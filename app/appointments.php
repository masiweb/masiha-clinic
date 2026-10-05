<?php
declare(strict_types=1);

function appointmentFilters(array $input):array {
 $f=[];
 foreach(['from','to'] as $k){
  $v=$input[$k]??($input['date']??date('Y-m-d'));
  if(!is_string($v))throw new DomainException('تاریخ معتبر نیست.');
  $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
  if(!$d||$d->format('Y-m-d')!==$v)throw new DomainException('تاریخ معتبر نیست.');
  $f[$k]=$v;
 }
 if($f['to']<$f['from']||strtotime($f['to'])-strtotime($f['from'])>366*86400)throw new DomainException('بازه گزارش باید حداکثر یک سال باشد.');
 foreach(['therapist','clinic','visit_type','service','package','label','diagnosis'] as $k){
  $v=$input[$k]??'0';if(!is_scalar($v)||!preg_match('/^\d+$/D',(string)$v))throw new DomainException('فیلتر عددی معتبر نیست.');$f[$k]=(int)$v;
 }
 foreach(['q','room','state','presence','patient_type','financial'] as $k){$v=$input[$k]??'';if(!is_string($v)||mb_strlen($v)>100)throw new DomainException('فیلتر معتبر نیست.');$f[$k]=trim(MasihaOtp::digits($v));}
 if($f['state']!==''&&!isset(workflowLabels()[$f['state']]))throw new DomainException('مرحله مراجعه معتبر نیست.');
 if(!in_array($f['presence'],['','present','not_arrived'])||!in_array($f['patient_type'],['','new','returning'])||!in_array($f['financial'],['','debt','settled']))throw new DomainException('فیلتر معتبر نیست.');
 if($f['financial']!==''&&!allowed('finance.debt'))throw new DomainException('اجازه فیلتر مالی ندارید.');
 if($f['diagnosis']&&scope('forms.visit')==='none')throw new DomainException('اجازه فیلتر تشخیص ندارید.');
 return $f;
}

