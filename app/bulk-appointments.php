<?php
declare(strict_types=1);

/** Administrative appointment cancellation: dry-run first, explicit confirmation second. */
function bulkAppointmentCriteria(array $input):array{
 if((user()['role']??'')!=='admin'||!allowed('appointments'))throw new DomainException('لغو گروهی نوبت‌ها فقط برای مدیر مجاز است.');
 $from=(string)($input['from']??'');$to=(string)($input['to']??'');
 foreach([$from,$to] as $day){
  $d=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
  if(!$d||$d->format('Y-m-d')!==$day)throw new DomainException('تاریخ شروع یا پایان معتبر نیست.');
 }
 if($from>$to||strtotime($to)-strtotime($from)>31*86400)throw new DomainException('بازه باید حداکثر ۳۱ روز باشد.');
 $tid=filter_var($input['therapist_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
 $clinic=filter_var($input['clinic_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
 if($tid===false||$clinic===false)throw new DomainException('درمانگر یا کلینیک معتبر نیست.');
 if($tid&&!q("SELECT id FROM staff WHERE id=? AND active=1 AND deleted=0",[$tid])->fetchColumn())throw new DomainException('درمانگر پیدا نشد.');
 if($clinic&&!q('SELECT id FROM clinics WHERE id=? AND active=1',[$clinic])->fetchColumn())throw new DomainException('کلینیک پیدا نشد.');
 return ['from'=>$from,'to'=>$to,'therapist_id'=>(int)$tid,'clinic_id'=>(int)$clinic];
}
function bulkAppointmentCandidates(array $filter):array{
 $where=["s.starts_at>=?","s.starts_at<DATE_ADD(?,INTERVAL 1 DAY)","s.starts_at>NOW()","s.status='scheduled'","w.state IN ('scheduled','referred')","w.arrived_at IS NULL"];
 $params=[$filter['from'],$filter['to']];
 if($filter['therapist_id']){$where[]='s.therapist_id=?';$params[]=$filter['therapist_id'];}
 if($filter['clinic_id']){$where[]='s.clinic_id=?';$params[]=$filter['clinic_id'];}
 $rows=q("SELECT s.id,s.episode_id,s.therapist_id,s.clinic_id,s.starts_at,w.version,
  p.pid,p.fname,p.lname,p.phone_cell
  FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id
  JOIN patients p ON p.pid=ep.pid JOIN visit_workflows w ON w.session_id=s.id
  WHERE ".implode(' AND ',$where)." ORDER BY s.id LIMIT 501",$params)->fetchAll();
 if(count($rows)>500)throw new DomainException('بیش از ۵۰۰ نوبت انتخاب شده است؛ بازه یا پزشک را محدود کنید.');
 return $rows;
}
function bulkAppointmentMessage(array $filter):string{
 return '{first_name} {last_name} عزیز، به اطلاع می‌رساند تمامی نوبت‌های مربوط به {doctor_name} در {clinic_name} از تاریخ {start_date} تا تاریخ {end_date} لغو شده‌اند. برای دریافت نوبت جدید با کلینیک تماس بگیرید.';
}
function bulkAppointmentContext(array $filter,array $patient):array{
 $doctor=$filter['therapist_id']?(string)q('SELECT name FROM staff WHERE id=?',[$filter['therapist_id']])->fetchColumn():'تمامی درمانگران';
 $clinic=$filter['clinic_id']?(string)q('SELECT name FROM clinics WHERE id=?',[$filter['clinic_id']])->fetchColumn():setting('name','کلینیک مسیحا');
 return [
  'first_name'=>(string)$patient['fname'],
  'last_name'=>(string)$patient['lname'],
  'patient_name'=>trim($patient['fname'].' '.$patient['lname']),
  'date'=>jd($filter['from'],'yyyy/MM/dd'),
  'start_date'=>jd($filter['from'],'yyyy/MM/dd'),
  'end_date'=>jd($filter['to'],'yyyy/MM/dd'),
  'therapist'=>$doctor,'doctor_name'=>$doctor,
  'clinic'=>$clinic,'clinic_name'=>$clinic
 ];
}
function bulkAppointmentPreview(array $input):never{
 $f=bulkAppointmentCriteria($input);
 $rows=bulkAppointmentCandidates($f);
 if(!$rows)throw new DomainException('نوبت قابل لغوی در این بازه پیدا نشد.');
 $text=trim((string)($input['message']??''));
 if($text===''||mb_strlen($text)>1000)throw new DomainException('متن پیام باید بین ۱ تا ۱۰۰۰ نویسه باشد.');
 $send=isset($input['send_sms']);
 if($send&&(!allowed('sms')||!MasihaSms::enabled()))throw new DomainException('برای ارسال گروهی ابتدا سرویس پیامک را فعال کنید.');
 $fingerprint=array_map(static fn($r)=>[$r['id'],(int)$r['version']],$rows);
 $preview=bin2hex(random_bytes(16));
 $_SESSION['clinic_bulk_cancel']=[
  'id'=>$preview,'actor'=>(int)user()['id'],'created'=>time(),
  'filter'=>$f,'fingerprint'=>$fingerprint,'message'=>$text,'send_sms'=>$send
 ];
 flash('پیش‌نمایش آماده است؛ هیچ نوبتی لغو نشده و هیچ پیامکی ارسال نشده است.');
 go('/sms/appointment-notices');
}
function bulkAppointmentApply(array $input):never{
 global $db;
 if((user()['role']??'')!=='admin'||!allowed('appointments'))throw new DomainException('دسترسی لغو گروهی ندارید.');
 $p=$_SESSION['clinic_bulk_cancel']??null;
 if(!is_array($p)||($p['actor']??0)!==(int)user()['id']||time()-($p['created']??0)>600||!hash_equals((string)($p['id']??''),(string)($input['preview_key']??'')))throw new DomainException('پیش‌نمایش منقضی یا نامعتبر است.');
 if(!isset($input['confirm_cancel']))throw new DomainException('برای لغو نوبت‌ها تأیید صریح لازم است.');
 if(!empty($p['send_sms'])&&(!allowed('sms')||!MasihaSms::enabled()))throw new DomainException('امکان ارسال پیامک فعال نیست.');
 $f=bulkAppointmentCriteria($p['filter']);
 $rows=bulkAppointmentCandidates($f);
 $fingerprint=array_map(static fn($r)=>[$r['id'],(int)$r['version']],$rows);
 if($fingerprint!==$p['fingerprint'])throw new DomainException('فهرست یا وضعیت نوبت‌ها تغییر کرده است؛ پیش‌نمایش جدید بگیرید.');
 if(!$rows)throw new DomainException('نوبت قابل لغوی وجود ندارد.');
 $contacts=[];
 foreach($rows as $r){
  $phone=MasihaOtp::mobile((string)$r['phone_cell']);
  if($phone&&!isset($contacts[$phone]))$contacts[$phone]=$r;
 }
 $db->beginTransaction();
 try{
  foreach($rows as $r){
   workflowTransition((int)$r['id'],'cancelled',['reason'=>'لغو گروهی توسط مدیر','bulk_preview'=>$p['id']],(int)$r['version']);
   MasihaSms::cancelSession((int)$r['id']);
  }
  $queued=0;
  if($p['send_sms']){
   foreach($contacts as $phone=>$person){
    $ctx=bulkAppointmentContext($f,$person);
    $body=MasihaSms::render($p['message'],$ctx);
    if($body==='')continue;
    $dedupe=hash('sha256','bulk_cancel|'.$p['id'].'|'.$phone);
    q("INSERT IGNORE INTO sms_outbox(pid,session_id,event_key,mobile,message,status,scheduled_at,dedupe_key,created_by)
      VALUES(?,NULL,'bulk_appointment_cancelled',?,?,'queued',NOW(),?,?)",[(int)$person['pid'],$phone,$body,$dedupe,(int)user()['id']]);
    $queued+=(int)q('SELECT ROW_COUNT()')->fetchColumn();
   }
  }
  audit('bulk_appointment_cancelled',count($rows));
  $db->commit();
  unset($_SESSION['clinic_bulk_cancel']);
  flash(fa(count($rows)).' نوبت لغو شد؛ '.fa($queued).' پیامک در صف ارسال قرار گرفت.');
  go('/sms/appointment-notices');
 }catch(Throwable $ex){
  if($db->inTransaction())$db->rollBack();
  throw $ex;
 }
}
function bulkAppointmentNoticeView():void{
 need('sms');
 if((user()['role']??'')!=='admin'){http_response_code(403);echo 'تنها مدیر می‌تواند لغو گروهی نوبت‌ها را مدیریت کند.';return;}
 layout('sms','لغو گروهی نوبت‌ها و پیامک','انتخاب بازه، درمانگر یا کل کلینیک؛ بدون ارسال یا لغو پیش از تأیید نهایی.');
 $preview=$_SESSION['clinic_bulk_cancel']??null;
 if(is_array($preview)&&$preview['actor']===(int)user()['id']&&time()-$preview['created']<=600){
  $rows=bulkAppointmentCandidates($preview['filter']);
  $fingerprint=array_map(static fn($r)=>[$r['id'],(int)$r['version']],$rows);
  $valid=$fingerprint===$preview['fingerprint'];
  $patients=[];$sample=null;
  foreach($rows as $r){
   $mobile=MasihaOtp::mobile((string)$r['phone_cell']);
   if($mobile&&!isset($patients[$mobile]))$patients[$mobile]=$r;
  }
  if($patients)$sample=MasihaSms::render($preview['message'],bulkAppointmentContext($preview['filter'],reset($patients)));
  echo '<section class="panel form-panel"><h2>پیش‌نمایش لغو: '.fa(count($rows)).' نوبت</h2>';
  echo '<p>بیماران دارای شماره معتبر و یکتا: '.fa(count($patients)).'</p>';
  echo '<p>ارسال پیامک: '.(!empty($preview['send_sms'])?'پس از تأیید، در صف قرار می‌گیرد':'غیرفعال').'</p>';
  if($sample!==null)echo '<blockquote>'.nl2br(e($sample)).'</blockquote>';
  echo '<p class="hint">پیش‌نمایش هیچ نوبتی را تغییر نمی‌دهد. اعتبار پیش‌نمایش ۱۰ دقیقه است.</p>';
  if(!$valid)echo '<p class="notice">نوبت‌ها تغییر کرده‌اند؛ لازم است دوباره پیش‌نمایش بگیرید.</p>';
  else{
   formstart('sms_bulk_appointment_apply');hidden('preview_key',$preview['id']);
   check('confirm_cancel','تأیید می‌کنم این نوبت‌ها لغو شوند و در صورت فعال بودن، پیامک در صف ارسال قرار گیرد.',false);
   echo '<button class="button primary" type="submit" data-confirm="لغو گروهی بازگشت‌پذیر نیست. آیا مطمئن هستید؟">لغو قطعی '.fa(count($rows)).' نوبت</button></form>';
  }
  echo '</section>';
 }
 echo '<section class="panel form-panel"><h2>انتخاب نوبت‌های مشمول لغو</h2>';
 formstart('sms_bulk_appointment_preview');
 echo '<div class="form-grid">';
 field('from','تاریخ شروع',date('Y-m-d'),'date','شروع بازه',true);
 field('to','تاریخ پایان',date('Y-m-d'),'date','پایان بازه (حداکثر ۳۱ روز)',true);
 selectfield('therapist_id','پزشک / درمانگر',['0'=>'همه پزشکان و درمانگران']+array_filter(therapists()),0);
 selectfield('clinic_id','کلینیک',['0'=>'همه کلینیک‌ها']+options('clinics','active=1'),0);
 echo '</div>';
 field('message','متن پیامک با متغیرها',bulkAppointmentMessage([]),'textarea','متغیرها: {first_name} {last_name} {start_date} {end_date} {doctor_name} {clinic_name}',true,true);
 check('send_sms','پس از لغو قطعی، پیامک اطلاع‌رسانی در صف ارسال قرار گیرد.',false);
 echo '<p class="hint">تنها نوبت‌های آینده که حضور بیمار برایشان ثبت نشده و هنوز قابل لغو هستند انتخاب می‌شوند.</p>';
 formend('نمایش پیش‌نمایش نوبت‌ها','/sms/appointment-notices');
 echo '</section>';
 endLayout();
}
