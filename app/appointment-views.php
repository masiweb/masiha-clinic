<?php
function appointmentDashboard():void{
 need('appointments');
 try{$f=appointmentFilters($_GET);$r=appointmentResults($f,max(1,(int)($_GET['p']??1)));}catch(DomainException $ex){layout('appointments','نوبت‌ها');echo '<p class="notice">'.e($ex->getMessage()).'</p>';endLayout();return;}
 $display=displayEffective();
 layout('appointments','میز پذیرش · نوبت‌های مراجعین','',true);
 $url=static fn(array $changes)=>'/appointments?'.http_build_query(array_replace($f,$changes));
 echo '<div class="reception-toolbar" aria-label="اقدامات سریع پذیرش"><a class="button primary reception-create" href="/appointment/new">'.icon('plus').' ثبت / نوبت‌دهی</a>';
 if(can('patients'))echo '<a class="button secondary" href="/patients">'.icon('users').' تمامی مراجعین</a>';
 if(displayVisible($display,'panels','filters'))echo '<a class="button secondary" href="#reception-search">'.icon('search').' جستجوی سریع</a>';
 if((user()['role']??'')==='admin'&&allowed('sms'))echo '<a class="button secondary" href="/sms/appointment-notices">اعلان یا لغو گروهی نوبت‌ها</a>';
 echo '<a class="button secondary refresh-link" href="'.e($url([])).'">بارگذاری مجدد</a></div>';
 echo '<div class="reception-datebar"><strong>برنامه مراجعین</strong><div class="reception-days">';
 foreach([-1=>'روز قبل',1=>'روز بعد'] as $step=>$label){
  $next=['from'=>date('Y-m-d',strtotime($f['from'].' '.($step>0?'+':'').$step.' day')),'to'=>date('Y-m-d',strtotime($f['to'].' '.($step>0?'+':'').$step.' day'))];
  if($step===1){
   echo '<form method="get" action="/appointments" class="reception-date-jump" data-reception-date-jump>';
   foreach($f as $k=>$value)if(!in_array($k,['from','to'],true))hidden($k,(string)$value);
   hidden('to',$f['from']);
   echo '<label for="reception-jump-date">'.icon('calendar').' انتخاب تاریخ مراجعه</label>';
   echo '<input id="reception-jump-date" type="date" name="from" value="'.e($f['from']).'" required aria-label="انتخاب تاریخ دلخواه از تقویم شمسی">';
   echo '<button type="submit" class="button secondary small reception-date-apply">نمایش نوبت‌ها</button>';
   echo '</form>';
  }
  echo '<a class="button secondary small" href="'.e($url($next)).'">'.$label.'</a>';
 }
 echo '<a class="text-link" href="'.e($url(['from'=>date('Y-m-d'),'to'=>date('Y-m-d')])).'">امروز</a></div><a class="button secondary small" href="/display">'.icon('settings').' تنظیم نمایش</a></div>';
 echo '<div class="reception-grid"><div class="reception-content">';
 if(displayVisible($display,'panels','filters')){
  echo '<section class="panel reception-filters"><form method="get" id="reception-filters"><div class="reception-primary-filters">';
  selectfield('room','اتاق',[''=>'همه اتاق‌ها']+array_column(q("SELECT DISTINCT room FROM physio_sessions WHERE room<>'' UNION SELECT name room FROM resources WHERE kind='room'")->fetchAll(),'room','room'),$f['room']);
  selectfield('therapist','درمانگر',['0'=>'همه درمانگران']+array_filter(therapists()),$f['therapist']);
  echo '<button class="button primary small" type="submit">اعمال فیلتر</button></div><div class="reception-status-tabs" aria-label="مرحله مراجعه">';
  foreach([''=>'همه']+workflowLabels() as $key=>$label)echo '<a class="status-tab '.($f['state']===$key?'selected':'').'" '.($f['state']===$key?'aria-current="true"':'').' href="'.e($url(['state'=>$key])).'">'.e($label).'</a>';
  echo '</div>';
  hidden('state',$f['state']);
  $advanced=false;foreach(['service','package','label','hashtag','diagnosis','presence','patient_type','clinic','financial'] as $key)if(!empty($f[$key]))$advanced=true;
  if($f['from']!==$f['to'])$advanced=true;
  echo '<details class="advanced-filters" '.($advanced?'open':'').'><summary>تاریخ و فیلترهای بیشتر <span>بازه، خدمت، پکیج و مالی</span></summary><div class="form-grid three">';
  field('from','از تاریخ',$f['from'],'date');field('to','تا تاریخ',$f['to'],'date');
  selectfield('service','خدمت / نوع درمان',['0'=>'همه']+options('services','deleted=0'),$f['service']);
  selectfield('package','پکیج',['0'=>'همه']+options('packages','deleted=0'),$f['package']);
  selectfield('label','لیبل',['0'=>'همه لیبل‌ها']+options('labels','deleted=0'),$f['label']);
  selectfield('hashtag','هشتگ',['0'=>'همه هشتگ‌ها']+options('hashtags','active=1'),$f['hashtag']);
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
  $labelFilters=$f;$labelFilters['label']=0;
  [$labelWhere,$labelArgs]=appointmentWhere($labelFilters,false);
  $labelCounts=q("SELECT l.id,l.name,l.color,COUNT(*) n
    FROM labels l JOIN physio_sessions s ON s.label_id=l.id
    JOIN physio_episodes ep ON ep.id=s.episode_id
    JOIN patients p ON p.pid=ep.pid LEFT JOIN visit_workflows w ON w.session_id=s.id
    WHERE l.active=1 AND l.deleted=0 AND ".$labelWhere."
    GROUP BY l.id,l.name,l.color ORDER BY n DESC,l.name",$labelArgs)->fetchAll();
  if($labelCounts){
   echo '<nav class="reception-label-stats" aria-label="آمار لیبل نوبت‌ها">';
   foreach($labelCounts as $row){
    $selected=(int)$f['label']===(int)$row['id'];
    echo '<a class="reception-label-stat '.($selected?'selected':'').'" style="--label-color:'.e($row['color']).'"
      href="'.e($url(['label'=>$selected?0:(int)$row['id']])).'">'
      .e($row['name']).' <strong>'.fa($row['n']).'</strong></a>';
   }
   echo '</nav>';
  }
 }
 if(displayVisible($display,'panels','labels')){
  echo '<div class="reception-labels" aria-label="هشتگ‌های مراجعین">';
  foreach(q('SELECT id,name,color FROM hashtags WHERE active=1 ORDER BY name')->fetchAll() as $tag)
   echo '<a class="badge '.((int)$f['hashtag']===(int)$tag['id']?'selected':'').'" data-hashtag-color="'.e($tag['color']).'" style="--hashtag-color:'.e(preg_match('/^#[0-9a-fA-F]{6}$/D',(string)$tag['color'])?$tag['color']:'#935475').'" href="'.e($url(['hashtag'=>(int)$f['hashtag']===(int)$tag['id']?0:$tag['id']])).'">#'.e($tag['name']).'</a>';
  echo '</div>';
 }
 if(displayVisible($display,'panels','filters')){
  echo '<div class="reception-list-search"><label for="reception-search">'.icon('search').' جستجو در نوبت‌ها</label><input id="reception-search" name="q" type="search" form="reception-filters" value="'.e($f['q']).'" placeholder="نام، موبایل یا کد پرونده"><button type="submit" form="reception-filters" class="button secondary small">جستجو</button><a class="text-link" href="/appointments">پاک‌کردن فیلترها</a></div>';
 }
 // The /appointments reception list always displays the five requested columns.
 $columns=['patient','time','therapist','state','diagnoses'];
 echo '<section class="panel reception-list"><div class="panel-header"><h2>فهرست مراجعه</h2><span>'.fa($r['count']).' نوبت</span></div>';
 if(!$columns)echo '<p class="notice">ستونی برای نمایش انتخاب نشده است. از تنظیم نمایش ستون‌ها را انتخاب کنید.</p>';
 echo '<div class="table-scroll"><table><thead><tr>';
 foreach($columns as $key)echo '<th data-column="'.e($key).'">'.e(displayOptions()['columns'][$key]).'</th>';
 echo '</tr></thead><tbody>';
 foreach($r['rows'] as $s){$state=$s['workflow_state']??workflowInitialState($s);echo '<tr class="visit-row state-'.e($state).'" data-session-id="'.(int)$s['id'].'">';foreach($columns as $key){echo '<td data-column="'.e($key).'">';appointmentCell($key,$s,$display);echo '</td>';}echo '</tr>';}
 echo '</tbody></table></div>';if(!$r['rows'])emptyState('نوبتی با این فیلترها پیدا نشد','بازه تاریخ یا فیلترها را تغییر دهید.');echo '<div class="form-actions">';
 if($r['page']>1)echo '<a class="button secondary" href="'.e('/appointments?'.http_build_query($f+['p'=>$r['page']-1])).'">صفحه قبل</a>';
 echo '<span>صفحه '.fa($r['page']).'</span>';
 if($r['page']*50<$r['count'])echo '<a class="button secondary" href="'.e('/appointments?'.http_build_query($f+['p'=>$r['page']+1])).'">صفحه بعد</a>';
 echo '</div></section></div>';
 if(displayVisible($display,'panels','shortcuts')){
  echo '<aside class="reception-tools" aria-label="دسترسی سریع"><section class="panel"><h2>دسترسی سریع</h2><a href="/availability">'.icon('calendar').' زمان‌های آزاد</a><a href="/turn-board">'.icon('users').' تابلو انتظار</a>';
  if(allowed('reports.clinical'))echo '<a href="/reports/clinical">'.icon('chart').' گزارش عملکرد</a>';
  if(allowed('appointments.timing'))echo '<a href="/reports/timing">'.icon('chart').' زمان مراجعه</a>';
  if(allowed('services'))echo '<a href="/appointment/catalogs">تشخیص‌ها و برنامه‌های درمانی</a><a href="/resources">اتاق‌ها و تجهیزات</a>';
  echo '</section><section class="panel"><h2>راهنمای پذیرش</h2><p>برای ثبت ورود، انتظار یا ارسال به درمان، «گردش مراجعه» را در ردیف بیمار باز کنید.</p></section></aside>';
 }
 echo '</div>';endLayout();
}
function appointmentCatalogView():void{
 need('services');layout('services','تشخیص‌ها و برنامه‌های درمانی','موارد غیرفعال در سابقه حفظ می‌شوند.');
 foreach(['diagnosis'=>['diagnoses','تشخیص'],'program'=>['treatment_programs','برنامه درمانی']] as $kind=>[$table,$title]){
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
 hidden('visit_type_id',$s['visit_type_id']??0);
 selectfield('room','اتاق',[''=>'بدون اتاق اختصاصی']+array_column(q("SELECT name FROM resources WHERE kind='room' AND active=1")->fetchAll(),'name','name')+($s['room']!==''?[$s['room']=>$s['room']]:[]),$s['room']);
 if(allowed('finance.edit'))selectfield('package_id','پکیج',['0'=>'بدون پکیج']+options('packages','(active=1 AND deleted=0) OR id='.(int)$s['package_id']),$s['package_id']??0);
 echo '</div>';
 foreach(['hashtags'=>['hashtags','session_hashtags','hashtag_id','هشتگ‌ها'],'diagnoses'=>['diagnoses','session_diagnoses','diagnosis_id','تشخیص‌ها']] as $key=>[$catalog,$table,$column,$title]){
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
   echo '<div class="reception-patient-heading">'.patientPhotoAvatar($s).'<a href="/patient?id='.(int)$s['pid'].'">'.e(patientName($s)).'</a>';
   if(allowed('finance.debt')||allowed('finance.history')||allowed('finance.edit'))echo '<a class="settlement-link" href="/patient/finance?pid='.(int)$s['pid'].'" aria-label="امور مالی بیمار '.e(patientName($s)).'" title="تسویه حساب">$</a>';
   echo '</div><small>پرونده '.fa($s['pid']).'</small>';
   // The label is a separate colored classification; it never becomes a hashtag.
   foreach(q('SELECT DISTINCT l.name,l.color FROM labels l WHERE l.deleted=0 AND (l.id=? OR EXISTS(SELECT 1 FROM session_labels sl WHERE sl.session_id=? AND sl.label_id=l.id)) ORDER BY l.name',[(int)($s['label_id']??0),(int)$s['id']])->fetchAll() as $label)
    echo '<span class="badge visit-patient-label" data-label-color="'.e($label['color']).'" title="لیبل مراجعه">'.e($label['name']).'</span>';
   echo '<div class="reception-tag-line">';
   receptionEditor($s,'hashtags','هشتگ‌های مراجعه');
   echo '<div class="reception-tag-values">';
   foreach(q('SELECT h.name,h.color FROM hashtags h JOIN session_hashtags sh ON sh.hashtag_id=h.id WHERE sh.session_id=? ORDER BY h.name',[$s['id']])->fetchAll() as $tag)
    echo '<span class="badge visit-hashtag" data-hashtag-color="'.e($tag['color']).'">#'.e($tag['name']).'</span>';
   echo '</div></div>';
   echo '<div class="reception-admission-note">';
   receptionEditor($s,'notes','ویرایش توضیح پذیرش');
   echo '<div class="reception-admission-text">'.(trim($s['reception_notes']??'')!==''?nl2br(e($s['reception_notes'])):'توضیح پذیرش ثبت نشده').'</div></div>';
   break;

  case 'time': echo '<div class="reception-visit-upper">';$minutes=max(0,(int)ceil((strtotime($s['ends_at'])-strtotime($s['starts_at']))/60));echo '<strong class="reception-time">'.jd($s['starts_at'],'HH:mm').'</strong><small class="reception-duration">مدت ویزیت: '.fa($minutes).' دقیقه</small><small>'.jd($s['starts_at'],'yyyy/MM/dd').'</small>';appointmentInlineLabel($s);echo '</div>';receptionVisitZone($s,'diagnoses');break;
  case 'therapist': echo '<div class="reception-visit-upper"><span class="reception-therapist">'.icon('users').e($s['therapist_name']).'</span>';if(displayVisible($display,'panels','room'))echo '<small>'.e($s['room']?:'بدون اتاق اختصاصی').'</small>';echo '</div>';receptionVisitZone($s,'treatment');break;
  case 'state':
   echo '<div class="reception-visit-upper">';
   $state=$s['workflow_state']??workflowInitialState($s);$next=array_values(array_diff(workflowNextStates()[$state],['visited']));
   echo '<div class="visit-state-stack"><span class="visit-state state-'.e($state).'">'.e(workflowLabels()[$state]).'</span>';
   if($next&&allowed('appointments')&&(user()['role']!=='therapist'||(int)$s['therapist_id']===(int)user()['id'])){
    echo '<form method="post" class="visit-select-form">';csrf();hidden('action','visit_state');hidden('session_id',$s['id']);hidden('workflow_version',$s['workflow_version']);hidden('return_appointments',1);hidden('clinic_id',0);
    selectfield('state','',[$state=>workflowLabels()[$state]]+array_intersect_key(workflowLabels(),array_flip($next)),$state);echo '</form>';
   }
   echo '<a class="text-link" href="/visit?id='.(int)$s['id'].'">تاریخچه و گردش مراجعه</a></div></div>';receptionVisitZone($s,'therapist');break;
  case 'diagnoses':
   if(appointmentClinicalAllowed($s)){receptionDiagnosisSummary($s);}
   else echo '<span class="row-selection-text">—</span>';
   appointmentQuickActions($s);
   break;
  case 'actions': appointmentQuickActions($s);break;

 }
}


/** All row operations stay under the primary-diagnosis column. */
function appointmentQuickActions(array $s):void{
 $id=(int)$s['id'];$pid=(int)$s['pid'];$version=(int)($s['workflow_version']??0);
 $state=$s['workflow_state']??workflowInitialState($s);
 $canManage=allowed('appointments')&&(user()['role']!=='therapist'||(int)$s['therapist_id']===(int)user()['id']);
 echo '<div class="visit-quick-actions" role="group" aria-label="عملیات مراجعه '.e(patientName($s)).'">';
 if($canManage&&in_array($state,['scheduled','referred'],true)){
  echo '<form method="post" class="visit-inline-form">';csrf();hidden('action','visit_state');hidden('session_id',$id);hidden('workflow_version',$version);hidden('state','waiting');hidden('return_appointments',1);
  echo '<button type="submit" class="row-action-icon" title="ثبت حاضری" aria-label="ثبت حاضری">'.icon('check').'</button></form>';
 }else echo '<span class="row-action-icon is-disabled" title="ثبت حاضری در این وضعیت امکان‌پذیر نیست" aria-label="ثبت حاضری غیرفعال">'.icon('check').'</span>';
 if($canManage&&in_array($state,['scheduled','referred','waiting'],true)){
  echo '<form method="post" class="visit-inline-form" data-confirm="این نوبت لغو شود؟ سوابق آن برای حسابرسی حفظ خواهد شد.">';csrf();hidden('action','visit_state');hidden('session_id',$id);hidden('workflow_version',$version);hidden('state','cancelled');hidden('return_appointments',1);
  echo '<button type="submit" class="row-action-icon is-danger" title="حذف نوبت (لغو با حفظ سابقه)" aria-label="حذف نوبت"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 14h10l1-14M10 11v6m4-6v6"/></svg></button></form>';
 }else echo '<span class="row-action-icon is-disabled" title="این نوبت قابل حذف نیست" aria-label="حذف نوبت غیرفعال"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 14h10l1-14M10 11v6m4-6v6"/></svg></span>';
 if(allowed('inventory')||allowed('inventory.consume'))echo '<a class="row-action-icon" title="ثبت کالاهای مصرفی" aria-label="ثبت کالاهای مصرفی" href="/visit?id='.$id.'#stock-consumption">'.icon('box').'</a>';
 else echo '<span class="row-action-icon is-disabled" title="مجوز ثبت کالاهای مصرفی ندارید" aria-label="ثبت کالاهای مصرفی غیرفعال">'.icon('box').'</span>';
 echo '<details class="visit-more-menu"><summary class="row-action-icon" title="بیشتر" aria-label="عملیات بیشتر">'.icon('menu').'</summary><div class="visit-more-links">';
 if(allowed('appointments.reverse')&&in_array($state,['referred','waiting','in_service'],true)){
  echo '<form method="post" class="visit-step-back-form">';csrf();hidden('action','visit_step_back');hidden('session_id',$id);hidden('workflow_version',$version);
  echo '<button type="submit" class="visit-more-action">یک مرحله قبل (فقط وضعیت بیمار)</button></form>';
 }else echo '<span class="visit-more-action is-disabled" title="مرحله قبلی در این وضعیت قابل بازگشت نیست">یک مرحله قبل (فقط وضعیت بیمار)</span>';
 echo '<a href="/patient?id='.$pid.'">اطلاعات کامل و مدارک</a><a href="/appointment/details?id='.$id.'">مشخصات مراجعه</a>';
 if(allowed('patients.edit')&&patientAllowed($pid,'contact'))echo '<a href="/patients/edit?id='.$pid.'">ویرایش مراجعه‌کننده</a>';
 if(appointmentClinicalAllowed($s))echo '<a href="/session?id='.$id.'">فرم ویزیت و توضیحات درمانگر</a>';
 if(allowed('finance.debt')||allowed('finance.history'))echo '<a href="/patient/finance?pid='.$pid.'">تراز مالی بیمار</a>';
 if(can('episodes'))echo '<a href="/episode?id='.(int)$s['episode_id'].'">طرح درمان</a><a href="/progress?pid='.$pid.'">روند درمان</a>';
 if(allowed('finance.debt')||allowed('finance.edit'))echo '<a href="/billing?episode='.(int)$s['episode_id'].'">صورتحساب</a>';
 if(allowed('sms'))echo '<a href="/sms/audience?pid='.$pid.'">پیش‌نمایش پیامک</a>';
 echo '<a href="/appointment/new?pid='.$pid.'&amp;previous='.$id.'">نوبت بعدی</a>';
 echo '<a href="/visit?id='.$id.'">تاریخچه، اصلاح مرحله و چاپ</a>';
 echo '</div></details></div>';
}


function appointmentInlineLabel(array $s):void{
 $id=(int)$s['id'];$current=(int)($s['label_id']??0);
 if(!$current)$current=(int)q('SELECT label_id FROM session_labels WHERE session_id=? ORDER BY label_id LIMIT 1',[$id])->fetchColumn();
 static $choices=null;
 if($choices===null)$choices=q('SELECT id,name,color FROM labels WHERE active=1 AND deleted=0 ORDER BY name')->fetchAll();
 $editable=allowed('appointments')&&(user()['role']!=='therapist'||(int)$s['therapist_id']===(int)user()['id']);
 echo '<div class="visit-time-label">';
 if($editable&&$choices){
  echo '<form method="post" class="visit-select-form">';
  csrf();hidden('action','reception_update');hidden('kind','labels');hidden('session_id',$id);hidden('workflow_version',(int)($s['workflow_version']??0));
  echo '<select name="selection[]" class="visit-inline-label" data-auto-save-select aria-label="لیبل مراجعه">';
  if(!$current)echo '<option selected disabled value="">انتخاب لیبل</option>';
  foreach($choices as $choice)echo '<option value="'.(int)$choice['id'].'" '.((int)$choice['id']===$current?'selected':'').'>'.e($choice['name']).'</option>';
  echo '</select></form>';
 }else{
  $name=(string)q('SELECT name FROM labels WHERE id=?',[$current])->fetchColumn();
  echo '<span class="visit-label-static">'.e($name?:'لیبل انتخاب نشده').'</span>';
 }
 echo '</div>';
}


/** Lower zones within time, therapist and status columns. */
function receptionVisitZone(array $s,string $zone):void{
 $id=(int)$s['id'];
 $titles=['diagnoses'=>'۱ · تشخیص‌ها','treatment'=>'۲ · پکیج و برنامه درمانی','therapist'=>'۳ · توضیحات درمانگر'];
 echo '<section class="reception-visit-zone reception-visit-zone-'.e($zone).'"><strong class="reception-zone-heading">'.e($titles[$zone]).'</strong>';
 if($zone==='diagnoses'){
  if(appointmentClinicalAllowed($s))receptionEditor($s,'diagnoses','انتخاب تشخیص');
  else echo '<span class="muted">بدون دسترسی به تشخیص</span>';
 }elseif($zone==='treatment'){
  receptionEditor($s,'packages','پکیج‌ها');
  if(appointmentClinicalAllowed($s))receptionEditor($s,'programs','برنامه‌های درمانی');
  receptionTreatmentPlanEditor($s);
 }else{
  $note=trim((string)($s['notes']??''));
  echo '<div class="reception-therapist-notes">'.($note!==''?nl2br(e($note)):'<span class="muted">توضیح درمانگر ثبت نشده</span>').'</div>';
  if(appointmentClinicalAllowed($s)&&can('episodes'))
   echo '<a class="reception-therapist-edit" href="/session?id='.$id.'" title="ویرایش توضیحات درمانگر">'.icon('pen').' ویرایش توضیحات</a>';
 }
 echo '</section>';
}
function receptionDiagnosisSummary(array $s):void{
 $names=q('SELECT d.name FROM diagnoses d JOIN session_diagnoses sd ON sd.diagnosis_id=d.id WHERE sd.session_id=? ORDER BY d.name',[$s['id']])->fetchAll(PDO::FETCH_COLUMN);
 if(!$names&&trim((string)($s['legacy_diagnosis']??''))!=='')$names=[$s['legacy_diagnosis']];
 echo '<div class="reception-diagnosis-summary">'.e(implode('، ',$names)?:'—').'</div>';
}
function receptionTreatmentPlanEditor(array $s):void{
 $id=(int)$s['id'];$content=(string)(q('SELECT plan_text FROM session_treatment_plans WHERE session_id=?',[$id])->fetchColumn()?:'');
 echo '<div class="reception-plan-summary">'.($content!==''?nl2br(e($content)):'<small class="muted">برنامه اختصاصی ثبت نشده</small>').'</div>';
 if(!appointmentClinicalAllowed($s)||!receptionDiagnosisEdit($s))return;
 echo '<details class="row-editor editor-plan" data-row-editor><summary class="reception-plan-trigger">'.icon('pen').' ثبت برنامه اختصاصی</summary>';
 echo '<form method="post" class="row-editor-body">';csrf();hidden('action','reception_update');hidden('kind','plan');hidden('session_id',$id);hidden('workflow_version',(int)($s['workflow_version']??0));
 echo '<label for="plan-'.$id.'">شرح برنامه درمانی</label><textarea id="plan-'.$id.'" name="treatment_plan" rows="4" maxlength="5000">'.e($content).'</textarea><div class="editor-actions"><button class="button primary small" type="submit">ذخیره</button><button type="button" class="button secondary small" data-editor-cancel>انصراف</button></div></form></details>';
}
