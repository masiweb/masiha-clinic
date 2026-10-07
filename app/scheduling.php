<?php
declare(strict_types=1);

function schedulingTransaction(callable $callback){
 global $db;schedulingLock();$owns=!$db->inTransaction();
 try{if($owns)$db->beginTransaction();$result=$callback();if($owns)$db->commit();return $result;}
 catch(Throwable $ex){if($owns&&$db->inTransaction())$db->rollBack();throw $ex;}
 finally{schedulingUnlock();}
}
function scheduleTherapist(int $id):void{
 if(!allowed('appointments')||(user()['role']==='therapist'&&(int)user()['id']!==$id))throw new DomainException('اجازه ویرایش برنامه این درمانگر را ندارید.');
 if(!q("SELECT id FROM staff WHERE id=? AND active=1 AND deleted=0 AND role IN ('admin','therapist')",[$id])->fetchColumn())throw new DomainException('درمانگر فعال انتخاب کنید.');
}
function saveTherapistSchedule(array $v,int $id=0):int{
 return schedulingTransaction(function()use($v,$id){
  global $db;scheduleTherapist($v['therapist_id']);
  if($v['weekday']<0||$v['weekday']>6||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:00$/D',$v['start_time'])||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:00$/D',$v['end_time'])||$v['end_time']<=$v['start_time'])throw new DomainException('بازه برنامه حضور معتبر نیست.');
  foreach(['effective_from','effective_to'] as $key)if($v[$key]!==null){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v[$key]);if(!$d||$d->format('Y-m-d')!==$v[$key])throw new DomainException('تاریخ معتبر نیست.');}
  if($v['effective_from']&&$v['effective_to']&&$v['effective_to']<$v['effective_from'])throw new DomainException('بازه تاریخ معتبر نیست.');
  if($v['clinic_id']&&!q('SELECT id FROM clinics WHERE id=? AND active=1',[$v['clinic_id']])->fetchColumn())throw new DomainException('کلینیک فعال انتخاب کنید.');
  if($id){$old=q('SELECT * FROM therapist_schedules WHERE id=? FOR UPDATE',[$id])->fetch();if(!$old)throw new DomainException('برنامه پیدا نشد.');scheduleTherapist((int)$old['therapist_id']);}
  if(q("SELECT id FROM therapist_schedules WHERE id<>? AND therapist_id=? AND weekday=? AND active=1 AND start_time<? AND end_time>? AND COALESCE(effective_to,'9999-12-31')>=? AND COALESCE(effective_from,'1000-01-01')<=? LIMIT 1",[$id,$v['therapist_id'],$v['weekday'],$v['end_time'],$v['start_time'],$v['effective_from']??'1000-01-01',$v['effective_to']??'9999-12-31'])->fetchColumn())throw new DomainException('برنامه با بازه حضور دیگری هم‌پوشانی دارد.');
  $a=[$v['therapist_id'],$v['clinic_id']?:null,$v['weekday'],$v['start_time'],$v['end_time'],$v['effective_from'],$v['effective_to'],user()['id']];
  if($id)q('UPDATE therapist_schedules SET therapist_id=?,clinic_id=?,weekday=?,start_time=?,end_time=?,effective_from=?,effective_to=?,created_by=?,active=1 WHERE id=?',[...$a,$id]);
  else {q('INSERT INTO therapist_schedules(therapist_id,clinic_id,weekday,start_time,end_time,effective_from,effective_to,created_by) VALUES(?,?,?,?,?,?,?,?)',$a);$id=(int)$db->lastInsertId();}
  audit('work_schedule_save',$id);return $id;
 });
}
function disableScheduleRecord(string $kind,int $id):void{
 $table=match($kind){'schedule'=>'therapist_schedules','absence'=>'therapist_absences',default=>throw new DomainException('نوع معتبر نیست.')};
 schedulingTransaction(function()use($table,$id,$kind){$r=q("SELECT * FROM $table WHERE id=? FOR UPDATE",[$id])->fetch();if(!$r)throw new DomainException('بازه پیدا نشد.');scheduleTherapist((int)$r['therapist_id']);q("UPDATE $table SET active=0 WHERE id=?",[$id]);audit($kind.'_disabled',$id);});
}
function saveTherapistAbsence(int $tid,string $start,string $end,string $reason):int{
 return schedulingTransaction(function()use($tid,$start,$end,$reason){
  global $db;scheduleTherapist($tid);
  foreach([$start,$end] as $value){$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);if(!$d||$d->format('Y-m-d H:i:s')!==$value)throw new DomainException('تاریخ غیبت معتبر نیست.');}
  if($end<=$start||mb_strlen($reason)>500)throw new DomainException('بازه یا شرح غیبت معتبر نیست.');
  if(q('SELECT id FROM therapist_absences WHERE therapist_id=? AND active=1 AND starts_at<? AND ends_at>? LIMIT 1',[$tid,$end,$start])->fetchColumn())throw new DomainException('این بازه با غیبت قبلی هم‌پوشانی دارد.');
  if(q("SELECT id FROM physio_sessions WHERE therapist_id=? AND status='scheduled' AND starts_at<? AND ends_at>? LIMIT 1",[$tid,$end,$start])->fetchColumn())throw new DomainException('این بازه نوبت ثبت‌شده دارد؛ ابتدا نوبت‌ها را تعیین تکلیف کنید.');
  q('INSERT INTO therapist_absences(therapist_id,starts_at,ends_at,reason,active,created_by) VALUES(?,?,?,?,1,?)',[$tid,$start,$end,$reason,user()['id']]);$id=(int)$db->lastInsertId();
  q('UPDATE physio_slots SET enabled=0 WHERE therapist_id=? AND enabled=1 AND starts_at<? AND ends_at>?',[$tid,$end,$start]);audit('absence_save',$id);return $id;
 });
}
function copyTherapistSchedule(int $source,int $target):int{
 return schedulingTransaction(function()use($source,$target){
  scheduleTherapist($source);scheduleTherapist($target);if($source===$target)throw new DomainException('مبدا و مقصد یکسان است.');
  $rows=q('SELECT * FROM therapist_schedules WHERE therapist_id=? AND active=1 ORDER BY id',[$source])->fetchAll();if(!$rows)throw new DomainException('برنامه فعال در مبدا نیست.');$count=0;
  foreach($rows as $row){
   if(q('SELECT id FROM therapist_schedules WHERE therapist_id=? AND weekday=? AND start_time=? AND end_time=? AND clinic_id<=>? AND effective_from<=>? AND effective_to<=>? AND active=1',[$target,$row['weekday'],$row['start_time'],$row['end_time'],$row['clinic_id'],$row['effective_from'],$row['effective_to']])->fetchColumn())continue;
   $row['therapist_id']=$target;saveTherapistSchedule($row);$count++;
  }audit('work_schedule_copy',$target);return $count;
 });
}
function appointmentSlotSuggestions(int $pid,int $tid,int $clinic,string $from,int $minutes,string $room='',int $days=14,int $limit=30):array{
 if(!allowed('appointments')||!patientAllowed($pid)||!q('SELECT pid FROM patients WHERE pid=? AND active=1',[$pid])->fetchColumn())throw new DomainException('به این پرونده دسترسی ندارید.');
 if(!q("SELECT id FROM staff WHERE id=? AND active=1 AND deleted=0 AND role IN ('admin','therapist')",[$tid])->fetchColumn()||!q('SELECT id FROM clinics WHERE id=? AND active=1',[$clinic])->fetchColumn())throw new DomainException('درمانگر و کلینیک فعال انتخاب کنید.');
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$from);if(!$date||$date->format('Y-m-d')!==$from||$minutes<5||$minutes>480||$days<1||$days>31||$limit<1||$limit>1000)throw new DomainException('بازه جستجو معتبر نیست.');
 if($room!=='')validateResources($room,'');
 $results=[];$seen=[];
 for($day=0;$day<$days;$day++){
  $on=$date->modify('+'.$day.' days')->format('Y-m-d');
  $windows=[];
  foreach(q('SELECT start_time,end_time FROM therapist_schedules WHERE therapist_id=? AND active=1 AND weekday=? AND (clinic_id IS NULL OR clinic_id=?) AND (effective_from IS NULL OR effective_from<=?) AND (effective_to IS NULL OR effective_to>=?) ORDER BY start_time',[$tid,clinicWeekday($on),$clinic,$on,$on])->fetchAll() as $r)$windows[]=['start'=>$on.' '.$r['start_time'],'end'=>$on.' '.$r['end_time'],'room'=>$room,'equipment'=>''];
  foreach(q('SELECT starts_at,ends_at,room,equipment FROM physio_slots WHERE therapist_id=? AND enabled=1 AND starts_at>=? AND starts_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY starts_at',[$tid,$on,$on])->fetchAll() as $r)if($room===''||$room===$r['room'])$windows[]=['start'=>$r['starts_at'],'end'=>$r['ends_at'],'room'=>$r['room'],'equipment'=>$r['equipment']];
  $candidates=[];
  foreach($windows as $w)for($at=strtotime($w['start']);$at+$minutes*60<=strtotime($w['end']);$at+=15*60){
   if($at<=time())continue;$start=date('Y-m-d H:i:s',$at);$end=date('Y-m-d H:i:s',$at+$minutes*60);$key=$start.'|'.$w['room'].'|'.$w['equipment'];if(isset($seen[$key]))continue;$seen[$key]=true;
   if(therapistAvailabilityIssue($start,$end,$tid,$clinic)||conflict($start,$end,$tid,$w['room'],$w['equipment'],$pid))continue;
   if($w['room']!==''&&!q("SELECT id FROM resources WHERE name=? AND kind='room' AND active=1",[$w['room']])->fetchColumn())continue;
   if($w['equipment']!==''&&!q("SELECT id FROM resources WHERE name=? AND kind='equipment' AND active=1",[$w['equipment']])->fetchColumn())continue;
   $candidates[]=['starts_at'=>$start,'ends_at'=>$end,'room'=>$w['room'],'equipment'=>$w['equipment']];
  }
  usort($candidates,static fn($a,$b)=>strcmp($a['starts_at'],$b['starts_at']));
  foreach($candidates as $candidate){$results[]=$candidate;if(count($results)>=$limit)return $results;}
 }
 return $results;
}

