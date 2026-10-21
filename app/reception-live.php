<?php
declare(strict_types=1);

/** Render a single authorized appointment row using the same view and permission checks as /appointments. */
function receptionLiveRow(int $id):string{
 $s=q("SELECT s.*,p.pid,p.fname,p.lname,ep.diagnosis legacy_diagnosis,w.state workflow_state,
  COALESCE(w.version,0) workflow_version,u.name therapist_name,vt.name visit_type_name,
  pk.name package_name,c.name clinic_name
  FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id
  JOIN patients p ON p.pid=ep.pid
  LEFT JOIN visit_workflows w ON w.session_id=s.id
  LEFT JOIN staff u ON u.id=s.therapist_id
  LEFT JOIN visit_types vt ON vt.id=s.visit_type_id
  LEFT JOIN packages pk ON pk.id=s.package_id
  LEFT JOIN clinics c ON c.id=s.clinic_id
  WHERE s.id=? AND ".patientScope('p')." LIMIT 1",[$id])->fetch();
 if(!$s)throw new DomainException('این نوبت در دسترس نیست.');
 workflowAccess($s);
 ob_start();
 try{
  $state=$s['workflow_state']??workflowInitialState($s);
  echo '<tr class="visit-row state-'.e($state).'" data-session-id="'.(int)$s['id'].'">';
  $display=displayEffective();
  foreach(['patient','time','therapist','state','diagnoses'] as $col){
   echo '<td data-column="'.e($col).'">';
   appointmentCell($col,$s,$display);
   echo '</td>';
  }
  echo '</tr>';
  return (string)ob_get_clean();
 }catch(Throwable $ex){ob_end_clean();throw $ex;}
}
/** No full-page navigation and no separate audit path: use the existing atomic mutation services. */
function receptionLiveApi(string $path):void{
 if(!in_array($path,['/api/reception/save','/api/reception/row'],true))return;
 header('Cache-Control: no-store, private');
 header('Content-Type: application/json; charset=utf-8');
 try{
  demand('appointments');
  if($path==='/api/reception/row'){
   if($_SERVER['REQUEST_METHOD']!=='GET')throw new DomainException('روش درخواست مجاز نیست.');
   $id=filter_var($_GET['session_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$id)throw new DomainException('شناسه مراجعه معتبر نیست.');
  }else{
   if($_SERVER['REQUEST_METHOD']!=='POST')throw new DomainException('روش درخواست مجاز نیست.');
   $token=$_POST['csrf']??null;
   if(!is_string($token)||!hash_equals((string)($_SESSION['csrf']??''),$token)){
    http_response_code(403);echo json_encode(['ok'=>false,'error'=>'نشست یا کد امنیتی معتبر نیست؛ دوباره وارد سامانه شوید.'],JSON_UNESCAPED_UNICODE);exit;
   }
   $id=filter_var($_POST['session_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $ver=filter_var($_POST['workflow_version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
   if(!$id||$ver===false||$ver===null)throw new DomainException('شماره مراجعه یا نسخه تغییر معتبر نیست.');
   $action=(string)($_POST['action']??'');
   if($action==='reception_update'){
    $kind=(string)($_POST['kind']??'');
    $values=$_POST['selection']??[];
    receptionUpdate($id,$ver,$kind,[
     'selection'=>$values,
     'notes'=>$_POST['notes']??'',
     'new_diagnosis'=>$_POST['new_diagnosis']??'',
     'new_hashtag'=>$_POST['new_hashtag']??'', 'treatment_plan'=>$_POST['treatment_plan']??''
    ]);
   }elseif($action==='visit_state'){
    $target=(string)($_POST['state']??'');
    if(!array_key_exists($target,workflowLabels()))throw new DomainException('وضعیت انتخابی معتبر نیست.');
    workflowTransition($id,$target,[],$ver);
    if(in_array($target,['absent','cancelled','discharged'],true))
     MasihaSms::safely(fn()=>MasihaSms::cancelSession($id));
   }else throw new DomainException('نوع تغییر معتبر نیست.');
  }
  $html=receptionLiveRow((int)$id);
  echo json_encode(['ok'=>true,'session_id'=>(int)$id,'html'=>$html],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }catch(DomainException $ex){
  http_response_code(str_contains($ex->getMessage(),'تغییر کرده')?409:422);
  echo json_encode(['ok'=>false,'error'=>$ex->getMessage()],JSON_UNESCAPED_UNICODE);
 }catch(Throwable $ex){
  error_log('Reception live '.get_class($ex).' '.basename($ex->getFile()).':'.$ex->getLine());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'ثبت تغییر انجام نشد. لطفاً دوباره تلاش کنید.'],JSON_UNESCAPED_UNICODE);
 }
 exit;
}
