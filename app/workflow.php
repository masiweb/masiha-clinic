<?php
declare(strict_types=1);

/** Count completed session rows, never result-event revisions or displayed page rows. */
function episodeCompletion(int $id):array{
 $ep=q('SELECT id,pid,planned_sessions FROM physio_episodes WHERE id=?',[$id])->fetch();
 if(!$ep||!user()||!patientAllowed($ep['pid'])||(!allowed('appointments')&&!can('episodes')))throw new DomainException('به این دوره دسترسی ندارید.');
 $done=(int)q("SELECT COUNT(*) FROM physio_sessions WHERE episode_id=? AND status='done'",[$id])->fetchColumn();
 return ['done'=>$done,'planned'=>(int)$ep['planned_sessions'],'remaining'=>max(0,(int)$ep['planned_sessions']-$done),'tenth_reached'=>$done>=10];
}

function episodeCompletionNotice(int $id):void{
 $progress=episodeCompletion($id);
 if($progress['tenth_reached'])echo '<p class="notice" role="status">جلسه دهم انجام‌شدهٔ این دوره ثبت شده است؛ '.fa($progress['done']).' جلسه تکمیل شده. ارزیابی پیشرفت بیمار را بررسی کنید.</p>';
 elseif($progress['done']===9)echo '<p class="notice" role="status">۹ جلسه این دوره انجام شده است؛ جلسه انجام‌شدهٔ بعدی، جلسه دهم خواهد بود.</p>';
}

function workflowLabels():array{return ['scheduled'=>'نوبت ثبت‌شده','referred'=>'ارجاعی','waiting'=>'منتظر','in_service'=>'در حال ویزیت','visited'=>'ویزیت‌شده','discharged'=>'ترخیصی','absent'=>'غایب','cancelled'=>'کنسلی'];}


function workflowNextStates():array{return [
 'scheduled'=>['referred','waiting','visited','absent','cancelled'],
 'referred'=>['waiting','in_service','visited','cancelled','absent'],
 'waiting'=>['referred','in_service','visited','cancelled'],
 'in_service'=>['visited'], 'visited'=>['discharged'],
 'discharged'=>[], 'absent'=>[], 'cancelled'=>[]
];}

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
 q('INSERT INTO visit_events(session_id,version,event_type,to_state,actor_id,source,details) VALUES(?,0,?,?,?,?,?)',[$s['id'],$source==='legacy'?'legacy_snapshot':'appointment_created',$state,$actor,$source,json_encode(['timestamps_known'=>false,'actor_name'=>$source==='legacy'?'سیستم / سابقه قبلی':($source==='patient'?'بیمار':(user()['name']??'سیستم')),'appointment'=>array_intersect_key($s,array_flip(['starts_at','ends_at','therapist_id','room','clinic_id','service_id']))],JSON_THROW_ON_ERROR)]);
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
  $course=q('SELECT episode_id FROM physio_sessions WHERE id=?',[$id])->fetchColumn();
  if($course)q('SELECT id FROM physio_episodes WHERE id=? FOR UPDATE',[$course]);
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
  $next=workflowNextStates();
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
  billingSyncVisit((int)$s['episode_id'],$id);
  $stamp=$from===$target?null:match($target){'waiting'=>'arrived_at','referred'=>'called_at','in_service'=>'treatment_started_at','visited'=>'treatment_finished_at','discharged'=>'departed_at',default=>null};
  $extra=$stamp?",$stamp=COALESCE($stamp,NOW())":'';
  // Sending a patient directly into treatment is also an observed arrival.
  if($target==='in_service')$extra.=',arrived_at=COALESCE(arrived_at,NOW())';
  q("UPDATE visit_workflows SET state=?,version=version+1,updated_at=NOW()$extra WHERE session_id=?",[$target,$id]);
  $details['before_workflow']=workflowSnapshot($w);
  $details['after_workflow']=workflowSnapshot(q('SELECT * FROM visit_workflows WHERE session_id=?',[$id])->fetch());
  $details['actor_name']=$patient?'بیمار':user()['name'];
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
 $events=q('SELECT v.id,v.version,v.event_type,v.from_state,v.to_state,v.actor_id,v.source,v.occurred_at,v.details,u.name current_actor_name FROM visit_events v LEFT JOIN staff u ON u.id=v.actor_id WHERE v.session_id=? ORDER BY v.version,v.id',[$id])->fetchAll();
 // Clinical notes are visible only under the existing visit-form scope.
 $clinical=scope('forms.visit')==='all'||(scope('forms.visit')==='own'&&((int)$s['created_by']===(int)user()['id']||(int)$s['therapist_id']===(int)user()['id']));
 foreach($events as &$event){$event['details']=json_decode($event['details'],true);$event['actor_name']=$event['details']['actor_name']??($event['source']==='patient'?'بیمار':($event['current_actor_name']??'سیستم / سابقه قبلی'));unset($event['current_actor_name']);if(!$clinical){unset($event['details']['before_result'],$event['details']['after_result'],$event['details']['before_diagnoses'],$event['details']['after_diagnoses']);}}unset($event);
 $w=q('SELECT * FROM visit_workflows WHERE session_id=?',[$id])->fetch()?:['session_id'=>$id,'state'=>workflowInitialState($s),'version'=>0];
 $wait=isset($w['arrived_at'],$w['treatment_started_at'])?max(0,strtotime($w['treatment_started_at'])-strtotime($w['arrived_at'])):null;
 $treatment=isset($w['treatment_started_at'],$w['treatment_finished_at'])?max(0,strtotime($w['treatment_finished_at'])-strtotime($w['treatment_started_at'])):null;
 return ['workflow'=>$w,'events'=>$events,'waiting_seconds'=>$wait,'treatment_seconds'=>$treatment];
}