function nextAppointmentDefaults(int $previous,int $pid):array{
 if(!allowed('appointments')||!patientAllowed($pid)||!q('SELECT pid FROM patients WHERE pid=? AND active=1',[$pid])->fetchColumn())throw new DomainException('به این پرونده دسترسی ندارید.');
 $s=q('SELECT s.* FROM physio_sessions s JOIN physio_episodes e ON e.id=s.episode_id WHERE s.id=? AND e.pid=?',[$previous,$pid])->fetch();
 if(!$s)throw new DomainException('نوبت قبلی این بیمار پیدا نشد.');
 $episode=q('SELECT status,planned_sessions FROM physio_episodes WHERE id=?',[$s['episode_id']])->fetch();$booked=(int)q("SELECT COUNT(*) FROM physio_sessions WHERE episode_id=? AND status IN ('scheduled','done')",[$s['episode_id']])->fetchColumn();$episodeId=$episode&&$episode['status']==='active'&&$booked<(int)$episode['planned_sessions']?(int)$s['episode_id']:0;
 $day=max(date('Y-m-d'),date('Y-m-d',strtotime(substr($s['starts_at'],0,10).' +1 day')));
 return ['therapist_id'=>(int)$s['therapist_id'],'clinic_id'=>(int)$s['clinic_id'],'service_id'=>(int)$s['service_id'],'episode_id'=>$episodeId,'visit_type_id'=>(int)$s['visit_type_id'],'room'=>$s['room'],'equipment'=>$s['equipment'],'duration'=>max(5,min(480,(int)((strtotime($s['ends_at'])-strtotime($s['starts_at']))/60))),'date'=>$day];
}

