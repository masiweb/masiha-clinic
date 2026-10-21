<?php
declare(strict_types=1);

/** Identify a patient by exact Iranian national ID and/or mobile. Do not guess among shared numbers. */
function bookingPatientKeys(string $nationalRaw,string $mobileRaw):array{
 $national=MasihaOtp::digits(trim($nationalRaw));
 if($national!==''&&!preg_match('/^\d{10}$/D',$national))throw new DomainException('کد ملی باید ۱۰ رقم باشد.');
 $mobile='';
 if(trim($mobileRaw)!==''){
  $mobile=MasihaOtp::mobile($mobileRaw)??throw new DomainException('شماره موبایل باید معتبر باشد؛ مانند ۰۹۱۲۳۴۵۶۷۸۹.');
 }
 return [$national,$mobile];
}
function bookingPatientMatch(string $national,string $mobile,bool $locked=false):array{
 if($national===''&&$mobile==='')return ['state'=>'empty','patient'=>null];
 $byNational=$national!==''?q('SELECT * FROM patients WHERE national_id=?'.($locked?' FOR UPDATE':''),[$national])->fetchAll():[];
 $byMobile=$mobile!==''?q('SELECT * FROM patients WHERE phone_cell=?'.($locked?' FOR UPDATE':''),[$mobile])->fetchAll():[];
 if(count($byNational)>1)return ['state'=>'conflict','patient'=>null];
 $patient=null;
 if(count($byNational)===1){
  $patient=$byNational[0];
  if($mobile!==''&&$patient['phone_cell']!==null&&$patient['phone_cell']!==''&&MasihaOtp::mobile($patient['phone_cell'])!==$mobile)return ['state'=>'conflict','patient'=>null];
 }elseif(count($byMobile)===1){
  $patient=$byMobile[0];
  if($national!==''&&$patient['national_id']!==null&&$patient['national_id']!==''&&MasihaOtp::digits($patient['national_id'])!==$national)return ['state'=>'conflict','patient'=>null];
 }elseif(count($byMobile)>1)return ['state'=>'conflict','patient'=>null];
 if(!$patient)return ['state'=>'new','patient'=>null];
 if(!(int)$patient['active']||!patientAllowed((int)$patient['pid']))return ['state'=>'conflict','patient'=>null];
 return ['state'=>'existing','patient'=>$patient];
}
/** Called inside the same transaction and advisory lock as the appointment insert. */
function bookingResolvePatient(int $pid,string $nationalRaw,string $mobileRaw,string $first,string $last):array{
 [$national,$mobile]=bookingPatientKeys($nationalRaw,$mobileRaw);
 if($pid){
  $p=q('SELECT * FROM patients WHERE pid=? FOR UPDATE',[$pid])->fetch();
  if(!$p||(int)$p['active']!==1||!patientAllowed($pid))throw new DomainException('پرونده انتخاب‌شده در دسترس نیست.');
  if($national!==''&&$p['national_id']!==null&&$p['national_id']!==''&&MasihaOtp::digits($p['national_id'])!==$national)throw new DomainException('کد ملی با پرونده انتخاب‌شده متفاوت است؛ اطلاعات بیمار را بررسی کنید.');
  if($mobile!==''&&$p['phone_cell']!==null&&$p['phone_cell']!==''&&MasihaOtp::mobile($p['phone_cell'])!==$mobile)throw new DomainException('موبایل با پرونده انتخاب‌شده متفاوت است؛ اطلاعات را بررسی کنید.');
  $match=bookingPatientMatch($national,$mobile,true);
  if($match['state']==='conflict'||$match['state']==='existing'&&(int)$match['patient']['pid']!==$pid)throw new DomainException('کد ملی یا موبایل به پرونده دیگری تعلق دارد؛ انتخاب را بررسی کنید.');
  return ['id'=>$pid,'created'=>false];
 }
 $match=bookingPatientMatch($national,$mobile,true);
 if($match['state']==='existing')return ['id'=>(int)$match['patient']['pid'],'created'=>false];
 if($match['state']==='conflict')throw new DomainException('اطلاعات هویتی با پرونده دیگری مغایرت دارد یا شماره برای چند بیمار ثبت شده است؛ پرونده را بررسی کنید.');
 if($match['state']==='empty')throw new DomainException('برای شناسایی مراجعه‌کننده، کد ملی یا موبایل را وارد کنید.');
 if(!allowed('patients.create'))throw new DomainException('اجازه تشکیل پرونده بیمار جدید ندارید.');
 $first=trim($first);$last=trim($last);
 if($first===''||$last===''||mb_strlen($first)>80||mb_strlen($last)>100)throw new DomainException('برای بیمار جدید، نام و نام خانوادگی را کامل وارد کنید.');
 // Other form fields stay editable only in the patient-profile view.
 q("INSERT INTO patients(fname,lname,phone_cell,national_id,created_by,address,notes,active,allow_patient_portal) VALUES(?,?,?,?,?,'','',1,'NO')",[$first,$last,$mobile?:null,$national?:null,(int)user()['id']]);
 $id=(int)q('SELECT LAST_INSERT_ID()')->fetchColumn();
 audit('patient_created_with_appointment',$id);
 return ['id'=>$id,'created'=>true];
}
function bookingLookupResponse(string $nationalRaw,string $mobileRaw):array{
 [$national,$mobile]=bookingPatientKeys($nationalRaw,$mobileRaw);
 $status=bookingPatientMatch($national,$mobile,false);
 if($status['state']==='existing'){
  $p=$status['patient'];
  return ['state'=>'existing','pid'=>(int)$p['pid'],'name'=>patientName($p),'message'=>'پرونده موجود شناسایی شد؛ نوبت به همین بیمار اختصاص می‌یابد.'];
 }
 return ['state'=>$status['state'],'pid'=>0,'name'=>'','message'=>match($status['state']){
 'empty'=>'کد ملی یا موبایل را وارد کنید.',
 'new'=>'پرونده‌ای با این مشخصات پیدا نشد؛ هنگام ثبت نوبت، پرونده جدید ساخته می‌شود.',
 default=>'اطلاعات با چند پرونده یا اطلاعات ثبت‌شده مغایرت دارد. قبل از ثبت نوبت، پرونده را بررسی کنید.'
 }];
}