function appointmentWhere(array $f,bool $withState=true):array{
 $where=['s.starts_at>=?','s.starts_at<DATE_ADD(?,INTERVAL 1 DAY)',patientScope('p')];$a=[$f['from'],$f['to']];
 foreach(['therapist'=>'s.therapist_id','clinic'=>'s.clinic_id','visit_type'=>'s.visit_type_id','service'=>'s.service_id','package'=>'s.package_id'] as $k=>$col)if($f[$k]){$where[]="$col=?";$a[]=$f[$k];}
 if($f['room']!==''){$where[]='s.room=?';$a[]=$f['room'];}
 if($withState&&$f['state']!==''){$where[]='w.state=?';$a[]=$f['state'];}
 if($f['label']){$where[]='(s.label_id=? OR EXISTS(SELECT 1 FROM session_labels sl WHERE sl.session_id=s.id AND sl.label_id=?))';$a[]=$f['label'];$a[]=$f['label'];}
 if($f['diagnosis']){
  $clinical=scope('forms.visit')==='all'?'1=1':'(s.therapist_id='.(int)user()['id'].' OR s.created_by='.(int)user()['id'].')';
  $where[]="($clinical AND EXISTS(SELECT 1 FROM session_diagnoses sd WHERE sd.session_id=s.id AND sd.diagnosis_id=?))";$a[]=$f['diagnosis'];
 }
 if($f['presence']!=='')$where[]='w.arrived_at IS '.($f['presence']==='present'?'NOT ':'').'NULL';
 if($f['patient_type']!=='')$where[]=($f['patient_type']==='new'?'NOT ':'')."(EXISTS(SELECT 1 FROM physio_sessions previous JOIN physio_episodes pe ON pe.id=previous.episode_id WHERE pe.pid=p.pid AND previous.status='done' AND previous.starts_at<s.starts_at) OR EXISTS(SELECT 1 FROM import_patient_events ie JOIN import_records ir ON ir.id=ie.record_id WHERE ir.pid=p.pid AND ie.status_code='done' AND ie.event_date<DATE(s.starts_at)))";
 if($f['q']!==''){$where[]="(CONCAT(p.fname,' ',p.lname) LIKE ? OR CAST(p.pid AS CHAR)=? OR (".patientScope('p','contact')." AND p.phone_cell LIKE ?))";array_push($a,'%'.$f['q'].'%',$f['q'],'%'.$f['q'].'%');}
 if($f['financial']!=='')$where[]="(ep.fee_toman-(SELECT COALESCE(SUM(amount_toman),0) FROM physio_payments pay WHERE pay.episode_id=ep.id AND pay.voided=0))".($f['financial']==='debt'?'>0':'<=0');
 return [implode(' AND ',$where),$a];
}
function appointmentJoins():string{return ' FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id JOIN patients p ON p.pid=ep.pid LEFT JOIN visit_workflows w ON w.session_id=s.id ';}
function appointmentResults(array $f,int $page=1):array{
 [$where,$a]=appointmentWhere($f);$join=appointmentJoins();
 $count=(int)q('SELECT COUNT(*)'.$join.'WHERE '.$where,$a)->fetchColumn();$page=max(1,min($page,max(1,(int)ceil($count/50))));
 $rows=q("SELECT s.*,p.pid,p.fname,p.lname,ep.diagnosis legacy_diagnosis,w.state workflow_state,COALESCE(w.version,0) workflow_version,u.name therapist_name,vt.name visit_type_name,pk.name package_name,c.name clinic_name".$join."LEFT JOIN staff u ON u.id=s.therapist_id LEFT JOIN visit_types vt ON vt.id=s.visit_type_id LEFT JOIN packages pk ON pk.id=s.package_id LEFT JOIN clinics c ON c.id=s.clinic_id WHERE $where ORDER BY s.starts_at,s.id LIMIT 50 OFFSET ".(($page-1)*50),$a)->fetchAll();
 [$allWhere,$allArgs]=appointmentWhere($f,false);
 $stats=q('SELECT COALESCE(w.state,\'scheduled\') state,COUNT(*) n,COUNT(DISTINCT p.pid) patients'.$join.'WHERE '.$allWhere.' GROUP BY COALESCE(w.state,\'scheduled\')',$allArgs)->fetchAll();
 $patients=(int)q('SELECT COUNT(DISTINCT p.pid)'.$join.'WHERE '.$allWhere,$allArgs)->fetchColumn();
 return compact('rows','count','page','stats','patients');
}
function appointmentClinicalAllowed(array $s):bool{return scope('forms.visit')==='all'||(scope('forms.visit')==='own'&&((int)$s['therapist_id']===(int)user()['id']||(int)$s['created_by']===(int)user()['id']));}

