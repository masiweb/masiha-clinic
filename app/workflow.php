<?php
declare(strict_types=1);

function workflowLabels():array{return ['scheduled'=>'نوبت ثبت‌شده','referred'=>'ارجاعی','waiting'=>'منتظر','in_service'=>'در حال ویزیت','visited'=>'ویزیت‌شده','discharged'=>'ترخیصی','absent'=>'غایب','cancelled'=>'کنسلی'];}

function workflowInitialState(array $s):string{
 return match($s['status']){'cancelled'=>'cancelled','absent'=>'absent','done'=>'visited',default=>match($s['turn_state']??'none'){'waiting'=>'waiting','called'=>'referred','in_service'=>'in_service',default=>'scheduled'}};
}

// Must run in the booking/transition transaction. Events have no update/delete API.
function workflowEnsure(array $s,string $source='legacy'):void{
 global $db;
 if(!$db->inTransaction())throw new LogicException('workflow_requires_transaction');
 if(q('SELECT session_id FROM visit_workflows WHERE session_id=?',[$s['id']])->fetchColumn())return;
 $state=workflowInitialState($s);
 q('INSERT INTO visit_workflows(session_id,state) VALUES(?,?)',[$s['id'],$state]);
 $actor=$source==='legacy'?0:(int)($_SESSION['uid']??-($_SESSION['pid']??0));
 q('INSERT INTO visit_events(session_id,version,event_type,to_state,actor_id,source,details) VALUES(?,0,?,?,?,?,?)',[$s['id'],$source==='legacy'?'legacy_snapshot':'appointment_created',$state,$actor,$source,json_encode(['timestamps_known'=>false],JSON_THROW_ON_ERROR)]);
}

function workflowBooked(int $id,string $source='staff'):void{
 $s=q('SELECT * FROM physio_sessions WHERE id=? FOR UPDATE',[$id])->fetch();
 if(!$s)throw new DomainException('نوبت پیدا نشد.');
 workflowEnsure($s,$source);
}

function workflowAccess(array $s,bool $clinical=false,bool $patient=false):void{
 $pid=(int)q('SELECT pid FROM physio_episodes WHERE id=?',[$s['episode_id']])->fetchColumn();
 if($patient){
  if(user()||!isset($_SESSION['pid'])||(int)$_SESSION['pid']!==$pid||!q("SELECT pid FROM patients WHERE pid=? AND active=1 AND allow_patient_portal='YES'",[$pid])->fetchColumn())throw new DomainException('به این نوبت دسترسی ندارید.');
  return;
 }
 if(!user()||!patientAllowed($pid))throw new DomainException('به این پرونده دسترسی ندارید.');
 $assigned=(int)$s['therapist_id']===(int)user()['id'];
 if($clinical){
  if(!$assigned||!in_array(user()['role'],['therapist','admin'],true)||scope('forms.visit')==='none')throw new DomainException('نتیجه جلسه فقط توسط درمانگر همین نوبت ثبت می‌شود.');
 }elseif(!allowed('appointments')||(user()['role']==='therapist'&&!$assigned))throw new DomainException('اجازه تغییر این نوبت را ندارید.');
}

