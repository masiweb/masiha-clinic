<?php
declare(strict_types=1);

function historyAccess(int $pid,bool $edit=false):void{
 if(!user()||!patientAllowed($pid)||($edit?!allowed('appointments.history'):(!allowed('appointments')&&!can('episodes'))))throw new DomainException('اجازه بررسی سوابق این بیمار را ندارید.');
}
function historyCodeSql(string $column):string{
 $sql="TRIM($column)";
 foreach(['۰۱۲۳۴۵۶۷۸۹','٠١٢٣٤٥٦٧٨٩'] as $digits)for($i=0;$i<10;$i++)$sql="REPLACE($sql,'".mb_substr($digits,$i,1)."','$i')";
 return $sql;
}
/** Evidence contains counting/identity fields only, never notes, diagnoses or financial amounts. */
function historyGroups(int $pid):array{
 historyAccess($pid);
 $rows=q('SELECT e.appointment_code,e.event_date,e.time_text,e.status_code,e.therapist_id,r.account_key FROM import_patient_events e JOIN import_records r ON r.id=e.record_id WHERE r.pid=? ORDER BY e.id',[$pid])->fetchAll();
 $groups=[];$uncoded=0;
 foreach($rows as $row){
  $code=trim(MasihaOtp::digits($row['appointment_code']));
  if($code===''){if($row['status_code']==='done')$uncoded++;continue;}
  $key=hash('sha256',json_encode([$row['account_key'],$code],JSON_THROW_ON_ERROR));
  if(!isset($groups[$key]))$groups[$key]=['source_hash'=>$key,'account'=>$row['account_key'],'code'=>$code,'rows'=>0,'evidence'=>[],'statuses'=>[],'dates'=>[]];
  $g=&$groups[$key];$g['rows']++;$g['statuses'][$row['status_code']]=true;if($row['event_date'])$g['dates'][$row['event_date']]=true;
  $g['evidence'][]=json_encode([$pid,$row['event_date'],trim(MasihaOtp::digits($row['time_text'])),$row['status_code'],(int)$row['therapist_id']],JSON_THROW_ON_ERROR);unset($g);
 }
 $links=[];foreach(q('SELECT * FROM visit_history_links WHERE pid=?',[$pid])->fetchAll() as $link)$links[$link['source_hash']]=$link;
 foreach($groups as $key=>&$g){
  $evidence=array_values(array_unique($g['evidence']));sort($evidence,SORT_STRING);$g['evidence_hash']=hash('sha256',json_encode($evidence,JSON_THROW_ON_ERROR));unset($g['evidence']);
  $g['done']=isset($g['statuses']['done']);$g['consistent']=$g['done']&&count($g['statuses'])===1&&count($g['dates'])===1;
  $g['identity_conflict']=(bool)q('SELECT 1 FROM import_patient_events e JOIN import_records r ON r.id=e.record_id WHERE r.account_key=? AND '.historyCodeSql('e.appointment_code').'=? AND (r.pid IS NULL OR r.pid<>?) LIMIT 1',[$g['account'],$g['code'],$pid])->fetchColumn();
  $g['link']=$links[$key]??null;
  // Read by source hash as well so reassigned import records cannot silently take over a review.
  if(!$g['link'])$g['link']=q('SELECT * FROM visit_history_links WHERE source_hash=?',[$key])->fetch()?:null;
  $g['review_valid']=$g['link']&&(int)$g['link']['pid']===$pid&&hash_equals($g['evidence_hash'],$g['link']['evidence_hash'])&&!$g['identity_conflict'];
  if($g['review_valid']&&$g['link']['decision']==='native'){
   $native=q('SELECT s.status,ep.pid FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id WHERE s.id=?',[$g['link']['session_id']])->fetch();
   $g['review_valid']=$native&&(int)$native['pid']===$pid&&$native['status']==='done'&&$g['consistent'];
  }
 }unset($g);
 return ['groups'=>$groups,'uncoded_done'=>$uncoded];
}
function patientCompletedHistory(int $pid):array{
 $data=historyGroups($pid);$native=(int)q("SELECT COUNT(*) FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id WHERE ep.pid=? AND s.status='done'",[$pid])->fetchColumn();
 $historical=0;$mapped=0;$pending=$data['uncoded_done'];$duplicates=0;
 foreach($data['groups'] as $g){if(!$g['done'])continue;$duplicates+=max(0,$g['rows']-1);
  if(!$g['review_valid']){$pending++;continue;}
  if($g['link']['decision']==='historical'&&$g['consistent'])$historical++;
  elseif($g['link']['decision']==='native')$mapped++;
  elseif($g['link']['decision']!=='exclude')$pending++;
 }
 return ['native'=>$native,'historical'=>$historical,'mapped'=>$mapped,'pending'=>$pending,'duplicate_rows'=>$duplicates,'verified_total'=>$native+$historical,'complete'=>$pending===0,'groups'=>$data['groups'],'uncoded_done'=>$data['uncoded_done']];
}
/** Explicit review only; a same-day match is never sufficient to merge visits automatically. */
function reviewCompletedHistory(int $pid,string $key,string $evidence,int $version,string $decision,int $sessionId,string $reason):void{
 global $db;historyAccess($pid,true);$reason=trim($reason);
 if(!preg_match('/^[a-f0-9]{64}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$evidence)||!in_array($decision,['historical','native','exclude'],true)||$reason===''||mb_strlen($reason)>1000)throw new DomainException('تصمیم و دلیل معتبر وارد کنید.');
 $lock='masiha-history-review';if((int)q('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)throw new DomainException('بررسی دیگری در حال ثبت است؛ دوباره تلاش کنید.');
 try{
  $db->beginTransaction();
  $old=q('SELECT * FROM visit_history_links WHERE source_hash=? FOR UPDATE',[$key])->fetch();
  if(($old&&(int)$old['pid']!==$pid)||($old?(int)$old['version']:0)!==$version)throw new DomainException('سابقه بررسی تغییر کرده است؛ صفحه را تازه کنید.');
  $data=historyGroups($pid);$g=$data['groups'][$key]??null;
  if(!$g||!hash_equals($g['evidence_hash'],$evidence)||$g['identity_conflict'])throw new DomainException('هویت یا اطلاعات منبع تغییر کرده است؛ ابتدا اتصال پرونده را بررسی و صفحه را تازه کنید.');
  if(!$g['done']||($decision!=='exclude'&&!$g['consistent']))throw new DomainException('وضعیت یا تاریخ منبع ناسازگار است؛ این سابقه قابل شمارش نیست.');
  if($decision==='native'){
   $s=q('SELECT s.status,ep.pid FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id WHERE s.id=? FOR UPDATE',[$sessionId])->fetch();
   if(!$s||(int)$s['pid']!==$pid||$s['status']!=='done')throw new DomainException('جلسه انجام‌شده همین بیمار را انتخاب کنید.');
   if(q('SELECT source_hash FROM visit_history_links WHERE session_id=? AND source_hash<>?',[$sessionId,$key])->fetchColumn())throw new DomainException('این جلسه قبلاً به سابقه دیگری متصل شده است؛ رکورد تکراری را با دلیل از شمارش خارج کنید.');
  }else $sessionId=0;
  $after=['decision'=>$decision,'session_id'=>$sessionId?:null,'evidence_hash'=>$evidence];
  q('INSERT INTO visit_history_links(source_hash,pid,decision,session_id,evidence_hash,version,reason,actor_id) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE decision=VALUES(decision),session_id=VALUES(session_id),evidence_hash=VALUES(evidence_hash),version=VALUES(version),reason=VALUES(reason),actor_id=VALUES(actor_id)',[$key,$pid,$decision,$sessionId?:null,$evidence,$version+1,$reason,user()['id']]);
  q('INSERT INTO visit_history_reviews(source_hash,pid,version,actor_id,reason,details) VALUES(?,?,?,?,?,?)',[$key,$pid,$version+1,user()['id'],$reason,json_encode(['before'=>$old?array_intersect_key($old,$after):null,'after'=>$after],JSON_THROW_ON_ERROR)]);
  audit('visit_history_reviewed',$pid);$db->commit();
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}finally{q('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function historyReviewView():void{
 need('patients');$pid=(int)($_GET['pid']??0);
 try{$data=patientCompletedHistory($pid);}catch(DomainException $ex){http_response_code(403);echo e($ex->getMessage());return;}
 layout('patients','تطبیق جلسات انجام‌شده','پرونده '.fa($pid));
 echo '<section class="panel form-panel"><h2>جمع تأییدشده: '.fa($data['verified_total']).'</h2><p>جلسات مسیحا: '.fa($data['native']).' · سوابق مستقل تأییدشده: '.fa($data['historical']).' · متصل به جلسه موجود: '.fa($data['mapped']).'</p><p>نیازمند بررسی: '.fa($data['pending']).' · ردیف تکراری منبع: '.fa($data['duplicate_rows']).'</p>';
 if(!$data['complete'])echo '<p class="notice">این عدد مجموع قطعی تمام سوابق نیست؛ موارد بررسی‌نشده در جمع وارد نشده‌اند.</p>';
 if($data['uncoded_done'])echo '<p class="notice">'.fa($data['uncoded_done']).' ردیف انجام‌شده بدون کد نوبت وجود دارد؛ برای شمارش قطعی باید شناسه منبع تکمیل شود.</p>';
 echo '<p class="hint">هر کد نوبت در هر حساب بقراط یک مراجعه محسوب می‌شود. تاریخ یکسان به‌تنهایی اثبات تکراری بودن نیست. این بررسی فقط شمارش را تغییر می‌دهد و اطلاعات درمان یا مالی را بازنویسی نمی‌کند.</p></section>';
 $page=max(1,(int)($_GET['p']??1));$groups=array_values(array_filter($data['groups'],static fn($g)=>$g['done']));$pages=max(1,(int)ceil(count($groups)/20));$page=min($page,$pages);
 foreach(array_slice($groups,($page-1)*20,20) as $g){echo '<section class="panel form-panel"><h2>نوبت '.e($g['code']).'</h2><p>تاریخ: '.e(implode(' / ',array_keys($g['dates']))).' · ردیف‌های منبع: '.fa($g['rows']).'</p>';
  echo '<p>'.($g['identity_conflict']?'تعارض اتصال پرونده؛ ابتدا هویت بررسی شود.':($g['review_valid']?'بررسی معتبر ثبت شده':'نیازمند بررسی')).'</p>';
  if($g['link']&&(int)$g['link']['pid']===$pid)echo '<p>تصمیم: '.e(['historical'=>'جلسه مستقل قبلی','native'=>'همان جلسه مسیحا','exclude'=>'خارج از شمارش'][$g['link']['decision']]??'').' · دلیل: '.e($g['link']['reason']).'</p>';
  if(allowed('appointments.history')&&!$g['identity_conflict']){formstart('history_review');hidden('pid',$pid);hidden('source_hash',$g['source_hash']);hidden('evidence_hash',$g['evidence_hash']);hidden('version',$g['link']['version']??0);selectfield('decision','نتیجه تطبیق',[''=>'انتخاب کنید','historical'=>'جلسه مستقل از جلسات مسیحا','native'=>'همان جلسه ثبت‌شده در مسیحا','exclude'=>'خارج از شمارش با حفظ سابقه'],'','پس از بررسی هویت و اصل مراجعه انتخاب کنید.',true);field('session_id','شناسه جلسه مسیحا',0,'number','فقط برای اتصال به جلسه انجام‌شده همین بیمار.');field('reason','دلیل بررسی','','textarea','دلیل و تاریخچه تصمیم حفظ می‌شود.',true);formend('ثبت بررسی','/patient?id='.$pid);}
  echo '</section>';
 }
 echo '<div class="actions">';foreach([$page-1=>'قبل',$page+1=>'بعد'] as $n=>$label)if($n>=1&&$n<=$pages)echo '<a class="button secondary" href="/visit-history?pid='.$pid.'&amp;p='.$n.'">'.$label.'</a>';echo '</div>';endLayout();
}
