<?php
function permissionGroups():array{return [
 'مالی'=>['finance.debt'=>'مشاهده بدهی','finance.history'=>'مشاهده سوابق پرداخت','finance.pay'=>'ثبت پرداخت یا بازگشت وجه','finance.referral'=>'تعیین سهم معرفی بیمار','finance.edit'=>'ویرایش مالی','finance.delete'=>'ابطال تراکنش','reports'=>'گزارش‌های کامل مالی'],
 'حساب‌های کارکنان'=>['staff.create'=>'ساخت کاربر / درمانگر','staff.edit'=>'ویرایش کاربر / درمانگر','staff.delete'=>'حذف کاربر / درمانگر'],
 'مراجعین'=>['patients.create'=>'ساخت پرونده','patients.edit'=>'ویرایش پرونده','patients.delete'=>'حذف و بایگانی پرونده'],
 'لیبل‌ها'=>['labels.create'=>'ساخت لیبل','labels.edit'=>'ویرایش لیبل','labels.delete'=>'حذف لیبل'],
 'مدیریت'=>['appointments'=>'نوبت‌دهی','services'=>'مدیریت خدمات و پکیج‌ها','settings'=>'تنظیمات کلینیک']
];}
function defaultsFor($role):array{$p=['profile'=>'none','contact'=>'none','forms.visit'=>'none','forms.general'=>'none'];foreach(permissionGroups() as $g)foreach($g as $k=>$v)$p[$k]=false;
 if($role==='reception'){$p=array_replace($p,['profile'=>'all','contact'=>'all','forms.visit'=>'none','forms.general'=>'own']);foreach(['appointments','patients.create','patients.edit','finance.debt','finance.history','finance.pay'] as $k)$p[$k]=true;}
 if($role==='therapist')$p=array_replace($p,['profile'=>'related','contact'=>'related','forms.visit'=>'own','forms.general'=>'own','appointments'=>true]);
 if($role==='finance')foreach(['finance.debt','finance.history','finance.pay','reports'] as $k)$p[$k]=true;
 return $p;}
function permissions(?array $u=null):array{$u??=user();if(!$u)return defaultsFor('');$j=q('SELECT permissions FROM staff_permissions WHERE staff_id=?',[$u['id']])->fetchColumn();return $j?array_replace(defaultsFor(''),json_decode($j,true)?:[]):defaultsFor($u['role']);}
function allowed($key):bool{return user()&& (user()['role']==='admin'||(permissions()[$key]??false)===true);}
function scope($key):string{return (user()['role']??'')==='admin'?'all':(string)(permissions()[$key]??'none');}
function demand($key):void{if(!allowed($key)){http_response_code(403);exit('اجازه انجام این عملیات را ندارید.');}}
function patientScope($alias='p',$kind='profile'):string{$s=scope($kind);$u=(int)(user()['id']??0);if($s==='all')return '1=1';if($s!=='related')return '1=0';return "($alias.providerID=$u OR $alias.created_by=$u OR EXISTS(SELECT 1 FROM physio_episodes ax JOIN physio_sessions sx ON sx.episode_id=ax.id WHERE ax.pid=$alias.pid AND sx.therapist_id=$u))";}
function patientAllowed($pid,$kind='profile'):bool{return (bool)q('SELECT p.pid FROM patients p WHERE p.pid=? AND '.patientScope('p',$kind),[$pid])->fetchColumn();}
function requirePatient($pid):void{if(!patientAllowed($pid)){http_response_code(403);exit('به این پرونده دسترسی ندارید.');}}
function formScope($alias,$kind='visit'):string{$s=scope('forms.'.$kind);return $s==='all'?'1=1':($s==='own'?$alias.'.created_by='.(int)user()['id']:'1=0');}
function can(string $section):bool{if(!user())return false;if(user()['role']==='admin')return true;return match($section){'dashboard'=>true,'patients'=>scope('profile')!=='none'||allowed('patients.create'),'episodes'=>scope('profile')!=='none'&&scope('forms.visit')!=='none','forms'=>scope('profile')!=='none'&&(scope('forms.visit')!=='none'||scope('forms.general')!=='none'),'finance'=>array_any_compat(['finance.debt','finance.history','finance.pay','finance.referral','finance.edit','finance.delete']), 'staff'=>array_any_compat(['staff.create','staff.edit','staff.delete']),'labels'=>array_any_compat(['labels.create','labels.edit','labels.delete']),'resources','services'=>allowed('services'),default=>allowed($section)};}
function array_any_compat($keys):bool{foreach($keys as $k)if(allowed($k))return true;return false;}
function guardLegacyAction($a):void{
 if(in_array($a,['patient','archive_patient','document'])){$pid=(int)($_POST['pid']??0);demand($a==='patient'?($pid?'patients.edit':'patients.create'):($a==='archive_patient'?'patients.delete':'patients.edit'));if($pid){requirePatient($pid);if($a==='patient'&&!patientAllowed($pid,'contact')){http_response_code(403);exit('برای ویرایش مشخصات کامل، دسترسی اطلاعات تماس لازم است.');}}}
 if(in_array($a,['episode','episode_update','session'])){if($a==='episode'){requirePatient((int)($_POST['pid']??0));if(!can('episodes')){http_response_code(403);exit;}}else{$id=(int)($_POST[$a==='session'?'session_id':'episode_id']??0);$r=q('SELECT * FROM '.($a==='session'?'physio_sessions':'physio_episodes').' WHERE id=?',[$id])->fetch();if(!$r)throw new DomainException('فرم پیدا نشد.');$ep=$a==='session'?q('SELECT * FROM physio_episodes WHERE id=?',[$r['episode_id']])->fetch():$r;requirePatient($ep['pid']);if(scope('forms.visit')==='none'||(scope('forms.visit')==='own'&&(int)$r['created_by']!==(int)user()['id'])){http_response_code(403);exit('فقط فرم‌های مجاز را می‌توانید ویرایش کنید.');}}if($a==='episode'&&!allowed('finance.edit'))$_POST['fee_toman']='0';}
 if($a==='payment')demand('finance.pay');
 if(in_array($a,['service','resource','toggle_resource']))demand('services');
 if(in_array($a,['schedule','slot','disable_slot']))demand('appointments');
 if($a==='staff') {demand((int)($_POST['id']??0)?'staff.edit':'staff.create');$target=q('SELECT role FROM staff WHERE id=?',[(int)($_POST['id']??0)])->fetchColumn();if(user()['role']!=='admin'&&(int)($_POST['id']??0)){ $tu=q('SELECT * FROM staff WHERE id=?',[(int)$_POST['id']])->fetch();if($tu){$mine=permissions();foreach(permissions($tu) as $k=>$v){$rank=['none'=>0,'own'=>1,'related'=>1,'all'=>2];$a=is_bool($v)?(int)$v:($rank[$v]??0);$mv=$mine[$k]??false;$b=is_bool($mv)?(int)$mv:($rank[$mv]??0);if($a>$b){http_response_code(403);exit('ویرایش حساب دارای دسترسی بالاتر فقط توسط مدیر مجاز است.');}}}}if(user()['role']!=='admin'&&($target==='admin'||($_POST['role']??'')==='admin')){http_response_code(403);exit('مدیریت حساب مدیر فقط برای مدیر مجاز است.');}}
}
