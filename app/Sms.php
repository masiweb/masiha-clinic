<?php
final class MasihaSms {
 public static function config():array{
  global $config;
  $file=$config['storage'].'/sms.json';
  if(!is_file($file))return [];
  try{$v=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(Throwable){return [];}
 }
 public static function enabled():bool{return setting('sms_notifications','0')==='1';}
 public static function template(string $event):?array{
  $r=q('SELECT * FROM sms_templates WHERE event_key=? AND enabled=1',[$event])->fetch();
  return $r?:null;
 }
 public static function context(int $pid,int $sessionId=0):?array{
  $p=q('SELECT pid,fname,lname,phone_cell,DOB FROM patients WHERE pid=? AND active=1',[$pid])->fetch();
  if(!$p)return null;
  $ctx=[
   'patient_name'=>trim($p['fname'].' '.$p['lname']),
   'first_name'=>(string)$p['fname'],
   'clinic_name'=>setting('name','مدیریت کلینیک مسیحا'),
   'clinic_phone'=>setting('phone',''),
   'date'=>'','time'=>'','therapist'=>'','service'=>'','clinic'=>''
  ];
  if($sessionId){
   $s=q("SELECT s.starts_at,s.treatment,u.name therapist_name,COALESCE(c.name,'') clinic_name,COALESCE(v.name,s.treatment,'') service_name
         FROM physio_sessions s
         JOIN staff u ON u.id=s.therapist_id
         LEFT JOIN clinics c ON c.id=s.clinic_id
         LEFT JOIN services v ON v.id=s.service_id
         JOIN physio_episodes e ON e.id=s.episode_id
         WHERE s.id=? AND e.pid=?",[$sessionId,$pid])->fetch();
   if($s){
    $ctx['date']=jd($s['starts_at'],'yyyy/MM/dd');
    $ctx['time']=fa(date('H:i',strtotime($s['starts_at'])));
    $ctx['therapist']=(string)$s['therapist_name'];
    $ctx['service']=(string)$s['service_name'];
    $ctx['clinic']=(string)$s['clinic_name'];
   }
  }
  $ctx['_mobile']=MasihaOtp::mobile((string)($p['phone_cell']??''));
  $ctx['_dob']=$p['DOB'];
  return $ctx;
 }
 public static function render(string $body,array $ctx):string{
  $map=[];foreach($ctx as $k=>$v)if(!str_starts_with((string)$k,'_'))$map['{'.$k.'}']=(string)$v;
  return mb_substr(trim(strtr($body,$map)),0,2000);
 }
 public static function queue(string $event,int $pid,int $sessionId=0,?string $scheduledAt=null,int $actor=0,string $dedupeSuffix=''):bool{
  if(!self::enabled())return false;
  $tpl=self::template($event);if(!$tpl)return false;
  $ctx=self::context($pid,$sessionId);if(!$ctx||empty($ctx['_mobile']))return false;
  if($scheduledAt===null){
   if($event==='appointment_reminder'&&$sessionId){
    $start=q('SELECT starts_at FROM physio_sessions WHERE id=?',[$sessionId])->fetchColumn();
    if(!$start)return false;
    $scheduledAt=date('Y-m-d H:i:s',strtotime((string)$start)-max(0,(int)$tpl['send_offset_minutes'])*60);
   }else $scheduledAt=date('Y-m-d H:i:s');
  }
  $message=self::render((string)$tpl['message_template'],$ctx);if($message==='')return false;
  $dedupe=hash('sha256',implode('|',[$event,$pid,$sessionId,$scheduledAt,$dedupeSuffix]));
  q("INSERT IGNORE INTO sms_outbox(pid,session_id,event_key,mobile,message,status,scheduled_at,dedupe_key,created_by)
     VALUES(?,?,?,?,?,'queued',?,?,?)",[$pid,$sessionId?:null,$event,$ctx['_mobile'],$message,$scheduledAt,$dedupe,$actor]);
  return q('SELECT ROW_COUNT()')->fetchColumn()>0;
 }
 public static function queueAppointment(int $sessionId,int $actor=0):void{
  $pid=(int)q('SELECT e.pid FROM physio_sessions s JOIN physio_episodes e ON e.id=s.episode_id WHERE s.id=?',[$sessionId])->fetchColumn();
  if(!$pid)return;
  self::queue('appointment_created',$pid,$sessionId,null,$actor);
  self::queue('appointment_reminder',$pid,$sessionId,null,$actor);
 }
 public static function queueDueReminders():int{
  if(!self::enabled()||!self::template('appointment_reminder'))return 0;
  $rows=q("SELECT s.id,e.pid FROM physio_sessions s JOIN physio_episodes e ON e.id=s.episode_id
           WHERE s.status='scheduled' AND s.starts_at>NOW() AND s.starts_at<DATE_ADD(NOW(),INTERVAL 7 DAY)
           ORDER BY s.starts_at LIMIT 1000")->fetchAll();
  $n=0;foreach($rows as $r)if(self::queue('appointment_reminder',(int)$r['pid'],(int)$r['id']))$n++;return $n;
 }
 public static function queueBirthdays():int{
  if(!self::enabled()||!self::template('birthday'))return 0;
  $today=MasihaJalali::format(date('Y-m-d'),'MM-dd');$key=date('Y-m-d');$n=0;
  foreach(q("SELECT pid,DOB FROM patients WHERE active=1 AND DOB IS NOT NULL AND phone_cell IS NOT NULL AND phone_cell<>''")->fetchAll() as $p){
   if(MasihaJalali::format($p['DOB'],'MM-dd')!==$today)continue;
   if(self::queue('birthday',(int)$p['pid'],0,date('Y-m-d H:i:s'),0,$key))$n++;
  }
  return $n;
 }
 public static function send(array $row):array{
  $cfg=self::config();$key=trim((string)($cfg['key']??''));
  if($key==='')return [false,'','تنظیمات اتصال پیامک کامل نیست.'];
  $post=['receptor'=>$row['mobile'],'message'=>$row['message']];
  if(!empty($cfg['sender']))$post['sender']=$cfg['sender'];
  $ch=curl_init('https://api.kavenegar.com/v1/'.rawurlencode($key).'/sms/send.json');
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
  $out=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
  if($out===false||$http!==200)return [false,'','خطای ارتباط با سرویس پیامک'.($err!==''?'':' (HTTP '.$http.')')];
  try{$j=json_decode((string)$out,true,512,JSON_THROW_ON_ERROR);}catch(Throwable){return [false,'','پاسخ سرویس پیامک قابل خواندن نبود.'];}
  $ok=(int)($j['return']['status']??0)===200;$mid=(string)($j['entries'][0]['messageid']??'');
  return [$ok,$mid,$ok?'':mb_substr((string)($j['return']['message']??'ارسال ناموفق بود.'),0,450)];
 }
 public static function process(int $limit=20):array{
  if(!self::enabled())return ['disabled'=>true,'sent'=>0,'failed'=>0];
  $sent=$failed=0;$limit=max(1,min(100,$limit));
  for($i=0;$i<$limit;$i++){
   global $db;$db->beginTransaction();
   try{
    $row=q("SELECT * FROM sms_outbox WHERE status='queued' AND scheduled_at<=NOW() ORDER BY scheduled_at,id LIMIT 1 FOR UPDATE")->fetch();
    if(!$row){$db->commit();break;}
    q("UPDATE sms_outbox SET status='sending',attempts=attempts+1,last_error='' WHERE id=?",[$row['id']]);$db->commit();
   }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
   [$ok,$mid,$error]=self::send($row);
   if($ok){q("UPDATE sms_outbox SET status='sent',sent_at=NOW(),provider_message_id=?,last_error='' WHERE id=?",[$mid,$row['id']]);$sent++;}
   else{$attempts=(int)$row['attempts']+1;if($attempts<3)q("UPDATE sms_outbox SET status='queued',scheduled_at=DATE_ADD(NOW(),INTERVAL ? MINUTE),last_error=? WHERE id=?",[5*$attempts,$error,$row['id']]);else q("UPDATE sms_outbox SET status='failed',last_error=? WHERE id=?",[$error,$row['id']]);$failed++;}
  }
  return ['disabled'=>false,'sent'=>$sent,'failed'=>$failed];
 }
}
