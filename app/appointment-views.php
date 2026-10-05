<?php
function appointmentDashboard():void{
 need('appointments');
 try{$f=appointmentFilters($_GET);$r=appointmentResults($f,max(1,(int)($_GET['p']??1)));}catch(DomainException $ex){layout('appointments','نوبت‌ها');echo '<p class="notice">'.e($ex->getMessage()).'</p>';endLayout();return;}
 layout('appointments','نوبت‌های مراجعین','جستجو، پیگیری مراحل مراجعه و برنامه درمانگران');
 echo '<div class="actions"><a class="button primary" href="/appointment/new">ثبت نوبت</a><a class="button secondary" href="/availability">زمان‌های آزاد</a><a class="button secondary" href="/turn-board">تابلو امروز</a>';
 if(allowed('services'))echo '<a class="button secondary" href="/appointment/catalogs">نوع ویزیت و تشخیص‌ها</a><a class="button secondary" href="/resources">اتاق‌ها و تجهیزات</a>';
 echo '</div><section class="panel form-panel"><form method="get"><div class="form-grid">';
 field('from','از تاریخ',$f['from'],'date');field('to','تا تاریخ',$f['to'],'date');field('q','نام، موبایل یا کد پرونده',$f['q']);
 selectfield('therapist','درمانگر',['0'=>'همه']+array_filter(therapists()),$f['therapist']);
 selectfield('visit_type','نوع ویزیت',['0'=>'همه']+options('visit_types','active=1'),$f['visit_type']);
 selectfield('room','اتاق',[''=>'همه']+array_column(q("SELECT DISTINCT room FROM physio_sessions WHERE room<>'' UNION SELECT name room FROM resources WHERE kind='room'")->fetchAll(),'room','room'),$f['room']);
 selectfield('state','وضعیت',[''=>'همه']+workflowLabels(),$f['state']);
 selectfield('service','خدمت / نوع درمان',['0'=>'همه']+options('services','deleted=0'),$f['service']);
 selectfield('package','پکیج',['0'=>'همه']+options('packages','deleted=0'),$f['package']);
 selectfield('label','هشتگ',['0'=>'همه']+options('labels','deleted=0'),$f['label']);
 if(scope('forms.visit')!=='none')selectfield('diagnosis','تشخیص',['0'=>'همه']+options('diagnoses','active=1'),$f['diagnosis']);
 selectfield('presence','حضور',[''=>'همه','present'=>'وارد شده','not_arrived'=>'ورود ثبت نشده'],$f['presence']);
 selectfield('patient_type','سابقه مراجعه در سامانه',[''=>'همه','new'=>'بدون جلسه انجام‌شده قبلی','returning'=>'دارای جلسه انجام‌شده قبلی'],$f['patient_type']);
 selectfield('clinic','کلینیک',['0'=>'همه']+options('clinics','active=1'),$f['clinic']);
 if(allowed('finance.debt'))selectfield('financial','مالی دوره',[''=>'همه','debt'=>'دارای بدهی','settled'=>'بدون بدهی دوره'],$f['financial']);
 echo '</div><div class="form-actions"><button class="button primary">اعمال فیلترها</button><a class="button secondary" href="/appointments">امروز / پاک‌کردن فیلترها</a></div></form></section>';
 $stats=array_fill_keys(array_keys(workflowLabels()),0);foreach($r['stats'] as $item)$stats[$item['state']]=(int)$item['n'];
 echo '<div class="stats-grid"><article class="stat-card"><span>مراجعین یکتای بازه</span><strong>'.fa($r['patients']).'</strong></article><article class="stat-card"><span>تمام نوبت‌های بازه</span><strong>'.fa(array_sum($stats)).'</strong></article>';
 foreach(['absent'=>'غایب در مطب','waiting'=>'در انتظار','cancelled'=>'کنسل‌شده','in_service'=>'در حال ویزیت','visited'=>'ویزیت‌شده','discharged'=>'ترخیصی'] as $k=>$title){$url='/appointments?'.http_build_query(array_replace($f,['state'=>$k]));echo '<a class="stat-card" href="'.e($url).'"><span>'.e($title).'</span><strong>'.fa($stats[$k]).'</strong></a>';}echo '</div>';
 echo '<div class="actions">';foreach(q('SELECT id,name FROM labels WHERE active=1 AND deleted=0 ORDER BY name')->fetchAll() as $l)echo '<a class="button secondary" href="'.e('/appointments?'.http_build_query(array_replace($f,['label'=>$l['id']]))).'">#'.e($l['name']).'</a>';echo '</div>';
 echo '<section class="panel"><div class="panel-header"><h2>فهرست مراجعه</h2><span>'.fa($r['count']).' نوبت</span></div><div class="table-scroll"><table><thead><tr><th>اطلاعات مراجعه</th><th>زمان نوبت</th><th>درمانگر</th><th>وضعیت بیمار</th><th>تشخیص‌ها</th><th>پکیج / عملیات</th></tr></thead><tbody>';
 foreach($r['rows'] as $s){
  echo '<tr><td><a href="/patient?id='.(int)$s['pid'].'">'.e(patientName($s)).'</a><small>پرونده '.fa($s['pid']).' · '.e($s['visit_type_name']??'نوع ویزیت تعیین نشده').'</small>';
  foreach(q('SELECT l.name FROM labels l WHERE l.id=? OR EXISTS(SELECT 1 FROM session_labels sl WHERE sl.session_id=? AND sl.label_id=l.id)',[$s['label_id'],$s['id']])->fetchAll() as $label)echo '<span class="badge">#'.e($label['name']).'</span>';
  echo '</td><td>'.jd($s['starts_at'],'yyyy/MM/dd HH:mm').'<small>تا '.jd($s['ends_at'],'HH:mm').'</small></td><td>'.e($s['therapist_name']).'<small>'.e($s['room']?:'بدون اتاق اختصاصی').'</small></td><td>'.e(workflowLabels()[$s['workflow_state']??workflowInitialState($s)]).'</td><td>';
  if(appointmentClinicalAllowed($s)){
   $diagnoses=q('SELECT d.name FROM session_diagnoses sd JOIN diagnoses d ON d.id=sd.diagnosis_id WHERE sd.session_id=? ORDER BY d.name',[$s['id']])->fetchAll(PDO::FETCH_COLUMN);
   echo e(implode('، ',$diagnoses)?:$s['legacy_diagnosis']);
  }else echo '—';
  echo '</td><td>'.e($s['package_name']??'—').'<div class="actions"><a class="button secondary small" href="/visit?id='.(int)$s['id'].'">گردش مراجعه</a><a class="button secondary small" href="/appointment/new?pid='.(int)$s['pid'].'">نوبت بعدی</a></div><details><summary>بیشتر</summary><div class="actions">';
  echo '<a href="/patient?id='.(int)$s['pid'].'">اطلاعات کامل و مدارک</a><a href="/appointment/details?id='.(int)$s['id'].'">مشخصات مراجعه</a>';
  if(allowed('patients.edit')&&patientAllowed($s['pid'],'contact'))echo '<a href="/patients/edit?id='.(int)$s['pid'].'">ویرایش مراجعه‌کننده</a>';
  if(appointmentClinicalAllowed($s))echo '<a href="/session?id='.(int)$s['id'].'">فرم ویزیت</a>';
  if(allowed('finance.debt')||allowed('finance.history'))echo '<a href="/finance?episode='.(int)$s['episode_id'].'">تراز مالی</a>';
  echo '</div></details></td></tr>';
 }
 echo '</tbody></table></div>';if(!$r['rows'])emptyState('نوبتی با این فیلترها پیدا نشد','بازه تاریخ یا فیلترها را تغییر دهید.');echo '<div class="form-actions">';
 if($r['page']>1)echo '<a class="button secondary" href="'.e('/appointments?'.http_build_query($f+['p'=>$r['page']-1])).'">صفحه قبل</a>';
 echo '<span>صفحه '.fa($r['page']).'</span>';
 if($r['page']*50<$r['count'])echo '<a class="button secondary" href="'.e('/appointments?'.http_build_query($f+['p'=>$r['page']+1])).'">صفحه بعد</a>';
 echo '</div></section>';endLayout();
}
function appointmentCatalogView():void{
 need('services');layout('services','نوع ویزیت و تشخیص‌ها','موارد غیرفعال در سابقه حفظ می‌شوند.');
 foreach(['visit_type'=>['visit_types','نوع ویزیت'],'diagnosis'=>['diagnoses','تشخیص']] as $kind=>[$table,$title]){
  echo '<section class="panel form-panel"><h2>'.e($title).'</h2>';
  foreach(q("SELECT * FROM $table ORDER BY name")->fetchAll() as $row){formstart('appointment_catalog');hidden('kind',$kind);hidden('id',$row['id']);field('name','عنوان',$row['name']);check('active','فعال',(bool)$row['active']);formend('ذخیره','/appointments');}
  formstart('appointment_catalog');hidden('kind',$kind);hidden('id',0);field('name','افزودن '.$title,'','text','',true);check('active','فعال',true);formend('افزودن','/appointments');echo '</section>';
 }
 endLayout();
}
function appointmentDetailsView():void{
 need('appointments');$id=(int)($_GET['id']??0);
 try{workflowTimeline($id);$s=q('SELECT * FROM physio_sessions WHERE id=?',[$id])->fetch();workflowAccess($s);}catch(DomainException $ex){http_response_code(403);echo e($ex->getMessage());return;}
 $version=(int)q('SELECT version FROM visit_workflows WHERE session_id=?',[$id])->fetchColumn();
 layout('appointments','مشخصات مراجعه');formstart('appointment_metadata');hidden('session_id',$id);hidden('workflow_version',$version);
 echo '<section class="panel form-panel"><div class="form-grid">';
 selectfield('visit_type_id','نوع ویزیت',['0'=>'تعیین نشده']+options('visit_types','active=1 OR id='.(int)$s['visit_type_id']),$s['visit_type_id']??0);
 selectfield('room','اتاق',[''=>'بدون اتاق اختصاصی']+array_column(q("SELECT name FROM resources WHERE kind='room' AND active=1")->fetchAll(),'name','name')+($s['room']!==''?[$s['room']=>$s['room']]:[]),$s['room']);
 if(allowed('finance.edit'))selectfield('package_id','پکیج',['0'=>'بدون پکیج']+options('packages','(active=1 AND deleted=0) OR id='.(int)$s['package_id']),$s['package_id']??0);
 echo '</div>';
 foreach(['labels'=>['labels','session_labels','label_id','هشتگ‌ها'],'diagnoses'=>['diagnoses','session_diagnoses','diagnosis_id','تشخیص‌ها']] as $key=>[$catalog,$table,$column,$title]){
  if($key==='diagnoses'&&((int)$s['therapist_id']!==(int)user()['id']||!can('episodes')))continue;
  $selected=array_map('intval',q("SELECT $column FROM $table WHERE session_id=?",[$id])->fetchAll(PDO::FETCH_COLUMN));
  hidden('set_'.$key,1);echo '<fieldset><legend>'.e($title).'</legend>';
  foreach(q("SELECT id,name FROM $catalog WHERE active=1 OR id IN (SELECT $column FROM $table WHERE session_id=".(int)$id.") ORDER BY name")->fetchAll() as $entry)echo '<label><input type="checkbox" name="'.$key.'[]" value="'.(int)$entry['id'].'" '.(in_array((int)$entry['id'],$selected,true)?'checked':'').'> '.e($entry['name']).'</label> ';
  echo '</fieldset>';
 }
 echo '</section>';formend('ذخیره با ثبت سابقه','/visit?id='.$id);endLayout();
}