/** Atomic state, legacy status, timestamps and audit; optional version rejects stale forms. */
function workflowTransition(int $id,string $target,array $details=[],?int $expected=null,?array $result=null,bool $patient=false):array{
 global $db;
 $owns=!$db->inTransaction();if($owns)$db->beginTransaction();
 try{
  $s=q('SELECT * FROM physio_sessions WHERE id=? FOR UPDATE',[$id])->fetch();
  if(!$s)throw new DomainException('نوبت پیدا نشد.');
  workflowAccess($s,$result!==null,$patient);
  if($patient&&($target!=='cancelled'||!in_array($s['status'],['scheduled','cancelled'],true)||$s['starts_at']<=date('Y-m-d H:i:s')||!in_array($s['turn_state'],['none','done'],true)))throw new DomainException('این نوبت قابل لغو نیست.');
  if(!isset(workflowLabels()[$target]))throw new DomainException('وضعیت مراجعه معتبر نیست.');
  if($target==='visited'&&$result===null)throw new DomainException('برای پایان ویزیت، درمانگر باید نتیجه جلسه را ثبت کند.');
  if($result!==null&&$target!=='visited')throw new DomainException('نتیجه درمان فقط برای جلسه ویزیت‌شده ثبت می‌شود.');
  workflowEnsure($s);
  $w=q('SELECT * FROM visit_workflows WHERE session_id=? FOR UPDATE',[$id])->fetch();
  if($expected!==null&&$expected!==(int)$w['version'])throw new DomainException('وضعیت نوبت تغییر کرده است؛ صفحه را تازه کنید.');
  if($target==='absent'&&$w['arrived_at']!==null)throw new DomainException('برای مراجعه‌کننده حاضر، غیبت ثبت نمی‌شود.');
  $from=$w['state'];
  // A clinical correction after checkout must not reopen a completed visit.
  if($result!==null&&$from==='discharged')$target='discharged';
  $next=[
   'scheduled'=>['referred','waiting','visited','absent','cancelled'],
   'referred'=>['waiting','in_service','visited','cancelled','absent'],
   'waiting'=>['referred','in_service','visited','cancelled'],
   'in_service'=>['visited'], 'visited'=>['discharged'],
   'discharged'=>[], 'absent'=>[], 'cancelled'=>[]
  ];
  if($from===$target&&$result===null){if($owns)$db->commit();return $w;}
  if($from!==$target&&!in_array($target,$next[$from]??[],true))throw new DomainException('این تغییر با مرحله فعلی مراجعه سازگار نیست.');
  if($result!==null){
   foreach(['pain_before','pain_after'] as $key)if(($result[$key]??null)!==null&&(!is_int($result[$key])||$result[$key]<0||$result[$key]>10))throw new DomainException('شدت درد باید بین صفر و ده باشد.');
   if(mb_strlen((string)($result['rom']??''))>255||mb_strlen((string)($result['notes']??''))>10000)throw new DomainException('شرح جلسه بیش از حد مجاز است.');
   $details['before_result']=array_intersect_key($s,array_flip(['pain_before','pain_after','rom','notes']));
   $details['after_result']=$result;
   q('UPDATE physio_sessions SET pain_before=?,pain_after=?,rom=?,notes=? WHERE id=?',[$result['pain_before']??null,$result['pain_after']??null,$result['rom']??'',$result['notes']??'',$id]);
  }
  $legacy=match($target){'visited','discharged'=>'done','absent'=>'absent','cancelled'=>'cancelled',default=>'scheduled'};
  $turn=match($target){'referred'=>'called','waiting'=>'waiting','in_service'=>'in_service','visited','discharged','absent','cancelled'=>'done',default=>'none'};
  q('UPDATE physio_sessions SET status=?,turn_state=?,turn_updated_at=NOW() WHERE id=?',[$legacy,$turn,$id]);
  $stamp=$from===$target?null:match($target){'waiting'=>'arrived_at','referred'=>'called_at','in_service'=>'treatment_started_at','visited'=>'treatment_finished_at','discharged'=>'departed_at',default=>null};
  $extra=$stamp?",$stamp=COALESCE($stamp,NOW())":'';
  // Sending a patient directly into treatment is also an observed arrival.
  if($target==='in_service')$extra.=',arrived_at=COALESCE(arrived_at,NOW())';
  q("UPDATE visit_workflows SET state=?,version=version+1,updated_at=NOW()$extra WHERE session_id=?",[$target,$id]);
  $actor=(int)($_SESSION['uid']??-($_SESSION['pid']??0));
  q('INSERT INTO visit_events(session_id,version,event_type,from_state,to_state,actor_id,source,details) VALUES(?,?,?,?,?,?,?,?)',[$id,(int)$w['version']+1,$result!==null?'session_result':'state_changed',$from,$target,$actor,$patient?'patient':'staff',json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
  audit($result!==null?'session_result':'visit_state_changed',$id);
  $out=q('SELECT * FROM visit_workflows WHERE session_id=?',[$id])->fetch();
  if($owns)$db->commit();return $out;
 }catch(Throwable $ex){if($owns&&$db->inTransaction())$db->rollBack();throw $ex;}
}

function workflowTimeline(int $id):array{
 $s=q('SELECT s.*,e.pid FROM physio_sessions s JOIN physio_episodes e ON e.id=s.episode_id WHERE s.id=?',[$id])->fetch();
 if(!$s||!user()||!patientAllowed($s['pid'])||(!allowed('appointments')&&!can('episodes')))throw new DomainException('به این نوبت دسترسی ندارید.');
 $events=q('SELECT id,version,event_type,from_state,to_state,actor_id,source,occurred_at,details FROM visit_events WHERE session_id=? ORDER BY version,id',[$id])->fetchAll();
 // Clinical notes are visible only under the existing visit-form scope.
 $clinical=scope('forms.visit')==='all'||(scope('forms.visit')==='own'&&((int)$s['created_by']===(int)user()['id']||(int)$s['therapist_id']===(int)user()['id']));
 foreach($events as &$event){$event['details']=json_decode($event['details'],true);if(!$clinical){unset($event['details']['before_result'],$event['details']['after_result']);}}unset($event);
 $w=q('SELECT * FROM visit_workflows WHERE session_id=?',[$id])->fetch()?:['session_id'=>$id,'state'=>workflowInitialState($s),'version'=>0];
 $wait=isset($w['arrived_at'],$w['treatment_started_at'])?max(0,strtotime($w['treatment_started_at'])-strtotime($w['arrived_at'])):null;
 $treatment=isset($w['treatment_started_at'],$w['treatment_finished_at'])?max(0,strtotime($w['treatment_finished_at'])-strtotime($w['treatment_started_at'])):null;
 return ['workflow'=>$w,'events'=>$events,'waiting_seconds'=>$wait,'treatment_seconds'=>$treatment];
}
