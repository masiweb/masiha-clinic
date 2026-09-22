<?php
require __DIR__.'/../app/bootstrap.php';require __DIR__.'/../app/ui.php';require __DIR__.'/../app/actions.php';require __DIR__.'/../app/views.php';require __DIR__.'/../app/admin-actions.php';require __DIR__.'/../app/admin-views.php';require __DIR__.'/../app/admin-api.php';require __DIR__.'/../app/import.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$error='';adminApi($path);
if($path==='/logo'){$f=$config['storage'].'/logo.png';if(is_file($f)){header('Content-Type: image/png');readfile($f);}else{header('Content-Type: image/svg+xml');echo '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>';}exit;}
if(str_starts_with($path,'/patient/')){require __DIR__.'/../app/portal.php';exit;}
if($path==='/health'){header('Content-Type: application/json');echo json_encode(['ok'=>(int)q('SELECT 1')->fetchColumn()===1,'app'=>'masiha-clinic','version'=>'1.2.0']);exit;}
if($path==='/document'){
 $doc=q('SELECT * FROM physio_patient_documents WHERE id=?',[(int)($_GET['id']??0)])->fetch();if(!$doc){http_response_code(404);exit;}
 if(!(user()&&patientAllowed($doc['pid']))&&(!isset($_SESSION['pid'])||(int)$_SESSION['pid']!==(int)$doc['pid']||!q("SELECT pid FROM patients WHERE pid=? AND active=1 AND allow_patient_portal='YES'",[$_SESSION['pid']])->fetchColumn())){http_response_code(403);exit;}
 $f=$config['storage'].'/documents/'.basename($doc['storage_name']);if(!is_file($f)){http_response_code(404);exit;}
 header('Content-Type: '.$doc['mime']);header('Content-Disposition: attachment; filename="document-'.(int)$doc['id'].'.'.(['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg'][$doc['mime']]??'bin').'"');header('Content-Length: '.filesize($f));readfile($f);exit;
}
function renderPage(string $path):void {
 if($path!=='/login'&&!user())go('/login');if(user())guardPage($path);
 match($path){'/','/index.php'=> (function(){need('dashboard');dashboardV2();})(),'/login'=>loginView(),'/patients'=>patientsView(),'/patients/new','/patients/edit'=>patientForm(),'/patient'=>patientView(),'/episodes'=>episodesView(),'/episodes/new'=>episodeForm(),'/episode'=>episodeView(),'/appointments'=>appointmentsView(),'/availability'=>availabilityView(),'/appointment/new'=>bookingView(),'/session'=>sessionView(),'/finance'=>financeV2(),'/reports'=>financeV2(true),'/settings'=>settingsView(),'/staff'=>adminStaff(),'/resources'=>resourcesView(),'/services'=>servicesView(),'/labels'=>labelsView(),'/forms'=>formsView(),'/appearance'=>appearanceView(),'/imports'=>importsView(),default=>(function(){http_response_code(404);echo 'این صفحه پیدا نشد. <a href="/">بازگشت به سامانه</a>';})()};
}
try {if($_SERVER['REQUEST_METHOD']==='POST')handlePost();}
catch(Throwable $ex){
 if($db->inTransaction())$db->rollBack();
 $error=$ex instanceof DomainException?$ex->getMessage():($ex instanceof PDOException&&$ex->getCode()==='23000'?'شماره همراه، کد ملی یا نام انتخاب‌شده قبلاً ثبت شده است.':'ذخیره اطلاعات انجام نشد. دوباره تلاش کنید.');
 if(!($ex instanceof DomainException))error_log('Masiha '.get_class($ex).' code '.$ex->getCode().' at '.basename($ex->getFile()).':'.$ex->getLine());
}
try {renderPage($path);}catch(Throwable $ex){http_response_code(500);error_log('Masiha render '.get_class($ex).' at '.basename($ex->getFile()).':'.$ex->getLine());echo 'نمایش صفحه با خطا مواجه شد. لطفاً دوباره تلاش کنید.';}
