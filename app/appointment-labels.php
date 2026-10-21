<?php
declare(strict_types=1);

/** Stable set of clinic labels. The meaning is separate from hashtags. */
function clinicLabelColors():array{
 return ['ویزیت'=>'#6366F1','ویزیت درمان'=>'#F97316','بیمار جدید'=>'#0EA5E9','نرمال'=>'#10B981','حضوری'=>'#F59E0B','ورزش درمانی'=>'#8B5CF6','تمرین پلاس'=>'#EC4899'];
}
function clinicLabelDescriptions():array{
 return [
  'ویزیت'=>'ویزیت اولیه',
  'ویزیت درمان'=>'پس از ویزیت امروز، برای ادامه درمان در همان روز',
  'بیمار جدید'=>'ویزیت امروز انجام شده و نوبت آینده دارد',
  'نرمال'=>'حداقل یک جلسه درمان تکمیل کرده است',
  'حضوری'=>'بدون وقت قبلی مراجعه کرده است',
  'ورزش درمانی'=>'خدمات او فقط تمرین یا ورزش درمانی است',
  'تمرین پلاس'=>'تمرین محور است و ممکن است خدمات فیزیوتراپی نیز داشته باشد'
 ];
}
function clinicAutoLabelName(int $pid,int $serviceId,string $appointmentDate,string $bookingSource='scheduled'):array{
 if($bookingSource==='walkin')return ['حضوری','مراجعه بدون رزرو قبلی'];
 $service=$serviceId?(string)q('SELECT name FROM services WHERE id=?',[$serviceId])->fetchColumn():'';
 if($serviceId>0){
  if(in_array($serviceId,[4,6,12],true)||preg_match('/^(ورزش درمانی|تمرین درمانی|آموزش ورزش)/u',$service))
   return ['ورزش درمانی','خدمت انتخابی فقط ورزش یا تمرین درمانی است'];
  if($serviceId===16||preg_match('/(منوال.*تمرین|تمرین.*فیزیو|تمرین پلاس)/u',$service))
   return ['تمرین پلاس','برنامه ترکیبی با محوریت تمرین'];
 }
 if($serviceId===1||$service==='ویزیت اولیه')return ['ویزیت','ویزیت اولیه'];
 if($pid>0){
  $done=(int)q("SELECT COUNT(*) FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id WHERE ep.pid=? AND s.status='done' AND (s.service_id IS NULL OR s.service_id<>1)",[$pid])->fetchColumn();
  if($done>0)return ['نرمال','بیمار حداقل یک جلسه درمان را تمام کرده است'];
  $today=date('Y-m-d');
  $seen=(int)q("SELECT COUNT(*) FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id WHERE ep.pid=? AND s.status='done' AND DATE(s.starts_at)=? AND (s.service_id=1 OR s.service_id IS NULL)",[$pid,$today])->fetchColumn();
  if($seen>0&&$appointmentDate>$today)return ['بیمار جدید','ویزیت امروز انجام شده و نوبت آینده ثبت می‌شود'];
  if($seen>0&&$appointmentDate===$today&&$serviceId>0)return ['ویزیت درمان','بیمار امروز ویزیت شده و اکنون نوبت درمان دارد'];
 }
 return ['ویزیت','هنوز سابقه درمان تکمیل‌شده یا شواهد کافی برای لیبل دیگر وجود ندارد'];
}
function clinicFindLabelId(string $name):int{
 $id=(int)q('SELECT id FROM labels WHERE name=? AND active=1 AND deleted=0 ORDER BY id DESC LIMIT 1',[$name])->fetchColumn();
 if(!$id)throw new DomainException('لیبل موردنیاز در سیستم فعال نیست: '.$name);
 return $id;
}
function clinicAssignPatientLabel(int $pid,int $labelId,bool $manual):void{
 $state=q('SELECT selection_mode FROM patient_label_modes WHERE pid=?',[$pid])->fetchColumn();
 $old=q('SELECT label_id FROM patients WHERE pid=?',[$pid])->fetchColumn();
 if(!$manual&&($state==='manual'||($state===false&&$old!==false&&$old!==null&&$old!=='')))return;
 q('UPDATE patients SET label_id=? WHERE pid=?',[$labelId?:null,$pid]);
 q("INSERT INTO patient_label_modes(pid,selection_mode) VALUES(?,?) ON DUPLICATE KEY UPDATE selection_mode=VALUES(selection_mode)",[$pid,$manual?'manual':'auto']);
}
function clinicBookSessionLabel(int $sessionId,int $pid,int $serviceId,string $date,string $source,int $manualLabel):array{
 if(!in_array($source,['scheduled','walkin'],true))throw new DomainException('شیوه مراجعه معتبر نیست.');
 [$name,$why]=clinicAutoLabelName($pid,$serviceId,$date,$source);
 $id=$manualLabel?:clinicFindLabelId($name);
 q('UPDATE physio_sessions SET label_id=? WHERE id=?',[$id,$sessionId]);
 q('DELETE FROM session_labels WHERE session_id=?',[$sessionId]);
 q('INSERT INTO session_labels(session_id,label_id) VALUES(?,?)',[$sessionId,$id]);
 q("INSERT INTO session_label_modes(session_id,selection_mode,booking_source,explanation) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE selection_mode=VALUES(selection_mode),booking_source=VALUES(booking_source),explanation=VALUES(explanation)",[$sessionId,$manualLabel?'manual':'auto',$source,$manualLabel?'انتخاب دستی هنگام ثبت نوبت':$why]);
 clinicAssignPatientLabel($pid,$id,$manualLabel>0);
 return ['name'=>$manualLabel?(string)q('SELECT name FROM labels WHERE id=?',[$manualLabel])->fetchColumn():$name,'reason'=>$manualLabel?'انتخاب دستی':$why];
}
/** Treatment completion can transition automatically to Normal, except explicit therapy labels and overrides. */
function clinicAfterTreatmentLabel(int $sessionId):void{
 $r=q("SELECT s.id,s.service_id,s.label_id,s.episode_id,ep.pid,slm.selection_mode,slm.booking_source FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id JOIN session_label_modes slm ON slm.session_id=s.id WHERE s.id=? AND s.status='done' FOR UPDATE",[$sessionId])->fetch();
 if(!$r||$r['selection_mode']!=='auto'||(int)$r['service_id']===1||$r['booking_source']==='walkin')return;
 $name=(string)q('SELECT name FROM labels WHERE id=?',[$r['label_id']])->fetchColumn();
 if(in_array($name,['ورزش درمانی','تمرین پلاس'],true))return;
 $normal=clinicFindLabelId('نرمال');
 if((int)$r['label_id']!==$normal){
  q('UPDATE physio_sessions SET label_id=? WHERE id=?',[$normal,$sessionId]);
  q('DELETE FROM session_labels WHERE session_id=?',[$sessionId]);
  q('INSERT INTO session_labels(session_id,label_id) VALUES(?,?)',[$sessionId,$normal]);
  q("UPDATE session_label_modes SET explanation='ثبت پایان نخستین/جلسه درمان' WHERE session_id=?",[$sessionId]);
  audit('appointment_label_auto_normal',$sessionId);
 }
 clinicAssignPatientLabel((int)$r['pid'],$normal,false);
}
function clinicHashtagColor(string $name):string{
 $palette=['#2563EB','#D946EF','#EA580C','#059669','#7C3AED','#DC2626','#0891B2','#CA8A04','#DB2777','#0D9488','#4F46E5','#65A30D'];
 $index=hexdec(substr(hash('sha256',$name),0,7))%count($palette);
 $taken=[];
 foreach(q('SELECT color,COUNT(*) n FROM hashtags GROUP BY color')->fetchAll() as $row)$taken[strtolower($row['color'])]=(int)$row['n'];
 $best=$palette[$index];$count=PHP_INT_MAX;
 for($i=0;$i<count($palette);$i++){
  $candidate=$palette[($index+$i)%count($palette)];
  $used=$taken[strtolower($candidate)]??0;
  if($used<$count){$best=$candidate;$count=$used;if($used===0)break;}
 }
 return $best;
}


