<?php
declare(strict_types=1);
require_once __DIR__.'/ai-normalization.php';
function aiCatalog():array{
 return [
  'openai'=>['title'=>'OpenAI','model'=>'gpt-4o-mini','endpoint'=>'https://api.openai.com/v1/chat/completions'],
  'claude'=>['title'=>'Claude','model'=>'claude-sonnet-4-5','endpoint'=>'https://api.anthropic.com/v1/messages'],
  'deepseek'=>['title'=>'DeepSeek','model'=>'deepseek-chat','endpoint'=>'https://api.deepseek.com/chat/completions'],
  'grok'=>['title'=>'Grok','model'=>'grok-4','endpoint'=>'https://api.x.ai/v1/chat/completions']
 ];
}
function aiSecretEncrypt(string $key):string{
 global $config;
 $iv=random_bytes(12);$tag='';
 $secret=hash('sha256','clinic-ai-v1'.$config['secret'],true);
 $result=openssl_encrypt($key,'aes-256-gcm',$secret,OPENSSL_RAW_DATA,$iv,$tag);
 if($result===false)throw new RuntimeException('AI encryption failed.');
 return base64_encode($iv.$tag.$result);
}
function aiSecretDecrypt(string $encrypted):string{
 global $config;
 $raw=base64_decode($encrypted,true);
 if($raw===false||strlen($raw)<30)throw new RuntimeException('AI key corrupt.');
 $secret=hash('sha256','clinic-ai-v1'.$config['secret'],true);
 $result=openssl_decrypt(substr($raw,28),'aes-256-gcm',$secret,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
 if($result===false)throw new RuntimeException('AI key corrupt.');
 return $result;
}
function aiProvider(string $id):?array{
 if(!isset(aiCatalog()[$id]))return null;
 return q('SELECT * FROM ai_provider_settings WHERE provider=?',[$id])->fetch()?:null;
}
function aiSaveProvider():never{
 if((user()['role']??'')!=='admin'){http_response_code(403);exit;}
 $id=val('provider',20,true);
 if(!isset(aiCatalog()[$id]))throw new DomainException('سرویس انتخاب‌شده معتبر نیست.');
 $model=val('model',100,true);
 if(!preg_match('/^[a-zA-Z0-9._:-]{2,100}$/D',$model))throw new DomainException('نام مدل معتبر نیست.');
 $newKey=val('api_key',2000);
 $old=aiProvider($id);
 $stored=$newKey!==''?aiSecretEncrypt($newKey):($old['api_key_encrypted']??null);
 $enabled=isset($_POST['enabled'])?1:0;
 if($enabled&&!$stored)throw new DomainException('ابتدا API Key را وارد کنید.');
 q('INSERT INTO ai_provider_settings(provider,model,api_key_encrypted,enabled) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE model=VALUES(model),api_key_encrypted=VALUES(api_key_encrypted),enabled=VALUES(enabled)',[$id,$model,$stored,$enabled]);
 if(isset($_POST['use_default'])&&$enabled)setsetting('ai_active_provider',$id);
 audit('ai_provider_settings_saved');flash('تنظیمات سرویس ذخیره شد.');go('/ai/settings');
}
function aiSettingsView():void{
 if((user()['role']??'')!=='admin'){http_response_code(403);exit;}
 layout('settings','مدیریت هوش مصنوعی','اتصال سرویس‌های مجاز برای ورود هوشمند اطلاعات فرم.');
 echo '<section class="panel form-panel"><h2>مدیریت اتصال سرویس‌ها</h2><p class="hint">کلیدها رمزگذاری می‌شوند، نمایش داده نمی‌شوند و خالی گذاشتن کادر کلید به معنی حفظ مقدار فعلی است. برای صوت، کلید OpenAI نیز لازم است. مدل مورد استفاده را طبق دسترسی API خود وارد کنید.</p><div class="ai-provider-grid">';
 foreach(aiCatalog() as $id=>$info){
  $row=aiProvider($id);
  echo '<section class="ai-provider-card"><h3>'.e($info['title']).'</h3><p class="hint">'.e($info['endpoint']).'</p>';
  formstart('ai_provider_save');hidden('provider',$id);
  field('model','مدل',($row['model']??$info['model']),'text','نام مدل در حساب سرویس شما',true);
  field('api_key','API Key','','password','کلید جدید یا خالی برای حفظ کلید قبلی');
  echo '<p class="hint">'.(!empty($row['api_key_encrypted'])?'کلید قبلاً ثبت شده است.':'کلیدی ثبت نشده است.').'</p>';
  check('enabled','فعال',(bool)($row['enabled']??false));
  echo '<label class="switch-row"><input type="checkbox" name="use_default" '.(setting('ai_active_provider')===$id?'checked':'').'> مدل اصلی استخراج فرم</label>';
  echo '<button class="button primary" type="submit">ذخیره تنظیمات</button></form>';
  formstart('ai_provider_test');hidden('provider',$id);
  echo '<button class="button secondary small" type="submit">آزمایش اتصال API</button></form></section>';
 }
 echo '</div></section><section class="info-card"><p>پردازش داده‌های مراجعه فقط پس از تأیید کارکنان انجام می‌شود. صدا و متن استخراج‌شده در سامانه ذخیره نخواهند شد. مرورگر برای ضبط به اتصال HTTPS نیاز دارد.</p></section>';
 endLayout();
}

/** Only fixed HTTPS provider endpoints are allowed (no arbitrary target URLs). */
function aiHttp(string $url,array $headers,array $fields,bool $multipart=false):array{
 $ch=curl_init($url);if($ch===false)throw new RuntimeException('AI HTTP client unavailable');
 $headers[]='Accept: application/json';
 if(!$multipart)$headers[]='Content-Type: application/json';
 curl_setopt_array($ch,[
  CURLOPT_POST=>true,
  CURLOPT_POSTFIELDS=>$multipart?$fields:json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
  CURLOPT_HTTPHEADER=>$headers,
  CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>9,CURLOPT_TIMEOUT=>55,
  CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
  CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSL_VERIFYPEER=>true
 ]);
 $result=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 if(!is_string($result)||$status<200||$status>=300){
  if(in_array($status,[401,403],true))throw new DomainException('کلید API یا دسترسی مدل معتبر نیست.');
  if($status===429)throw new DomainException('سقف درخواست‌های سرویس پر شده است.');
  throw new DomainException('سرویس هوش مصنوعی پاسخ مناسبی نداد. '.($status?'HTTP '.$status:''));
 }
 if(strlen($result)>250000)throw new RuntimeException('AI response too large');
 $json=json_decode($result,true);
 if(!is_array($json))throw new DomainException('پاسخ API قابل خواندن نیست.');
 return $json;
}
function aiTranscribe(array $file):string{
 $provider=aiProvider('openai');
 if(!$provider||empty($provider['api_key_encrypted']))throw new DomainException('برای تبدیل صدا به متن، کلید OpenAI را وارد کنید.');
 if(($file['error']??1)!==UPLOAD_ERR_OK||($file['size']??0)<100||($file['size']??0)>6*1024*1024||!is_uploaded_file($file['tmp_name']))
  throw new DomainException('فایل صوتی تا ۶ مگابایت معتبر نیست.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
 $ext=match($mime){
  'audio/webm','video/webm'=>'webm',
  'audio/ogg','application/ogg'=>'ogg',
  'audio/mpeg'=>'mp3','audio/wav','audio/x-wav'=>'wav','audio/mp4'=>'m4a',default=>null
 };
 if($ext===null)throw new DomainException('فرمت صدای ضبط‌شده قابل پردازش نیست.');
 $data=['file'=>new CURLFile($file['tmp_name'],$mime,'clinic-input.'.$ext),'model'=>'gpt-4o-mini-transcribe','language'=>'fa','prompt'=>'گفتار فارسی برای پذیرش کلینیک: اعداد و صفرها را دقیق پیاده کن. «دو صفر» و «دو تا صفر» یعنی دو رقم ۰۰ و نه ۲۰. «صفر دو» یعنی ۰۲. تاریخ‌ها شمسی‌اند؛ مثل «چهارده دی شصت و هشت» یا «چهارده ده شصت و هشت». چیزی حدس نزن.'];
 $json=aiHttp('https://api.openai.com/v1/audio/transcriptions',['Authorization: Bearer '.aiSecretDecrypt($provider['api_key_encrypted'])],$data,true);
 $transcript=trim((string)($json['text']??''));
 if($transcript===''||mb_strlen($transcript)>6000)throw new DomainException('صدای قابل تبدیل به متن دریافت نشد.');
 return $transcript;
}
function aiExtractFields(string $providerName,string $transcript,string $mode):array{
 $p=aiProvider($providerName);
 if(!$p||!$p['enabled']||empty($p['api_key_encrypted']))throw new DomainException('سرویس اصلی هوش مصنوعی هنوز فعال نیست.');
 $catalog=aiCatalog();
 $allowed=$mode==='patient'
  ?['fname','lname','phone_cell','phone_home','national_id','father_name','DOB','sex','marital_status','referral_source','occupation','education','email','address','medical_conditions','notes','emergency_name','emergency_phone']
  :['notes','date','time','duration','room'];
 $instructions='Extract only explicitly stated clinic intake facts. Return exactly one JSON object with a fields object containing string values. Allowed keys: '.implode(', ',$allowed).'. The user speaks Persian and birth dates in the Iranian JALALI calendar. For DOB/date return a Jalali string J:YYYY-MM-DD, never convert calendar dates yourself. Example: «چهارده ده شصت و هشت» is J:1368-10-14, NOT 1968-10-14. For national_id, phone_cell and phone_home, use a DIGIT STRING. «دو صفر»/«دو تا صفر» mean "00", not "20"; «سه صفر» means "000"; «صفر دو» is "02"; «بیست» is "20". Preserve all leading zeros and repeated zeros; do not use arithmetic to decode identifier digits. Omit ambiguous values. No guessed dates or details, no explanations.';
 $key=aiSecretDecrypt($p['api_key_encrypted']);
 if($providerName==='claude'){
  $headers=['x-api-key: '.$key,'anthropic-version: 2023-06-01'];
  $payload=['model'=>$p['model'],'max_tokens'=>1100,'system'=>$instructions,'messages'=>[['role'=>'user','content'=>$transcript]]];
 }else{
  $headers=['Authorization: Bearer '.$key];
  $payload=['model'=>$p['model'],'max_tokens'=>1100,'messages'=>[['role'=>'system','content'=>$instructions],['role'=>'user','content'=>$transcript]]];
 }
 $answer=aiHttp($catalog[$providerName]['endpoint'],$headers,$payload);
 $out=$providerName==='claude'?($answer['content'][0]['text']??''):($answer['choices'][0]['message']['content']??'');
 if(!is_string($out)||!preg_match('/\{[\s\S]*\}/u',$out,$m))throw new DomainException('پاسخ مدل قابل تبدیل به اطلاعات فرم نیست.');
 $structured=json_decode($m[0],true);
 if(!is_array($structured)||!is_array($structured['fields']??null))throw new DomainException('ساختار پاسخ مدل معتبر نیست.');
 $clean=[];
 foreach($structured['fields'] as $key=>$value){
  if(!in_array($key,$allowed,true)||!is_string($value))continue;
  $value=trim($value);
  if($value===''||mb_strlen($value)>1000)continue;
  if(in_array($key,['DOB','date','national_id','phone_cell','phone_home'],true)){
   $normalized=aiSafeFieldValue($key,$value,$transcript);
   if($normalized===null)continue;
   $value=$normalized;
  }
  if($key==='date'&&!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$value))continue;
  if($key==='DOB'&&!preg_match('/^(?:19|20)\d{2}-\d{2}-\d{2}$/D',$value))continue;
  if($key==='time'&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$value))continue;
  if($key==='duration'&&(!ctype_digit($value)||(int)$value<5||(int)$value>480))continue;
  if($key==='sex'&&!in_array($value,['female','male','other'],true))continue;
  $clean[$key]=mb_substr($value,0,300);
 }
 // Recover exact date and identifiers from unambiguous spoken text if the model omitted them.
 foreach(['DOB','date','national_id','phone_cell','phone_home'] as $name){
  if(!in_array($name,$allowed,true)||isset($clean[$name]))continue;
  $normalized=aiSafeFieldValue($name,'',$transcript);
  if($normalized!==null)$clean[$name]=$normalized;
 }
 return $clean;
}

