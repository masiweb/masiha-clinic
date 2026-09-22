<?php
if(PHP_SAPI!=='cli')exit(1);require __DIR__.'/../app/bootstrap.php';
function ensure($v,$msg){if(!$v)throw new RuntimeException($msg);echo "PASS $msg\n";}
$phone='0999'.random_int(1000000,9999999);$pid=0;$captured='';
try{
 q("INSERT INTO patients(fname,lname,phone_cell,address,notes,allow_patient_portal) VALUES('OTP_QA','TEST',?,'','','YES')",[$phone]);$pid=(int)$db->lastInsertId();
 $service=new MasihaOtp($db,$config['secret'],function($to,$code)use(&$captured,$phone){if($to!==$phone)throw new RuntimeException('recipient');$captured=$code;return true;});
 $id=$service->request($phone,'qa-'.bin2hex(random_bytes(8)));ensure($captured!=='','OTP generated through injected test sender');ensure($service->verify($id,'000000')===null,'wrong OTP rejected');$p=$service->verify($id,$captured);ensure((int)$p['pid']===$pid,'correct OTP resolves patient');ensure($service->verify($id,$captured)===null,'OTP replay rejected');
 try{$service->request($phone,'qa-other');throw new RuntimeException('rate failure');}catch(DomainException){echo "PASS resend rate limit\n";}
 ensure(MasihaOtp::mobile('۰۰۹۸۹۱۲۳۴۵۶۷۸۹')==='09123456789','Persian phone normalization');
}finally{if($pid){q('DELETE FROM masiha_otp_challenges WHERE pid=?',[$pid]);q('DELETE FROM patients WHERE pid=?',[$pid]);}}
