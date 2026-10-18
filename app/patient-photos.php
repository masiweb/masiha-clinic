<?php
declare(strict_types=1);
function patientPhotoExists(int $pid):bool{
 if(!patientAllowed($pid))return false;
 return (bool)q('SELECT 1 FROM patient_profile_photos WHERE pid=?',[$pid])->fetchColumn();
}
function patientPhotoAvatar(array $patient,bool $large=false):string{
 $pid=(int)$patient['pid'];
 $cls=$large?'large-avatar profile-avatar':'patient-avatar';
 $letter=e(mb_substr((string)($patient['fname']??'ب'),0,1));
 $image=patientPhotoExists($pid);
 $inner=$image?'<img src="/patient-photo?pid='.$pid.'" alt="عکس '.e(patientName($patient)).'" loading="lazy">':icon('users');
 if(!$image)return '<span class="'.e($cls).' fallback-avatar" aria-hidden="true">'.$inner.'</span>';
 return '<button type="button" class="'.e($cls).' photo-preview-trigger" data-photo-preview="/patient-photo?pid='.$pid.'" aria-label="بزرگ‌نمایی تصویر '.e(patientName($patient)).'" title="بزرگ‌نمایی تصویر">'.$inner.'</button>';
}
function servePatientPhoto():never{
 global $config;
 if(!user()){http_response_code(403);exit;}
 $pid=filter_var($_GET['pid']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$pid||!patientAllowed((int)$pid)){http_response_code(403);exit;}
 $r=q('SELECT storage_name,mime FROM patient_profile_photos WHERE pid=?',[$pid])->fetch();
 if(!$r){http_response_code(404);exit;}
 $dir=$config['storage'].'/patient-photos';
 $path=$dir.'/'.basename($r['storage_name']);
 if(!is_file($path)||!in_array($r['mime'],['image/jpeg','image/png','image/webp'],true)){http_response_code(404);exit;}
 header('Content-Type: '.$r['mime']);
 header('Cache-Control: private, no-store');
 header('X-Content-Type-Options: nosniff');
 header('Content-Length: '.filesize($path));
 readfile($path);exit;
}
function savePatientPhoto(int $pid):void{
 global $config;
 demand('patients.edit');requirePatient($pid);
 $f=$_FILES['photo']??null;
 if(!$f||$f['error']!==UPLOAD_ERR_OK||$f['size']<1||$f['size']>3*1024*1024||!is_uploaded_file($f['tmp_name']))
  throw new DomainException('تصویر JPG، PNG یا WebP تا ۳ مگابایت انتخاب کنید.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
 if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new DomainException('فرمت عکس مجاز نیست.');
 $dim=@getimagesize($f['tmp_name']);
 if(!$dim||$dim[0]<80||$dim[1]<80||$dim[0]>5000||$dim[1]>5000||$dim[0]*$dim[1]>16000000)
  throw new DomainException('ابعاد تصویر معتبر نیست.');
 $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];
 $dir=$config['storage'].'/patient-photos';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('photo_dir');
 $filename=bin2hex(random_bytes(24)).'.'.$ext;$path=$dir.'/'.$filename;
 if(!move_uploaded_file($f['tmp_name'],$path))throw new RuntimeException('photo_upload');
 chmod($path,0600);
 try{
  $previous=q('SELECT storage_name FROM patient_profile_photos WHERE pid=?',[$pid])->fetchColumn();
  q('INSERT INTO patient_profile_photos(pid,storage_name,mime) VALUES(?,?,?) ON DUPLICATE KEY UPDATE storage_name=VALUES(storage_name),mime=VALUES(mime),updated_at=NOW()',[$pid,$filename,$mime]);
  audit('patient_photo_updated',$pid);
  if($previous&&is_file($dir.'/'.basename($previous)))@unlink($dir.'/'.basename($previous));
 }catch(Throwable $e){@unlink($path);throw $e;}
}
