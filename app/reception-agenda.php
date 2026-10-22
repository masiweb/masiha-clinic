<?php
declare(strict_types=1);

/** Read-only, scoped, two-day appointment book. Every row obeys patient-level permissions. */
function receptionAgendaApi(string $path):void {
 if(!in_array($path,['/api/reception/agenda','/api/reception/agenda/cancel'],true))return;
 header('Content-Type: application/json; charset=utf-8');
 header('Cache-Control: private, no-store');
 try{
  demand('appointments');
  if($path==='/api/reception/agenda/cancel'){
   if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new DomainException('روش درخواست مجاز نیست.');
   $csrf=$_POST['csrf']??null;
   if(!is_string($csrf)||!hash_equals((string)($_SESSION['csrf']??''),$csrf)){
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'نشست یا کد امنیتی معتبر نیست.'],JSON_UNESCAPED_UNICODE);
    exit;
   }
   $id=filter_var($_POST['session_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $version=filter_var($_POST['workflow_version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
   if(!$id||$version===false||$version===null)throw new DomainException('شناسه یا نسخه نوبت معتبر نیست.');
   $record=q("SELECT s.id,s.episode_id,s.status,s.therapist_id,
       COALESCE(w.state,'scheduled') state,COALESCE(w.version,0) workflow_version
       FROM physio_sessions s LEFT JOIN visit_workflows w ON w.session_id=s.id
       WHERE s.id=?",[$id])->fetch();
   if(!$record)throw new DomainException('نوبت پیدا نشد.');
   workflowAccess(['episode_id'=>$record['episode_id'],'therapist_id'=>$record['therapist_id']]);
   if($record['status']!=='scheduled'||!in_array($record['state'],['scheduled','referred','waiting'],true))
    throw new DomainException('این نوبت در وضعیت فعلی قابل حذف نیست.');
   if((int)$record['workflow_version']!==$version)throw new DomainException('وضعیت نوبت تغییر کرده است؛ صفحه را تازه کنید.');
   workflowTransition((int)$id,'cancelled',['reason'=>'لغو نوبت از دفتر نوبت‌ها'],$version);
   MasihaSms::safely(fn()=>MasihaSms::cancelSession((int)$id));
   echo json_encode(['ok'=>true,'id'=>(int)$id,'message'=>'نوبت لغو شد و سابقه آن حفظ شد.'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
   exit;
  }
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new DomainException('فقط مشاهده تقویم مجاز است.');
  $date=$_GET['date']??date('Y-m-d');
  if(!is_string($date)||strlen($date)!==10)throw new DomainException('تاریخ معتبر نیست.');
  $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
  if(!$day||$day->format('Y-m-d')!==$date)throw new DomainException('تاریخ معتبر نیست.');
  $int=static function($field):int{
   $v=filter_var($_GET[$field]??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
   if($v===false)throw new DomainException('فیلتر معتبر نیست.');
   return (int)$v;
  };
  $therapist=$int('therapist');$label=$int('label');
  $next=$day->modify('+2 days')->format('Y-m-d');
  $where=['s.starts_at>=?','s.starts_at<?',patientScope('p'),"s.status<>'cancelled'","COALESCE(w.state,'scheduled')<>'cancelled'"];
  $params=[$date,$next];
  if($therapist){$where[]='s.therapist_id=?';$params[]=$therapist;}
  if($label){$where[]='(s.label_id=? OR EXISTS(SELECT 1 FROM session_labels sl WHERE sl.session_id=s.id AND sl.label_id=?))';array_push($params,$label,$label);}
  $rows=q("SELECT s.id,s.starts_at,s.ends_at,s.status,s.treatment,s.therapist_id,
                 COALESCE(w.version,0) workflow_version,p.pid,p.fname,p.lname,
                 COALESCE(u.name,'') therapist_name,COALESCE(w.state,'scheduled') workflow_state,
                 COALESCE(NULLIF(l.name,''),(SELECT lx.name FROM session_labels sx
                    JOIN labels lx ON lx.id=sx.label_id WHERE sx.session_id=s.id AND lx.deleted=0 ORDER BY lx.name LIMIT 1),'') label_name,
                 COALESCE(l.color,(SELECT lx.color FROM session_labels sx
                    JOIN labels lx ON lx.id=sx.label_id WHERE sx.session_id=s.id AND lx.deleted=0 ORDER BY lx.name LIMIT 1),'#64748b') label_color
           FROM physio_sessions s JOIN physio_episodes e ON e.id=s.episode_id
           JOIN patients p ON p.pid=e.pid
           LEFT JOIN staff u ON u.id=s.therapist_id
           LEFT JOIN visit_workflows w ON w.session_id=s.id
           LEFT JOIN labels l ON l.id=s.label_id
           WHERE ".implode(' AND ',$where)."
           ORDER BY s.starts_at,s.id LIMIT 301",$params)->fetchAll();
  if(count($rows)>300)throw new DomainException('در این دو روز بیش از ۳۰۰ نوبت وجود دارد. فیلتر را محدود کنید.');
  $groups=[];
  for($i=0;$i<2;$i++){
   $key=$day->modify("+$i days")->format('Y-m-d');
   $groups[$key]=['date'=>$key,'shamsi'=>jd($key,'yyyy/MM/dd'),'weekday'=>jd($key,'EEEE'),'appointments'=>[]];
  }
  $states=workflowLabels();
  foreach($rows as $row){
   $key=substr($row['starts_at'],0,10);
   if(!isset($groups[$key]))continue;
   $groups[$key]['appointments'][]=[
    'id'=>(int)$row['id'],
    'patient'=>patientName($row),
    'time'=>jd($row['starts_at'],'HH:mm'),
    'end_time'=>jd($row['ends_at'],'HH:mm'),
    'therapist'=>$row['therapist_name'],
    'label'=>$row['label_name'],
    'label_color'=>preg_match('/^#[0-9a-fA-F]{6}$/D',(string)$row['label_color'])?$row['label_color']:'#0e7490',
    'status'=>$states[$row['workflow_state']]??'ثبت‌شده',
    'state'=>(string)$row['workflow_state'],
    'href'=>'/visit?id='.(int)$row['id'],
    'workflow_version'=>(int)$row['workflow_version'],
    'can_cancel'=>$row['status']==='scheduled'
      && in_array($row['workflow_state'],['scheduled','referred','waiting'],true)
      && (user()['role']!=='therapist'||(int)$row['therapist_id']===(int)user()['id'])
   ];
  }
  echo json_encode(['ok'=>true,'days'=>array_values($groups)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }catch(DomainException $ex){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$ex->getMessage()],JSON_UNESCAPED_UNICODE);}
 catch(Throwable $ex){http_response_code(500);error_log('Reception agenda '.get_class($ex).' '.basename($ex->getFile()).':'.$ex->getLine());echo '{"ok":false,"error":"نمایش تقویم با خطا مواجه شد."}';}
 exit;
}

function receptionAgendaWidget(array $display,string $selectedDate):void {
 if(!allowed('appointments'))return;
 echo '<button type="button" class="reception-agenda-fab" data-agenda-open aria-haspopup="dialog" aria-controls="reception-agenda-dialog" aria-label="باز کردن تقویم و دفتر نوبت‌ها" title="دفتر نوبت‌ها">'.icon('calendar').'</button>';
 echo '<div id="reception-agenda-dialog" class="reception-agenda-overlay" data-agenda-csrf="'.e($_SESSION['csrf']??'').'" hidden role="dialog" aria-modal="true" aria-labelledby="reception-agenda-title">';
 echo '<div class="reception-agenda-scrim" data-agenda-close></div>';
 echo '<section class="reception-agenda-window" tabindex="-1">';
 echo '<header class="reception-agenda-top"><strong id="reception-agenda-title">دفتر نوبت‌های کلینیک</strong><button type="button" class="reception-agenda-close" data-agenda-close aria-label="بستن تقویم">×</button></header>';
 echo '<div class="reception-agenda-controls">';
 echo '<label>تاریخ مراجعه <input type="date" id="reception-agenda-date" value="'.e($selectedDate).'"></label>';
 echo '<label>پزشک / درمانگر <select id="reception-agenda-therapist"><option value="0">همه درمانگران</option>';
 foreach(array_filter(therapists()) as $id=>$name)echo '<option value="'.(int)$id.'">'.e($name).'</option>';
 echo '</select></label>';
 echo '<label>لیبل <select id="reception-agenda-label"><option value="0">همه لیبل‌ها</option>';
 foreach(q('SELECT id,name FROM labels WHERE active=1 AND deleted=0 ORDER BY name')->fetchAll() as $label)
  echo '<option value="'.(int)$label['id'].'">'.e($label['name']).'</option>';
 echo '</select></label>';
 echo '<div class="reception-agenda-nav">';
 echo '<button type="button" data-agenda-prev aria-label="دو روز قبل">‹</button>';
 echo '<button type="button" data-agenda-today>امروز</button>';
 echo '<button type="button" data-agenda-next aria-label="دو روز بعد">›</button>';
 echo '</div></div>';
 echo '<div class="reception-agenda-book" id="reception-agenda-book" aria-live="polite"><div class="reception-agenda-loading">در حال دریافت نوبت‌ها…</div></div>';
 echo '<div class="reception-agenda-footer"><a href="/appointment/new">+ ثبت نوبت جدید</a><span>نوبت‌ها از پرونده‌های واقعی کلینیک خوانده می‌شوند.</span></div>';
 echo '</section></div>';
}
