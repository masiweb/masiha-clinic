<?php
// Masiha Clinic: standalone application, no upstream runtime dependencies.
declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');
$config=require (getenv('MASIHA_CONFIG')?:'/etc/masiha-clinic/config.php');
$db=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+03:30'");
if(PHP_SAPI!=='cli'){
 session_name('masiha_session');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);ini_set('session.use_strict_mode','1');session_start();
 header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
 if(isset($_SESSION['last_seen'])&&time()-$_SESSION['last_seen']>3600){$_SESSION=[];session_regenerate_id(true);}$_SESSION['last_seen']=time();
 $_SESSION['csrf']??=bin2hex(random_bytes(32));
}
require_once __DIR__.'/Jalali.php';require_once __DIR__.'/Otp.php';
function q(string $s,array $a=[]):PDOStatement{global $db;$st=$db->prepare($s);$st->execute($a);return $st;}
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function fa($v):string{return strtr((string)$v,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);}
function money($v):string{return fa(number_format((float)$v));}
function jd($v,$p='d MMMM yyyy'):string{return $v?MasihaJalali::format($v,$p):'—';}
function setting($key,$default=''):string{$v=q('SELECT value FROM settings WHERE `key`=?',[$key])->fetchColumn();return $v===false?$default:(string)$v;}
function setsetting($k,$v):void{q('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$k,(string)$v]);}
function go(string $path):never{header('Location: '.$path, true,303);exit;}
function csrf():void{echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';}
function checkcsrf():void{if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf'])){http_response_code(403);exit('درخواست معتبر نیست. صفحه را تازه کنید.');}}
function user():?array{static $u=false;if($u===false)$u=!empty($_SESSION['uid'])?(q('SELECT id,username,name,role,active FROM staff WHERE id=? AND active=1',[$_SESSION['uid']])->fetch()?:null):null;return $u;}
require_once __DIR__.'/permissions.php';
function need(string $section):void{if(!user())go('/login');if(!can($section)){http_response_code(403);exit('به این بخش دسترسی ندارید.');}}
function audit($action,$id=0):void{q('INSERT INTO audit(actor,action,entity) VALUES(?,?,?)',[(int)($_SESSION['uid']??-($_SESSION['pid']??0)),$action,$id]);}
function val($key,$max=255,$required=false):string{if(isset($_POST[$key])&&!is_scalar($_POST[$key]))throw new DomainException('ورودی معتبر نیست.');$v=trim((string)($_POST[$key]??''));if(mb_strlen($v)>$max||($required&&$v===''))throw new DomainException('فیلدهای ضروری را کامل و در محدوده مجاز وارد کنید.');return $v;}
function num($key,$min=0,$max=999999999999):int{$s=MasihaOtp::digits((string)($_POST[$key]??''));if(!preg_match('/^-?\d+$/D',$s)||($n=(int)$s)<$min||$n>$max)throw new DomainException('عدد واردشده معتبر نیست.');return $n;}
function datevalue($key,$required=false):?string{$v=val($key,16,$required);if($v==='')return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v||(int)substr($v,0,4)<1900||(int)substr($v,0,4)>2200)throw new DomainException('تاریخ معتبر وارد کنید.');return $v;}
function episode($id,$checkForm=true):array{$r=q('SELECT e.*,p.fname,p.lname,p.phone_cell FROM physio_episodes e JOIN patients p ON p.pid=e.pid WHERE e.id=?',[$id])->fetch();if(!$r)throw new DomainException('دوره درمان پیدا نشد.');if(user()){requirePatient($r['pid']);if($checkForm&&(scope('forms.visit')==='none'||(scope('forms.visit')==='own'&&(int)$r['created_by']!==(int)user()['id']))){http_response_code(403);exit('به این فرم دسترسی ندارید.');}}return $r;}
function totalpaid($id):int{return (int)q('SELECT COALESCE(SUM(amount_toman),0) FROM physio_payments WHERE episode_id=? AND voided=0',[$id])->fetchColumn();}
function badge($status):string{$map=['active'=>'در حال درمان','completed'=>'تکمیل‌شده','cancelled'=>'لغوشده','scheduled'=>'رزروشده','done'=>'انجام‌شده','absent'=>'عدم مراجعه'];return '<span class="badge '.e($status).'">'.e($map[$status]??$status).'</span>';}
function flash($text):void{$_SESSION['flash']=$text;}
function schedulingLock():void{if((int)q("SELECT GET_LOCK('masiha_schedule',5)")->fetchColumn()!==1)throw new DomainException('برنامه در حال به‌روزرسانی است؛ دوباره تلاش کنید.');}
function schedulingUnlock():void{q("SELECT RELEASE_LOCK('masiha_schedule')");}
function conflict($start,$end,$therapist,$room,$equipment,$pid,$except=0):bool{return (bool)q("SELECT s.id FROM physio_sessions s JOIN physio_episodes e ON e.id=s.episode_id WHERE s.status IN ('scheduled','done') AND s.starts_at<? AND s.ends_at>? AND s.id<>? AND (s.therapist_id=? OR (?<>'' AND s.room=?) OR (?<>'' AND s.equipment=?) OR e.pid=?) LIMIT 1",[$end,$start,$except,$therapist,$room,$room,$equipment,$equipment,$pid])->fetchColumn();}
function uploadDocument($pid):void{global $config;$f=$_FILES['file']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK||$f['size']>10*1024*1024||!is_uploaded_file($f['tmp_name']))throw new DomainException('فایل سالم تا ۱۰ مگابایت انتخاب کنید.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);if(!in_array($mime,['application/pdf','image/jpeg','image/png'],true))throw new DomainException('فقط فایل PDF، JPG یا PNG پذیرفته می‌شود.');if((int)q('SELECT COALESCE(SUM(bytes),0) FROM physio_patient_documents WHERE pid=?',[$pid])->fetchColumn()+$f['size']>100*1024*1024)throw new DomainException('فضای مدارک این پرونده پر شده است.');$name=bin2hex(random_bytes(24));$target=$config['storage'].'/documents/'.$name;if(!move_uploaded_file($f['tmp_name'],$target))throw new RuntimeException('upload');chmod($target,0600);try{q('INSERT INTO physio_patient_documents(pid,title,storage_name,mime,bytes) VALUES(?,?,?,?,?)',[$pid,val('title',150,true),$name,$mime,$f['size']]);audit('document_uploaded',(int)q('SELECT LAST_INSERT_ID()')->fetchColumn());}catch(Throwable $ex){unlink($target);throw $ex;}}

function validateResources($room,$equipment):void{if(!q("SELECT id FROM resources WHERE name=? AND kind='room' AND active=1",[$room])->fetchColumn())throw new DomainException('اتاق فعال انتخاب کنید.');if($equipment!==''&&!q("SELECT id FROM resources WHERE name=? AND kind='equipment' AND active=1",[$equipment])->fetchColumn())throw new DomainException('تجهیز فعال انتخاب کنید.');}