function appointmentCatalogSave(string $kind,int $id,string $name,bool $active):void{
 if(!allowed('services'))throw new DomainException('اجازه مدیریت فهرست‌ها را ندارید.');
 $table=match($kind){'visit_type'=>'visit_types','diagnosis'=>'diagnoses',default=>throw new DomainException('نوع فهرست معتبر نیست.')};
 if(trim($name)===''||mb_strlen($name)>($kind==='visit_type'?160:255))throw new DomainException('نام معتبر وارد کنید.');
 if($id){if(!q("SELECT id FROM $table WHERE id=?",[$id])->fetchColumn())throw new DomainException('مورد پیدا نشد.');q("UPDATE $table SET name=?,active=? WHERE id=?",[$name,(int)$active,$id]);}
 else {q("INSERT INTO $table(name,active) VALUES(?,?)",[$name,(int)$active]);$id=(int)q('SELECT LAST_INSERT_ID()')->fetchColumn();}
 audit('catalog_'.$kind.'_saved',$id);
}
function appointmentMetadata(int $id,int $version,array $input):void{
 global $db;
 schedulingLock();$owns=!$db->inTransaction();if($owns)$db->beginTransaction();
 try{
  $s=q('SELECT * FROM physio_sessions WHERE id=? FOR UPDATE',[$id])->fetch();if(!$s)throw new DomainException('نوبت پیدا نشد.');workflowAccess($s);
  workflowEnsure($s);$w=q('SELECT * FROM visit_workflows WHERE session_id=? FOR UPDATE',[$id])->fetch();
  if($version!==(int)$w['version'])throw new DomainException('وضعیت نوبت تغییر کرده است؛ صفحه را تازه کنید.');
  if(!in_array($w['state'],['scheduled','waiting','referred'],true))throw new DomainException('مشخصات مراجعه پس از شروع درمان قابل تغییر نیست.');
  $type=filter_var($input['visit_type_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
  $room=$input['room']??$s['room'];if($type===false||!is_string($room)||mb_strlen($room)>100)throw new DomainException('مشخصات معتبر نیست.');
  if($type!==(int)$s['visit_type_id']&&$type&&!q('SELECT id FROM visit_types WHERE id=? AND active=1',[$type])->fetchColumn())throw new DomainException('نوع ویزیت فعال انتخاب کنید.');
  if($room!==$s['room']&&$room!=='')validateResources($room,$s['equipment']);
  $pid=(int)q('SELECT pid FROM physio_episodes WHERE id=?',[$s['episode_id']])->fetchColumn();
  if(conflict($s['starts_at'],$s['ends_at'],$s['therapist_id'],$room,$s['equipment'],$pid,$id))throw new DomainException('اتاق یا زمان نوبت تداخل دارد.');
  $package=(int)$s['package_id'];
  if(array_key_exists('package_id',$input)){
   if(!allowed('finance.edit'))throw new DomainException('اجازه تغییر پکیج را ندارید.');
   $package=filter_var($input['package_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
   if($package===false||($package&&(int)$s['package_id']!==$package&&!q('SELECT id FROM packages WHERE id=? AND active=1 AND deleted=0',[$package])->fetchColumn()))throw new DomainException('پکیج فعال انتخاب کنید.');
  }
  $before=['visit_type_id'=>$s['visit_type_id'],'room'=>$s['room'],'package_id'=>$s['package_id']];$after=['visit_type_id'=>$type?:null,'room'=>$room,'package_id'=>$package?:null];
  q('UPDATE physio_sessions SET visit_type_id=?,room=?,package_id=? WHERE id=?',[$type?:null,$room,$package?:null,$id]);
  $event=['before_appointment'=>$before,'after_appointment'=>$after,'actor_name'=>user()['name']];
  foreach(['diagnoses'=>['session_diagnoses','diagnosis_id','diagnoses'],'labels'=>['session_labels','label_id','labels']] as $key=>[$table,$column,$catalog]){
   if(!array_key_exists($key,$input))continue;
   if($key==='diagnoses')workflowAccess($s,true);
   if(!is_array($input[$key])||count($input[$key])>30)throw new DomainException('فهرست انتخابی معتبر نیست.');
   $old=q("SELECT $column FROM $table WHERE session_id=? ORDER BY $column",[$id])->fetchAll(PDO::FETCH_COLUMN);
   $ids=[];foreach($input[$key] as $raw){$n=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($n===false||(!in_array($n,array_map('intval',$old),true)&&!q("SELECT id FROM $catalog WHERE id=? AND active=1",[$n])->fetchColumn()))throw new DomainException('مورد فعال انتخاب کنید.');$ids[$n]=$n;}
   q("DELETE FROM $table WHERE session_id=?",[$id]);foreach($ids as $n)q("INSERT INTO $table(session_id,$column) VALUES(?,?)",[$id,$n]);
   if($key==='labels')q('UPDATE physio_sessions SET label_id=? WHERE id=?',[array_values($ids)[0]??null,$id]);
   $event['before_'.$key]=$old;$event['after_'.$key]=array_values($ids);
  }
  q('UPDATE visit_workflows SET version=version+1,updated_at=NOW() WHERE session_id=?',[$id]);
  q("INSERT INTO visit_events(session_id,version,event_type,from_state,to_state,actor_id,source,details) VALUES(?,?,'appointment_updated',?,?,?,'staff',?)",[$id,$version+1,$w['state'],$w['state'],user()['id'],json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
  audit('appointment_details_updated',$id);if($owns)$db->commit();
 }catch(Throwable $ex){if($owns&&$db->inTransaction())$db->rollBack();throw $ex;}finally{schedulingUnlock();}
}
