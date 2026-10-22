<?php
function adminApi($path):void{global $config;
 if($path==='/theme.css'){
  header('Content-Type: text/css; charset=utf-8');
  $values=[];
  foreach(['theme_color'=>'#0b827c','theme_hover_color'=>'#086a68','theme_background_color'=>'#f4f7fc'] as $key=>$fallback){
   $value=setting($key,$fallback);
   $values['{{'.$key.'}}']=preg_match('/^#[0-9a-f]{6}$/iD',$value)?$value:$fallback;
  }
  foreach(themeExtraColors() as $key=>[$variable,$fallback,$label]){$value=setting($key,$fallback);$values['{{'.$key.'}}']=preg_match('/^#[0-9a-f]{6}$/iD',$value)?$value:$fallback;}
  $contrast=static function(string $hex):string{
   $channels=[];foreach(str_split(substr($hex,1),2) as $part){$c=hexdec($part)/255;$channels[]=$c<=.04045?$c/12.92:(($c+.055)/1.055)**2.4;}
   return $channels[0]*.2126+$channels[1]*.7152+$channels[2]*.0722>.179?'#000000':'#ffffff';
  };
  $values['{{primary_ink}}']=$contrast($values['{{theme_color}}']);
  $values['{{hover_ink}}']=$contrast($values['{{theme_hover_color}}']);
  $values['{{canvas_ink}}']=$contrast($values['{{theme_background_color}}']);
  // All presentation rules live in CSS files; only validated values enter templates.
  echo strtr(file_get_contents(__DIR__.'/../public/assets/theme.css'),$values);
  foreach(['font_id'=>'body','heading_font_id'=>'heading'] as $key=>$kind){
   $id=(int)setting($key,'0');
   if($id>0&&q('SELECT id FROM custom_fonts WHERE id=?',[$id])->fetchColumn())
    echo str_replace('{{font_id}}',(string)$id,file_get_contents(__DIR__.'/../public/assets/font-'.$kind.'.css'));
  }
  exit;
 }
 if($path==='/font'){$r=q('SELECT * FROM custom_fonts WHERE id=?',[(int)($_GET['id']??0)])->fetch();if(!$r){http_response_code(404);exit;}$f=$config['storage'].'/fonts/'.basename($r['filename']);if(!is_file($f)){http_response_code(404);exit;}header('Content-Type: font/'.$r['format']);readfile($f);exit;}
 if(!str_starts_with($path,'/api/'))return;if(!user()){http_response_code(401);exit;}header('Content-Type: application/json; charset=utf-8');
 receptionLiveApi($path);
 receptionAgendaApi($path);
 if($path==='/api/booking-auto-label'){
  try{
   demand('appointments');
   $pid=filter_var($_GET['pid']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
   $service=filter_var($_GET['service_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
   $date=(string)($_GET['date']??date('Y-m-d'));
   $source=(string)($_GET['booking_source']??'scheduled');
   if($pid===false||$service===false||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!in_array($source,['scheduled','walkin'],true))throw new DomainException('ورودی انتخاب لیبل معتبر نیست.');
   if($pid)requirePatient($pid);
   [$name,$explanation]=clinicAutoLabelName($pid,$service,$date,$source);
   $label=q('SELECT id,color FROM labels WHERE name=? AND active=1 AND deleted=0 ORDER BY id DESC LIMIT 1',[$name])->fetch();
   header('Cache-Control: private, no-store');
   echo json_encode(['name'=>$name,'explanation'=>$explanation,'color'=>$label['color']??'#64748b','id'=>(int)($label['id']??0)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  }catch(DomainException $ex){http_response_code(422);echo json_encode(['error'=>$ex->getMessage()],JSON_UNESCAPED_UNICODE);}
  catch(Throwable $ex){http_response_code(500);error_log('Auto label '.get_class($ex));echo '{"error":"پیشنهاد لیبل در دسترس نیست."}';}
  exit;
 }
 if($path==='/api/booking-patient-lookup'){
  try{
   demand('appointments');
   $result=bookingLookupResponse((string)($_GET['national_id']??''),(string)($_GET['phone_cell']??''));
   header('Cache-Control: no-store, private');
   echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
  }catch(DomainException $ex){http_response_code(422);echo json_encode(['error'=>$ex->getMessage()],JSON_UNESCAPED_UNICODE);}
  catch(Throwable $ex){http_response_code(500);error_log('Booking identity '.get_class($ex));echo '{"error":"بررسی پرونده انجام نشد."}';}
  exit;
 }
 if($path==='/api/appointment-slots'){
  try{
   $integer=static function($key,$default=0){$v=$_GET[$key]??$default;$n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>999999999]]);if($n===false)throw new DomainException('شناسه معتبر نیست.');return $n;};
   $date=$_GET['from']??date('Y-m-d');$room=$_GET['room']??'';if(!is_string($date)||!is_string($room)||mb_strlen($room)>100)throw new DomainException('ورودی معتبر نیست.');
   echo json_encode(['slots'=>appointmentSlotSuggestions($integer('pid'),$integer('therapist'),$integer('clinic',1),$date,$integer('duration',30),$room,$integer('days',14),$integer('days',14)===7?700:30,($_GET['board']??'')==='1')],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
  }catch(DomainException $ex){http_response_code(400);echo json_encode(['error'=>$ex->getMessage()],JSON_UNESCAPED_UNICODE);}
  catch(Throwable $ex){http_response_code(500);error_log('Masiha slots '.get_class($ex));echo '{"error":"جستجوی زمان انجام نشد."}';}exit;
 }
 if($path==='/api/visit-timeline'){
  try{$id=filter_var($_GET['session_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new DomainException('شناسه نوبت معتبر نیست.');echo json_encode(workflowTimeline($id),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
  catch(DomainException $ex){http_response_code(403);echo json_encode(['error'=>$ex->getMessage()],JSON_UNESCAPED_UNICODE);}
  catch(Throwable $ex){http_response_code(500);error_log('Masiha timeline '.get_class($ex));echo '{"error":"نمایش سابقه انجام نشد."}';}exit;
 }
 if($path==='/api/patients'){
  need('patients');
  $term=mb_substr(MasihaOtp::digits(trim((string)($_GET['q']??''))),0,100);
  $day=(string)($_GET['date']??date('Y-m-d'));
  $when=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
  if(!$when||$when->format('Y-m-d')!==$day){
   http_response_code(400);echo '{"error":"تاریخ جستجو معتبر نیست."}';exit;
  }
  $where='p.active=1 AND '.patientScope();
  $args=[];
  if($term!==''){
   $where.=' AND (CONCAT(p.fname,\' \',p.lname) LIKE ? OR CAST(p.pid AS CHAR)=? OR p.national_id LIKE ? OR ('.patientScope('p','contact').' AND p.phone_cell LIKE ?))';
   $args=['%'.$term.'%',$term,'%'.$term.'%','%'.$term.'%'];
  }
  $rows=q('SELECT p.pid,p.fname,p.lname,p.phone_cell,p.national_id FROM patients p WHERE '.$where.' ORDER BY p.lname,p.fname LIMIT 15',$args)->fetchAll();
  $out=[];
  foreach($rows as $row){
   $pid=(int)$row['pid'];
   $session=null;
   if(allowed('appointments')){
    $session=q("SELECT s.id FROM physio_sessions s JOIN physio_episodes e ON e.id=s.episode_id
      WHERE e.pid=? AND s.starts_at>=? AND s.starts_at<DATE_ADD(?,INTERVAL 1 DAY)
       AND s.status<>'cancelled'
      ORDER BY s.starts_at,s.id LIMIT 1",[$pid,$day,$day])->fetchColumn();
   }
   $out[]=[
    'id'=>$pid,'name'=>patientName($row),
    'phone'=>patientAllowed($pid,'contact')?$row['phone_cell']:null,
    'national_id'=>$row['national_id'],
    'has_appointment'=>(bool)$session,
    'href'=>$session?'/appointments?'.http_build_query(['from'=>$day,'to'=>$day,'q'=>(string)$pid]):'/patient?id='.$pid
   ];
  }
  usort($out,static fn($a,$b)=>((int)$b['has_appointment']<=>(int)$a['has_appointment']));
  header('Cache-Control: private, no-store');
  echo json_encode($out,JSON_UNESCAPED_UNICODE);exit;
 }
 if($path==='/api/booking'){need('appointments');$tid=(int)($_GET['therapist']??0);$pid=(int)($_GET['pid']??0);$eps=[];if($pid){requirePatient($pid);$eps=q("SELECT id,diagnosis FROM physio_episodes WHERE pid=? AND status='active' ORDER BY id DESC",[$pid])->fetchAll();}$services=q('SELECT s.id,s.name,s.duration,s.price FROM services s JOIN service_staff x ON x.service_id=s.id WHERE x.staff_id=? AND s.active=1 AND s.deleted=0 ORDER BY s.name',[$tid])->fetchAll();$tariffs=[];if($services){$ids=array_map('intval',array_column($services,'id'));$ph=implode(',',array_fill(0,count($ids),'?'));foreach(q("SELECT id,service_id,tariff_type,title,contract_name,price_toman,effective_from,effective_to FROM service_tariffs WHERE active=1 AND deleted=0 AND service_id IN ($ph) ORDER BY service_id,tariff_type,effective_from DESC,id DESC",$ids)->fetchAll() as $t)$tariffs[(int)$t['service_id']][]=$t;}echo json_encode(['services'=>$services,'episodes'=>$eps,'tariffs'=>$tariffs],JSON_UNESCAPED_UNICODE);exit;}
 http_response_code(404);echo '{}';exit;
}
function guardPage($path):void{
 if(in_array($path,['/patient','/patients/edit'])){$pid=(int)($_GET['id']??0);requirePatient($pid);if($path==='/patients/edit'){demand('patients.edit');if(!patientAllowed($pid,'contact')){http_response_code(403);exit('برای ویرایش مشخصات کامل، دسترسی تماس لازم است.');}}}
 if($path==='/patients/new')demand('patients.create');
 if($path==='/session'){$id=(int)($_GET['id']??0);$s=q('SELECT s.*,e.pid FROM physio_sessions s JOIN physio_episodes e ON e.id=s.episode_id WHERE s.id=?',[$id])->fetch();if(!$s){http_response_code(404);exit;}requirePatient($s['pid']);if(scope('forms.visit')==='none'||(scope('forms.visit')==='own'&&(int)$s['created_by']!==(int)user()['id']&&(int)$s['therapist_id']!==(int)user()['id'])){http_response_code(403);exit('به این فرم دسترسی ندارید.');}}
}
