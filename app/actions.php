<?php
function handlePost():never{
 global $db,$config;checkcsrf();$action=val('action',40,true);if(!in_array($action,['login','logout'])){if(!user())go('/login');guardLegacyAction($action);if(adminAction($action))exit;}
 if($action==='login'){
  $bucket=hash_hmac('sha256','login:'.($_SERVER['REMOTE_ADDR']??''),$config['secret']);$now=time();
  q('INSERT INTO login_limits VALUES(?,?,1) ON DUPLICATE KEY UPDATE attempts=IF(window_start<? ,1,attempts+1),window_start=IF(window_start<?,VALUES(window_start),window_start)',[$bucket,$now,$now-900,$now-900]);
  if((int)q('SELECT attempts FROM login_limits WHERE bucket=?',[$bucket])->fetchColumn()>12)throw new DomainException('تعداد تلاش‌های ورود زیاد است. ۱۵ دقیقه بعد دوباره تلاش کنید.');
  $u=q('SELECT * FROM staff WHERE username=? AND active=1',[val('username',80,true)])->fetch();
  if(!$u||!password_verify((string)($_POST['password']??''),$u['password_hash']))throw new DomainException('نام کاربری یا رمز ورود درست نیست.');
  session_regenerate_id(true);$_SESSION=['uid'=>(int)$u['id'],'csrf'=>bin2hex(random_bytes(32)),'last_seen'=>time()];q('DELETE FROM login_limits WHERE bucket=?',[$bucket]);audit('staff_login');go('/');
 }
 if($action==='logout'){$_SESSION=[];session_regenerate_id(true);go('/login');}
 if($action==='patient'){
  need('patients');$id=num('pid');$mobile=val('phone_cell',20);$mobile=$mobile!==''?(MasihaOtp::mobile($mobile)??throw new DomainException('شماره همراه معتبر وارد کنید.')):null;
  $national=MasihaOtp::digits(val('national_id',20));if($national!==''&&!preg_match('/^\d{10}$/D',$national))throw new DomainException('کد ملی باید ۱۰ رقم باشد.');
  $email=val('email',160);if($email&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new DomainException('ایمیل معتبر وارد کنید یا فیلد را خالی بگذارید.');
  $dob=datevalue('DOB');if($dob&&$dob>date('Y-m-d'))throw new DomainException('تاریخ تولد نمی‌تواند در آینده باشد.');
  $sourceDate=datevalue('source_registered_date');$clinicDate=datevalue('clinic_registered_date');
  $sex=val('sex',20);if(!in_array($sex,['','female','male','other'],true))throw new DomainException('جنسیت معتبر نیست.');
  $a=[val('fname',80,true),val('lname',100,true),$mobile,val('phone_home',30),$national?:null,val('father_name',160),$dob,$sex,val('marital_status',30),val('referral_source',160),$sourceDate,$clinicDate,$email,val('address',2000),val('emergency_name',160),val('emergency_phone',20),val('notes',5000),val('medical_conditions',10000),isset($_POST['allow_portal'])?'YES':'NO'];
  if($id){if(!q('SELECT pid FROM patients WHERE pid=?',[$id])->fetchColumn())throw new DomainException('پرونده پیدا نشد.');q('UPDATE patients SET fname=?,lname=?,phone_cell=?,phone_home=?,national_id=?,father_name=?,DOB=?,sex=?,marital_status=?,referral_source=?,source_registered_date=?,clinic_registered_date=?,email=?,address=?,emergency_name=?,emergency_phone=?,notes=?,medical_conditions=?,allow_patient_portal=? WHERE pid=?',[...$a,$id]);}
  else {q('INSERT INTO patients(fname,lname,phone_cell,phone_home,national_id,father_name,DOB,sex,marital_status,referral_source,source_registered_date,clinic_registered_date,email,address,emergency_name,emergency_phone,notes,medical_conditions,allow_patient_portal) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',$a);$id=(int)$db->lastInsertId();}
  if(!num('pid'))q('UPDATE patients SET created_by=? WHERE pid=?',[user()['id'],$id]);audit('patient_saved',$id);flash('اطلاعات بیمار ذخیره شد.');go(!patientAllowed($id)?'/patients':((($_POST['next']??'')==='booking')?'/appointment/new?pid='.$id:'/patient?id='.$id));
 }
 if($action==='archive_patient'){need('patients');$id=num('pid',1);q('UPDATE patients SET active=0 WHERE pid=?',[$id]);audit('patient_archived',$id);flash('پرونده بایگانی شد؛ سوابق آن محفوظ است.');go('/patients');}
 if($action==='episode'){
  need('episodes');$pid=num('pid',1);if(!q('SELECT pid FROM patients WHERE pid=? AND active=1',[$pid])->fetchColumn())throw new DomainException('بیمار فعال انتخاب کنید.');
  q('INSERT INTO physio_episodes(pid,diagnosis,body_region,referral,assessment,goals,precautions,exercises,planned_sessions,fee_toman,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$pid,val('diagnosis',255,true),val('body_region',100,true),val('referral',255),val('assessment',10000),val('goals',5000),val('precautions',5000),val('exercises',10000),num('planned_sessions',1,365),num('fee_toman'),user()['id']]);$id=(int)$db->lastInsertId();audit('episode_created',$id);flash('دوره درمان ایجاد شد.');go('/episode?id='.$id);
 }
 if($action==='episode_update'){
  need('episodes');$id=num('episode_id',1);episode($id);$state=val('status',20);if(!in_array($state,['active','completed','cancelled'],true))throw new DomainException('وضعیت معتبر نیست.');
  q('UPDATE physio_episodes SET assessment=?,goals=?,precautions=?,exercises=?,status=? WHERE id=?',[val('assessment',10000),val('goals',5000),val('precautions',5000),val('exercises',10000),$state,$id]);audit('episode_updated',$id);flash('برنامه درمان به‌روز شد.');go('/episode?id='.$id);
 }
 if($action==='schedule'){
  need('appointments');$ep=episode(num('episode_id',1));if($ep['status']!=='active')throw new DomainException('دوره درمان فعال نیست.');$tid=num('therapist_id',1);if(!q("SELECT id FROM staff WHERE id=? AND active=1 AND role IN ('admin','therapist')",[$tid])->fetchColumn())throw new DomainException('درمانگر فعال انتخاب کنید.');
  $date=datevalue('date',true);$time=val('time',5,true);if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time))throw new DomainException('ساعت معتبر وارد کنید.');$start=$date.' '.$time.':00';$end=date('Y-m-d H:i:s',strtotime($start)+num('duration',5,480)*60);
  $room=val('room',100,true);$equipment=val('equipment',100);
  validateResources($room,$equipment);schedulingLock();try{$db->beginTransaction();if(conflict($start,$end,$tid,$room,$equipment,$ep['pid']))throw new DomainException('این زمان با نوبت بیمار، درمانگر، اتاق یا تجهیزات تداخل دارد.');
   $count=(int)q("SELECT COUNT(*) FROM physio_sessions WHERE episode_id=? AND status IN ('scheduled','done')",[$ep['id']])->fetchColumn();if($count>=(int)$ep['planned_sessions'])throw new DomainException('ظرفیت جلسات این دوره تکمیل است.');
   q('INSERT INTO physio_sessions(episode_id,therapist_id,starts_at,ends_at,room,equipment,treatment,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?)',[$ep['id'],$tid,$start,$end,$room,$equipment,val('treatment',2000),'',user()['id']]);audit('appointment_created',(int)$db->lastInsertId());$db->commit();
  }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}finally{schedulingUnlock();}
  flash('نوبت با موفقیت ثبت شد.');go('/appointments?date='.$date);
 }
 if($action==='slot'){
  need('appointments');$tid=num('therapist_id',1);if(!q("SELECT id FROM staff WHERE active=1 AND id=? AND role IN ('admin','therapist')",[$tid])->fetchColumn())throw new DomainException('درمانگر معتبر انتخاب کنید.');$date=datevalue('date',true);$time=val('time',5,true);if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time))throw new DomainException('ساعت معتبر وارد کنید.');$start=$date.' '.$time.':00';$end=date('Y-m-d H:i:s',strtotime($start)+num('duration',5,480)*60);if($start<=date('Y-m-d H:i:s'))throw new DomainException('زمان آزاد باید در آینده باشد.');$room=val('room',100,true);$equipment=val('equipment',100);
  validateResources($room,$equipment);schedulingLock();try{
   if(conflict($start,$end,$tid,$room,$equipment,0)||q("SELECT id FROM physio_slots WHERE enabled=1 AND starts_at<? AND ends_at>? AND (therapist_id=? OR room=? OR (?<>'' AND equipment=?)) LIMIT 1",[$end,$start,$tid,$room,$equipment,$equipment])->fetchColumn())throw new DomainException('این بازه با نوبت یا زمان آزاد دیگری تداخل دارد.');
   q('INSERT INTO physio_slots(therapist_id,starts_at,ends_at,room,equipment,created_by) VALUES(?,?,?,?,?,?)',[$tid,$start,$end,$room,$equipment,user()['id']]);audit('slot_published',(int)$db->lastInsertId());
  }finally{schedulingUnlock();}flash('زمان آزاد برای بیماران منتشر شد.');go('/availability');
 }
 if($action==='disable_slot'){need('appointments');$id=num('id',1);q('UPDATE physio_slots SET enabled=0 WHERE id=?',[$id]);audit('slot_disabled',$id);flash('این زمان دیگر برای رزرو جدید نمایش داده نمی‌شود.');go('/availability');}
 if($action==='session'){
  need('episodes');$id=num('session_id',1);$s=q('SELECT * FROM physio_sessions WHERE id=?',[$id])->fetch();if(!$s)throw new DomainException('جلسه پیدا نشد.');if(user()['role']==='therapist'&&(int)$s['therapist_id']!==(int)user()['id'])throw new DomainException('فقط نتیجه جلسات خودتان را می‌توانید ثبت کنید.');
  $state=val('status',20);if(!in_array($state,['done','cancelled','absent'],true))throw new DomainException('وضعیت معتبر نیست.');$before=val('pain_before',2)===''?null:num('pain_before',0,10);$after=val('pain_after',2)===''?null:num('pain_after',0,10);
  schedulingLock();try{if($state==='done'){ $ep=episode($s['episode_id'],false);if(conflict($s['starts_at'],$s['ends_at'],$s['therapist_id'],$s['room'],$s['equipment'],$ep['pid'],$id))throw new DomainException('بازگرداندن این جلسه با نوبت دیگری تداخل دارد.');}q('UPDATE physio_sessions SET status=?,pain_before=?,pain_after=?,rom=?,notes=? WHERE id=?',[$state,$before,$after,val('rom',255),val('notes',10000),$id]);}finally{schedulingUnlock();}audit('session_result',$id);flash('نتیجه جلسه ذخیره شد.');go('/episode?id='.$s['episode_id']);
 }
 if($action==='payment'){
  need('finance');$id=num('episode_id',1);$amount=num('amount_toman',-999999999999);if(!$amount)throw new DomainException('مبلغ نمی‌تواند صفر باشد.');
  $nonce=val('payment_nonce',64,true);if(empty($_SESSION['payments'][$nonce]))throw new DomainException('این درخواست پرداخت قبلاً ثبت شده یا منقضی است.');
  $db->beginTransaction();try{$ep=q('SELECT id FROM physio_episodes WHERE id=? FOR UPDATE',[$id])->fetch();if(!$ep)throw new DomainException('دوره پیدا نشد.');if(totalpaid($id)+$amount<0)throw new DomainException('بازگشت وجه نمی‌تواند بیشتر از دریافتی باشد.');
   q('INSERT INTO physio_payments(episode_id,amount_toman,reference,created_by) VALUES(?,?,?,?)',[$id,$amount,val('reference',100),user()['id']]);audit('payment_recorded',(int)$db->lastInsertId());$db->commit();unset($_SESSION['payments'][$nonce]);
  }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}flash('تراکنش مالی ثبت شد.');go('/finance?episode='.$id);
 }
 if($action==='document'){need('patients');$pid=num('pid',1);if(!q('SELECT pid FROM patients WHERE pid=?',[$pid])->fetchColumn())throw new DomainException('بیمار پیدا نشد.');uploadDocument($pid);flash('مدرک به پرونده اضافه شد.');go('/patient?id='.$pid.'&tab=documents');}
 if($action==='settings'){
  need('settings');$name=val('name',100,true);$phone=val('phone',30);$address=val('address',1000);
  $f=$_FILES['logo']??null;if($f&&$f['error']!==UPLOAD_ERR_NO_FILE){if($f['error']!==UPLOAD_ERR_OK||$f['size']>2*1024*1024||!is_uploaded_file($f['tmp_name']))throw new DomainException('لوگو باید کمتر از ۲ مگابایت باشد.');$size=getimagesize($f['tmp_name']);if(!$size||!in_array($size[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true)||$size[0]>2000||$size[1]>2000)throw new DomainException('تصویر PNG یا JPG تا ابعاد ۲۰۰۰ پیکسل انتخاب کنید.');$im=imagecreatefromstring(file_get_contents($f['tmp_name']));if(!$im)throw new DomainException('تصویر خوانده نشد.');$tmp=$config['storage'].'/logo.tmp';imagealphablending($im,false);imagesavealpha($im,true);if(!imagepng($im,$tmp))throw new RuntimeException('logo');chmod($tmp,0600);rename($tmp,$config['storage'].'/logo.png');imagedestroy($im);}
  elseif(isset($_POST['remove_logo'])&&is_file($config['storage'].'/logo.png'))unlink($config['storage'].'/logo.png');
  $smsFile=$config['storage'].'/sms.json';$sms=is_file($smsFile)?json_decode(file_get_contents($smsFile),true):[];$key=val('sms_key',256);$template=val('sms_template',100);if($key){if(!preg_match('/^[A-Za-z0-9]{20,256}$/D',$key))throw new DomainException('کلید پیامک معتبر نیست.');$sms['key']=$key;}if(!preg_match('/^[A-Za-z0-9_-]*$/D',$template))throw new DomainException('نام الگوی پیامک باید انگلیسی باشد.');$sms['template']=$template;if(isset($_POST['otp'])&&(empty($sms['key'])||empty($sms['template'])))throw new DomainException('ابتدا کلید و الگوی پیامک را وارد کنید.');
  $tmp=$smsFile.'.tmp';if(file_put_contents($tmp,json_encode($sms))===false)throw new RuntimeException('sms');chmod($tmp,0600);rename($tmp,$smsFile);
  foreach(['name'=>$name,'phone'=>$phone,'address'=>$address,'help'=>isset($_POST['help'])?'1':'0','otp'=>isset($_POST['otp'])?'1':'0'] as $k=>$v)setsetting($k,$v);audit('settings_updated');flash('تنظیمات ذخیره شد.');go('/settings');
 }
 if($action==='staff'){
  need('staff');$id=num('id');$role=val('role',20);if(!in_array($role,['admin','reception','therapist','finance'],true))throw new DomainException('نقش معتبر نیست.');$name=val('name',160,true);$username=val('username',80,true);if(!preg_match('/^[a-zA-Z0-9._-]{3,80}$/D',$username))throw new DomainException('نام کاربری باید حداقل ۳ حرف انگلیسی یا عدد باشد.');$password=val('password',200);$active=isset($_POST['active'])?1:0;if($id==user()['id']&&(!$active||$role!=='admin'))throw new DomainException('نقش یا فعالیت حساب خودتان را از این فرم تغییر ندهید.');
  if(!$id||$password!==''){if(strlen($password)<12)throw new DomainException('رمز ورود باید حداقل ۱۲ نویسه باشد.');$hash=password_hash($password,PASSWORD_DEFAULT);}
  if($id){q('UPDATE staff SET name=?,username=?,role=?,active=? WHERE id=?',[$name,$username,$role,$active,$id]);if($password!=='')q('UPDATE staff SET password_hash=? WHERE id=?',[$hash,$id]);}else{q('INSERT INTO staff(name,username,password_hash,role,active) VALUES(?,?,?,?,?)',[$name,$username,$hash,$role,$active]);$id=(int)$db->lastInsertId();}if(user()['role']!=='admin'&&!q('SELECT staff_id FROM staff_permissions WHERE staff_id=?',[$id])->fetchColumn())q('INSERT INTO staff_permissions VALUES(?,?)',[$id,json_encode(defaultsFor(''))]);audit('staff_saved',$id);flash('اطلاعات همکار ذخیره شد.');go('/staff');
 }
 if($action==='resource'){need('resources');$kind=val('kind',20);if(!in_array($kind,['room','equipment'],true))throw new DomainException('نوع معتبر نیست.');q('INSERT INTO resources(name,kind) VALUES(?,?)',[val('name',100,true),$kind]);audit('resource_created',(int)$db->lastInsertId());flash('مورد جدید اضافه شد.');go('/resources');}
 if($action==='service'){need('resources');q('INSERT INTO services(name,price,duration) VALUES(?,?,?)',[val('name',160,true),num('price'),num('duration',5,480)]);audit('service_created',(int)$db->lastInsertId());flash('خدمت اضافه شد.');go('/resources');}
 if($action==='toggle_resource'){need('resources');$table=val('kind',20)==='service'?'services':'resources';$id=num('id',1);q("UPDATE $table SET active=1-active WHERE id=?",[$id]);audit('resource_toggled',$id);go('/resources');}
 throw new DomainException('درخواست شناخته نشد.');
}