function aiHandleApi():never{
 header('Content-Type: application/json; charset=utf-8');
 try {
  if(!user()||!allowed('ai.use')){http_response_code(403);throw new DomainException('مجوز استفاده از هوش مصنوعی وجود ندارد.');}
  if($_SERVER['REQUEST_METHOD']!=='POST')throw new DomainException('روش درخواست معتبر نیست.');
  checkcsrf();
  $mode=val('mode',20,true);
  if(!in_array($mode,['patient','appointment'],true))throw new DomainException('فرم معتبر نیست.');
  if($mode==='patient'&&!allowed('patients.create'))throw new DomainException('مجوز ایجاد پرونده ندارید.');
  if($mode==='appointment'&&!allowed('appointments'))throw new DomainException('مجوز ثبت نوبت ندارید.');
  if((int)($_SESSION['ai_last_request']??0)>time()-12)throw new DomainException('برای درخواست بعدی کمی فاصله بگذارید.');
  $_SESSION['ai_last_request']=time();
  $uid=(int)user()['id'];$now=time();
  q('INSERT INTO ai_use_limits(staff_id,window_start,attempts) VALUES(?,?,1) ON DUPLICATE KEY UPDATE attempts=IF(window_start<? ,1,attempts+1),window_start=IF(window_start<?,VALUES(window_start),window_start)',[$uid,$now,$now-3600,$now-3600]);
  if((int)q('SELECT attempts FROM ai_use_limits WHERE staff_id=?',[$uid])->fetchColumn()>30)throw new DomainException('سقف استفاده ساعتی پر شده است.');
  $transcript=val('transcript',6000);
  if(isset($_FILES['audio']))$transcript=aiTranscribe($_FILES['audio']);
  if($transcript==='')throw new DomainException('ابتدا صدا ضبط کنید یا متن وارد کنید.');
  $provider=setting('ai_active_provider','openai');
  if(!isset(aiCatalog()[$provider]))throw new DomainException('مدل اصلی معتبر نیست.');
  $values=aiExtractFields($provider,$transcript,$mode);
  audit('ai_form_suggestion');
  echo json_encode(['transcript'=>$transcript,'fields'=>$values],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }catch(Throwable $err){
  if($err instanceof DomainException){
   if(http_response_code()<400)http_response_code(422);
   echo json_encode(['error'=>$err->getMessage()],JSON_UNESCAPED_UNICODE);
  }else{
   http_response_code(502);
   error_log('AI failed '.get_class($err).' at '.basename($err->getFile()).':'.$err->getLine());
   echo '{"error":"پردازش هوشمند انجام نشد؛ سرویس را در پنل مدیریت بررسی کنید."}';
  }
 }
 exit;
}
function aiVoiceWidget(string $mode):void{
 if(!user()||!allowed('ai.use'))return;
 echo '<section class="panel ai-voice-panel" data-ai-voice="'.e($mode).'"><div class="ai-voice-heading"><span class="ai-voice-icon">'.icon('sun').'</span><div><h3>ورود هوشمند اطلاعات با صدا</h3><p>اطلاعات بیمار یا نوبت را بگویید و پیشنهادهای استخراج‌شده را پیش از اعمال تأیید کنید.</p></div></div>';
 echo '<label class="ai-consent"><input type="checkbox" data-ai-consent> با ارسال گفتار یا متن به سرویس هوش مصنوعی انتخاب‌شده موافقم.</label>';
 echo '<div class="ai-voice-actions"><button type="button" class="button primary" data-ai-start>شروع ضبط صدا</button><button type="button" class="button secondary" data-ai-stop disabled>پایان ضبط و تحلیل</button></div>';
 echo '<label class="ai-transcript-label">ورود متنی جایگزین<textarea data-ai-transcript rows="3" maxlength="6000" placeholder="برای نمونه: نام بیمار علی رضایی است و برای درد زانو مراجعه کرده..."></textarea></label>';
 echo '<button type="button" class="button secondary small" data-ai-text>تحلیل متن واردشده</button><p class="ai-voice-status" role="status" aria-live="polite"></p>';
 echo '<div class="ai-voice-results" hidden><h4>پیشنهادهای استخراج‌شده</h4><p class="hint">فقط فیلدهای صحیح را انتخاب کنید؛ هیچ داده‌ای بدون تأیید شما ذخیره نمی‌شود.</p><div data-ai-fields></div><button type="button" class="button primary" data-ai-apply>اعمال مقادیر انتخاب‌شده</button></div></section>';
}

/** Explicit connection check with a synthetic harmless prompt. */
function aiTestProvider():never{
 if((user()['role']??'')!=='admin'){http_response_code(403);exit;}
 $name=val('provider',20,true);$cfg=aiProvider($name);$catalog=aiCatalog();
 if(!isset($catalog[$name])||!$cfg||!$cfg['enabled']||empty($cfg['api_key_encrypted']))
  throw new DomainException('ابتدا کلید سرویس را ذخیره و آن را فعال کنید.');
 $key=aiSecretDecrypt($cfg['api_key_encrypted']);
 if($name==='claude'){
  $headers=['x-api-key: '.$key,'anthropic-version: 2023-06-01'];
  $fields=['model'=>$cfg['model'],'max_tokens'=>20,'messages'=>[['role'=>'user','content'=>'Reply with OK only.']]];
 }else{
  $headers=['Authorization: Bearer '.$key];
  $fields=['model'=>$cfg['model'],'max_tokens'=>20,'messages'=>[['role'=>'user','content'=>'Reply with OK only.']]];
 }
 $data=aiHttp($catalog[$name]['endpoint'],$headers,$fields);
 if(!is_array($data)||(empty($data['choices'][0]['message']['content'])&&empty($data['content'][0]['text'])))
  throw new DomainException('سرویس پاسخ معتبر نداد.');
 audit('ai_provider_connection_test');
 flash('اتصال به '. $catalog[$name]['title'].' با موفقیت آزمایش شد.');
 go('/ai/settings');
}