function copyTherapistScheduleDays(int $tid,int $source,array $days):int{
 if($source<0||$source>6||count($days)>7)throw new DomainException('روزهای هفته معتبر نیست.');
 $targets=[];foreach($days as $day){if(!is_scalar($day))throw new DomainException('روز مقصد معتبر نیست.');$n=filter_var(MasihaOtp::digits((string)$day),FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>6]]);if($n===false)throw new DomainException('روز مقصد معتبر نیست.');if($n!==$source)$targets[$n]=$n;}
 if(!$targets)throw new DomainException('حداقل یک روز مقصد متفاوت از روز مبدا انتخاب کنید.');
 return schedulingTransaction(function()use($tid,$source,$targets){
  scheduleTherapist($tid);$rows=q('SELECT * FROM therapist_schedules WHERE therapist_id=? AND weekday=? AND active=1 ORDER BY start_time,id',[$tid,$source])->fetchAll();
  if(!$rows)throw new DomainException('برای روز مبدا برنامه حضور فعالی ثبت نشده است.');$count=0;
  foreach($targets as $day)foreach($rows as $row){
   if(q('SELECT id FROM therapist_schedules WHERE therapist_id=? AND weekday=? AND start_time=? AND end_time=? AND clinic_id<=>? AND effective_from<=>? AND effective_to<=>? AND active=1',[$tid,$day,$row['start_time'],$row['end_time'],$row['clinic_id'],$row['effective_from'],$row['effective_to']])->fetchColumn())continue;
   $row['weekday']=$day;saveTherapistSchedule($row);$count++;
  }audit('work_schedule_days_copied',$tid);return $count;
 });
}
