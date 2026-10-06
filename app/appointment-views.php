<?php
function appointmentDashboard():void{
 need('appointments');
 try{$f=appointmentFilters($_GET);$r=appointmentResults($f,max(1,(int)($_GET['p']??1)));}catch(DomainException $ex){layout('appointments','نوبت‌ها');echo '<p class="notice">'.e($ex->getMessage()).'</p>';endLayout();return;}
 $display=displayEffective();
 layout('appointments','میز پذیرش · نوبت‌های مراجعین','',true);
 $url=static fn(array $changes)=>'/appointments?'.http_build_query(array_replace($f,$changes));
 echo '<div class="reception-toolbar"><a class="button primary" href="/appointment/new">'.icon('plus').' ثبت / نوبت‌دهی</a>';
 if(can('patients'))echo '<a class="button secondary" href="/patients">'.icon('users').' تمامی مراجعین</a>';
 if(displayVisible($display,'panels','filters'))echo '<a class="button secondary" href="#reception-search">'.icon('search').' جستجوی سریع</a>';
 echo '<a class="button secondary refresh-link" href="'.e($url([])).'">بارگذاری مجدد</a></div>';
 echo '<div class="reception-datebar"><strong>برنامه مراجعین</strong><div class="reception-days">';
 foreach([-1=>'روز قبل',1=>'روز بعد'] as $step=>$label){
  $next=['from'=>date('Y-m-d',strtotime($f['from'].' '.($step>0?'+':'').$step.' day')),'to'=>date('Y-m-d',strtotime($f['to'].' '.($step>0?'+':'').$step.' day'))];
  if($step===1)echo '<span class="reception-current-date">'.icon('calendar').jd($f['from'],'EEEE، d MMMM yyyy').($f['to']!==$f['from']?' تا '.jd($f['to']):'').'</span>';
  echo '<a class="button secondary small" href="'.e($url($next)).'">'.$label.'</a>';
 }
 echo '<a class="text-link" href="'.e($url(['from'=>date('Y-m-d'),'to'=>date('Y-m-d')])).'">امروز</a></div><a class="button secondary small" href="/display">'.icon('settings').' تنظیم نمایش</a></div>';
 echo '<div class="reception-grid"><div class="reception-content">';
 if(displayVisible($display,'panels','filters')){
  echo '<section class="panel reception-filters"><form method="get" id="reception-filters"><div class="reception-primary-filters">';
  selectfield('visit_type','نوع ویزیت',['0'=>'همه ویزیت‌ها']+options('visit_types','active=1'),$f['visit_type']);
  selectfield('room','اتاق',[''=>'همه اتاق‌ها']+array_column(q("SELECT DISTINCT room FROM physio_sessions WHERE room<>'' UNION SELECT name room FROM resources WHERE kind='room'")->fetchAll(),'room','room'),$f['room']);
  selectfield('therapist','درمانگر',['0'=>'همه درمانگران']+array_filter(therapists()),$f['therapist']);
  echo '<button class="button primary small" type="submit">اعمال فیلتر</button></div><div class="reception-status-tabs" aria-label="مرحله مراجعه">';
  foreach([''=>'همه']+workflowLabels() as $key=>$label)echo '<a class="status-tab '.($f['state']===$key?'selected':'').'" '.($f['state']===$key?'aria-current="true"':'').' href="'.e($url(['state'=>$key])).'">'.e($label).'</a>';
  echo '</div>';
  hidden('state',$f['state']);
  $advanced=false;foreach(['service','package','label','diagnosis','presence','patient_type','clinic','financial'] as $key)if(!empty($f[$key]))$advanced=true;
  if($f['from']!==$f['to'])$advanced=true;
  echo '<details class="advanced-filters" '.($advanced?'open':'').'><summary>تاریخ و فیلترهای بیشتر <span>بازه، خدمت، پکیج و مالی</span></summary><div class="form-grid three">';
  field('from','از تاریخ',$f['from'],'date');field('to','تا تاریخ',$f['to'],'date');
  selectfield('service','خدمت / نوع درمان',['0'=>'همه']+options('services','deleted=0'),$f['service']);
  selectfield('package','پکیج',['0'=>'همه']+options('packages','deleted=0'),$f['package']);
  selectfield('label','هشتگ',['0'=>'همه']+options('labels','deleted=0'),$f['label']);
  if(scope('forms.visit')!=='none')selectfield('diagnosis','تشخیص',['0'=>'همه']+options('diagnoses','active=1'),$f['diagnosis']);
  selectfield('presence','حضور',[''=>'همه','present'=>'وارد شده','not_arrived'=>'ورود ثبت نشده'],$f['presence']);
  selectfield('patient_type','سابقه مراجعه',[''=>'همه','new'=>'بدون جلسه انجام‌شده قبلی','returning'=>'دارای جلسه انجام‌شده قبلی'],$f['patient_type']);
  selectfield('clinic','کلینیک',['0'=>'همه']+options('clinics','active=1'),$f['clinic']);
  if(allowed('finance.debt'))selectfield('financial','مالی دوره',[''=>'همه','debt'=>'دارای بدهی','settled'=>'بدون بدهی دوره'],$f['financial']);
  echo '</div></details></form></section>';
 }
 $stats=array_fill_keys(array_keys(workflowLabels()),0);foreach($r['stats'] as $item)$stats[$item['state']]=(int)$item['n'];
 if(displayVisible($display,'panels','stats')){
  echo '<section class="reception-summary" aria-label="آمار بازه انتخاب‌شده"><span class="summary-chip"><strong>'.fa($r['patients']).'</strong> مراجع یکتا</span><a class="summary-chip" href="'.e($url(['state'=>''])).'"><strong>'.fa(array_sum($stats)).'</strong> تمام نوبت‌ها</a>';
  foreach(['absent'=>'غایب','waiting'=>'در انتظار','cancelled'=>'کنسل‌شده','in_service'=>'در حال ویزیت','visited'=>'ویزیت‌شده','discharged'=>'ترخیصی'] as $k=>$title)
   echo '<a class="summary-chip state-'.e($k).'" href="'.e($url(['state'=>$k])).'"><strong>'.fa($stats[$k]).'</strong> '.e($title).'</a>';
  echo '</section>';
 }
 if(displayVisible($display,'panels','labels')){
  echo '<div class="reception-labels" aria-label="هشتگ‌های مراجعین">';
  foreach(q('SELECT id,name FROM labels WHERE active=1 AND deleted=0 ORDER BY name')->fetchAll() as $l)
   echo '<a class="badge '.((int)$f['label']===(int)$l['id']?'selected':'').'" href="'.e($url(['label'=>(int)$f['label']===(int)$l['id']?0:$l['id']])).'">#'.e($l['name']).'</a>';
  echo '</div>';
 }
 if(displayVisible($display,'panels','filters')){
  echo '<div class="reception-list-search"><label for="reception-search">'.icon('search').' جستجو در نوبت‌ها</label><input id="reception-search" name="q" type="search" form="reception-filters" value="'.e($f['q']).'" placeholder="نام، موبایل یا کد پرونده"><button type="submit" form="reception-filters" class="button secondary small">جستجو</button><a class="text-link" href="/appointments">پاک‌کردن فیلترها</a></div>';
 }
 $columns=array_keys(array_filter($display['columns'],static fn($item)=>$item['visible']));
 echo '<section class="panel reception-list"><div class="panel-header"><h2>فهرست مراجعه</h2><span>'.fa($r['count']).' نوبت</span></div>';
 if(!$columns)echo '<p class="notice">ستونی برای نمایش انتخاب نشده است. از تنظیم نمایش ستون‌ها را انتخاب کنید.</p>';
 echo '<div class="table-scroll"><table><thead><tr>';
 foreach($columns as $key)echo '<th data-column="'.e($key).'">'.e(displayOptions()['columns'][$key]).'</th>';
 echo '</tr></thead><tbody>';
 foreach($r['rows'] as $s){$state=$s['workflow_state']??workflowInitialState($s);echo '<tr class="visit-row state-'.e($state).'">';foreach($columns as $key){echo '<td data-column="'.e($key).'">';appointmentCell($key,$s,$display);echo '</td>';}echo '</tr>';}
 echo '</tbody></table></div>';if(!$r['rows'])emptyState('نوبتی با این فیلترها پیدا نشد','بازه تاریخ یا فیلترها را تغییر دهید.');echo '<div class="form-actions">';
 if($r['page']>1)echo '<a class="button secondary" href="'.e('/appointments?'.http_build_query($f+['p'=>$r['page']-1])).'">صفحه قبل</a>';
 echo '<span>صفحه '.fa($r['page']).'</span>';
 if($r['page']*50<$r['count'])echo '<a class="button secondary" href="'.e('/appointments?'.http_build_query($f+['p'=>$r['page']+1])).'">صفحه بعد</a>';
 echo '</div></section></div>';
 if(displayVisible($display,'panels','shortcuts')){
  echo '<aside class="reception-tools" aria-label="دسترسی سریع"><section class="panel"><h2>دسترسی سریع</h2><a href="/availability">'.icon('calendar').' زمان‌های آزاد</a><a href="/turn-board">'.icon('users').' تابلو انتظار</a>';
  if(allowed('reports.clinical'))echo '<a href="/reports/clinical">'.icon('chart').' گزارش عملکرد</a>';
  if(allowed('appointments.timing'))echo '<a href="/reports/timing">'.icon('chart').' زمان مراجعه</a>';
  if(allowed('services'))echo '<a href="/appointment/catalogs">نوع ویزیت و تشخیص</a><a href="/resources">اتاق‌ها و تجهیزات</a>';
  echo '</section><section class="panel"><h2>راهنمای پذیرش</h2><p>برای ثبت ورود، انتظار یا ارسال به درمان، «گردش مراجعه» را در ردیف بیمار باز کنید.</p></section></aside>';
 }
 echo '</div>';endLayout();
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

function appointmentCell(string $key,array $s,array $display):void{
 switch($key){
  case 'patient':
   echo '<a href="/patient?id='.(int)$s['pid'].'">'.e(patientName($s)).'</a><small>پرونده '.fa($s['pid']).' · '.e($s['visit_type_name']??'نوع ویزیت تعیین نشده').'</small>';
   foreach(q('SELECT l.name FROM labels l WHERE l.id=? OR EXISTS(SELECT 1 FROM session_labels sl WHERE sl.session_id=? AND sl.label_id=l.id)',[$s['label_id'],$s['id']])->fetchAll() as $label)echo '<span class="badge">#'.e($label['name']).'</span>';
   echo '<div class="patient-row-tools">';
   if(can('finance'))echo '<a class="settlement-link" href="/finance?episode='.(int)$s['episode_id'].'" aria-label="تسویه حساب '.e(patientName($s)).'" title="تسویه حساب">$</a>';
   receptionEditor($s,'labels','هشتگ‌ها');receptionEditor($s,'notes','توضیحات پذیرش');echo '</div>';
   break;

  case 'time': echo jd($s['starts_at'],'yyyy/MM/dd HH:mm').'<small>تا '.jd($s['ends_at'],'HH:mm').'</small>';break;
  case 'therapist': echo e($s['therapist_name']);if(displayVisible($display,'panels','room'))echo '<small>'.e($s['room']?:'بدون اتاق اختصاصی').'</small>';break;
  case 'state': $state=$s['workflow_state']??workflowInitialState($s);echo '<a class="visit-state state-'.e($state).'" href="/visit?id='.(int)$s['id'].'">'.e(workflowLabels()[$state]).'</a>';break;
  case 'diagnoses': receptionEditor($s,'diagnoses','تشخیص‌ها');break;
  case 'actions':
   receptionEditor($s,'packages','پکیج‌های درمانی');echo '<div class="actions"><a class="button secondary small" href="/visit?id='.(int)$s['id'].'">گردش مراجعه</a><a class="button secondary small" href="/appointment/new?pid='.(int)$s['pid'].'&amp;previous='.(int)$s['id'].'">نوبت بعدی</a></div><details><summary>بیشتر</summary><div class="actions">';
   echo '<a href="/patient?id='.(int)$s['pid'].'">اطلاعات کامل و مدارک</a><a href="/appointment/details?id='.(int)$s['id'].'">مشخصات مراجعه</a>';
   if(allowed('patients.edit')&&patientAllowed($s['pid'],'contact'))echo '<a href="/patients/edit?id='.(int)$s['pid'].'">ویرایش مراجعه‌کننده</a>';
   if(appointmentClinicalAllowed($s))echo '<a href="/session?id='.(int)$s['id'].'">فرم ویزیت</a>';
   if(allowed('finance.debt')||allowed('finance.history'))echo '<a href="/finance?episode='.(int)$s['episode_id'].'">تراز مالی</a>';
   if(can('episodes'))echo '<a href="/episode?id='.(int)$s['episode_id'].'">طرح درمان</a><a href="/progress?pid='.(int)$s['pid'].'">روند درمان</a>';
   if(allowed('finance.debt')||allowed('finance.edit'))echo '<a href="/billing?episode='.(int)$s['episode_id'].'">صورتحساب</a>';
   if(allowed('sms'))echo '<a href="/sms/audience?pid='.(int)$s['pid'].'">پیش‌نمایش پیامک</a>';
   echo '<a href="/visit?id='.(int)$s['id'].'">تاریخچه، اصلاح مرحله و چاپ</a></div></details>';break;
 }
}