/** Whitelist: never copy clinical values into administrative state snapshots. */
function workflowSnapshot(array $w):array{
 return array_intersect_key($w,array_flip(['state','arrived_at','called_at','treatment_started_at','treatment_finished_at','departed_at']));
}

/** Correct only the latest administrative transition; keep its original event intact. */
function workflowReverse(int $id,int $expected,string $reason):array{
 global $db;
 if(!allowed('appointments.reverse'))throw new DomainException('اجازه اصلاح مرحله مراجعه را ندارید.');
 $reason=trim($reason);
 if($reason===''||mb_strlen($reason)>1000)throw new DomainException('دلیل اصلاح مرحله را وارد کنید (حداکثر ۱۰۰۰ نویسه).');
 $owns=!$db->inTransaction();if($owns)$db->beginTransaction();
 try{
  $s=q('SELECT * FROM physio_sessions WHERE id=? FOR UPDATE',[$id])->fetch();
  if(!$s)throw new DomainException('نوبت پیدا نشد.');
  workflowAccess($s);
  $w=q('SELECT * FROM visit_workflows WHERE session_id=? FOR UPDATE',[$id])->fetch();
  if(!$w||$expected!==(int)$w['version'])throw new DomainException('وضعیت نوبت تغییر کرده است؛ صفحه را تازه کنید.');
  $event=q('SELECT * FROM visit_events WHERE session_id=? AND version=?',[$id,$expected])->fetch();
  $details=$event?json_decode($event['details'],true):[];
  $before=$details['before_workflow']??null;
  $states=['scheduled','waiting','referred','in_service'];
  if(!$event||$event['event_type']!=='state_changed'||!is_array($before)||!in_array($w['state'],$states,true)||!in_array($before['state']??'',$states,true)||$w['treatment_finished_at']!==null)
   throw new DomainException('فقط آخرین تغییر مرحله پیش از ثبت نتیجه قابل اصلاح است؛ سابقه درمان حذف نمی‌شود.');
  if(array_keys(workflowSnapshot($w))!==array_keys($before))throw new DomainException('سابقه کامل این تغییر موجود نیست.');
  $turn=match($before['state']){'waiting'=>'waiting','referred'=>'called','in_service'=>'in_service',default=>'none'};
  q("UPDATE physio_sessions SET status='scheduled',turn_state=?,turn_updated_at=NOW() WHERE id=?",[$turn,$id]);
  q('UPDATE visit_workflows SET state=?,arrived_at=?,called_at=?,treatment_started_at=?,treatment_finished_at=?,departed_at=?,version=version+1,updated_at=NOW() WHERE session_id=?',[...array_values($before),$id]);
  $new=q('SELECT * FROM visit_workflows WHERE session_id=?',[$id])->fetch();
  $audit=['reason'=>$reason,'reverses_event_id'=>(int)$event['id'],'before_workflow'=>workflowSnapshot($w),'after_workflow'=>workflowSnapshot($new),'actor_name'=>user()['name']];
  q("INSERT INTO visit_events(session_id,version,event_type,from_state,to_state,actor_id,source,details) VALUES(?,?,'stage_corrected',?,?,?,'staff',?)",[$id,$expected+1,$w['state'],$new['state'],user()['id'],json_encode($audit,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
  audit('visit_stage_corrected',$id);
  if($owns)$db->commit();return $new;
 }catch(Throwable $ex){if($owns&&$db->inTransaction())$db->rollBack();throw $ex;}
}