/** Recheck future, automatically labelled appointments after a recorded clinical outcome.
 *  Manual session/patient labels are never overwritten.
 */
function clinicRefreshOpenAppointmentLabels(int $pid,int $excludeSession=0):void{
 $today=date('Y-m-d');
 $rows=q("SELECT s.id,s.service_id,s.label_id,s.starts_at,m.booking_source
  FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id
  JOIN session_label_modes m ON m.session_id=s.id
  WHERE ep.pid=? AND s.id<>? AND s.status='scheduled' AND DATE(s.starts_at)>=?
  AND m.selection_mode='auto' ORDER BY s.starts_at,s.id",[$pid,$excludeSession,$today])->fetchAll();
 $patientLabel=null;
 foreach($rows as $s){
  [$name,$reason]=clinicAutoLabelName($pid,(int)($s['service_id']??0),substr($s['starts_at'],0,10),$s['booking_source']);
  $label=clinicFindLabelId($name);
  if($patientLabel===null)$patientLabel=$label;
  if((int)$s['label_id']===$label)continue;
  q('UPDATE physio_sessions SET label_id=? WHERE id=?',[$label,$s['id']]);
  q('DELETE FROM session_labels WHERE session_id=?',[$s['id']]);
  q('INSERT INTO session_labels(session_id,label_id) VALUES(?,?)',[$s['id'],$label]);
  q('UPDATE session_label_modes SET explanation=? WHERE session_id=?',[$reason,$s['id']]);
  audit('future_appointment_auto_label_refreshed',(int)$s['id']);
 }
 if($patientLabel!==null)clinicAssignPatientLabel($pid,$patientLabel,false);
}
