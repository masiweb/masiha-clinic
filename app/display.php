<?php
declare(strict_types=1);

function displayOptions():array{return [
 'columns'=>['patient'=>'اطلاعات مراجعه','time'=>'زمان نوبت','therapist'=>'درمانگر','state'=>'وضعیت بیمار','diagnoses'=>'تشخیص‌ها','actions'=>'پکیج / عملیات'],
 'panels'=>['filters'=>'فیلترهای نوبت‌ها','stats'=>'آمار نوبت‌ها','labels'=>'هشتگ‌ها','room'=>'اتاق در فهرست نوبت‌ها','shortcuts'=>'میانبرهای نوبت‌ها','timeline'=>'Timeline مراجعه'],
 'sidebar'=>['dashboard'=>'نمای کلی','appointments'=>'نوبت‌ها','patients'=>'بیماران','episodes'=>'دوره‌های درمان','finance'=>'مالی','reports'=>'گزارش‌ها','staff'=>'همکاران','services'=>'خدمات و پکیج‌ها','inventory'=>'انبار','sms'=>'پیامک','labels'=>'لیبل‌ها','forms'=>'فرم‌ها','resources'=>'اتاق‌ها و تجهیزات','imports'=>'انتقال بقراط','settings'=>'تنظیمات']
];}
function displayDefaults():array{
 $out=[];foreach(displayOptions() as $group=>$items){$n=0;foreach($items as $key=>$title)$out[$group][$key]=['allowed'=>true,'visible'=>true,'locked'=>false,'order'=>++$n];}return $out;
}
function displayPolicy(string $role):array{
 $default=displayDefaults();$saved=json_decode(setting('display.policy.'.$role,'{}'),true);
 if(!is_array($saved))return $default;
 foreach($default as $group=>&$items)foreach($items as $key=>&$item){$raw=$saved[$group][$key]??[];if(!is_array($raw))continue;foreach(['allowed','visible','locked'] as $prop)if(is_bool($raw[$prop]??null))$item[$prop]=$raw[$prop];if(is_int($raw['order']??null)&&$raw['order']>=1&&$raw['order']<=100)$item['order']=$raw['order'];}unset($items,$item);
 return $default;
}
function displayPreferences():array{
 $value=json_decode(setting('display.user.'.(int)user()['id'],'{}'),true);return is_array($value)?$value:[];
}
function displayEffective():array{
 if(!user())return displayDefaults();
 $policy=displayPolicy(user()['role']);$personal=displayPreferences();
 foreach($policy as $group=>&$items){
  foreach($items as $key=>&$item){$v=$personal[$group][$key]??[];if(!$item['locked']&&is_array($v)){
   if(is_bool($v['visible']??null))$item['visible']=$v['visible'];
   if(is_int($v['order']??null)&&$v['order']>=1&&$v['order']<=100)$item['order']=$v['order'];
  }$item['visible']=$item['visible']&&$item['allowed'];}unset($item);
  uasort($items,static fn($a,$b)=>$a['order']<=>$b['order']);
 }unset($items);
 // Display preferences never confer a clinical or navigation permission.
 if(scope('forms.visit')==='none')$policy['columns']['diagnoses']['visible']=false;
 foreach($policy['sidebar'] as $key=>&$item)$item['visible']=$item['visible']&&can($key);unset($item);
 return $policy;
}
function displayVisible(array $profile,string $group,string $key):bool{return ($profile[$group][$key]['visible']??false)===true;}
function displaySave(array $input,bool $policy=false,string $role='',bool $reset=false):void{
 global $db;
 if(!user())throw new DomainException('ابتدا وارد حساب شوید.');
 if($policy&&user()['role']!=='admin')throw new DomainException('تنظیم سیاست نمایش فقط برای مدیر مجاز است.');
 if($policy&&!in_array($role,['admin','reception','therapist','finance'],true))throw new DomainException('نقش معتبر نیست.');
 $storage=$policy?'display.policy.'.$role:'display.user.'.(int)user()['id'];
 $out=[];$rules=displayPolicy(user()['role']);
 foreach(displayOptions() as $group=>$items)foreach($items as $key=>$title){
  $raw=$input[$group][$key]??[];if(!is_array($raw))throw new DomainException('تنظیمات نمایش معتبر نیست.');
  if(isset($raw['order'])&&!is_scalar($raw['order']))throw new DomainException('ترتیب معتبر نیست.');
  $order=filter_var(MasihaOtp::digits((string)($raw['order']??1)),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
  if($order===false)throw new DomainException('ترتیب نمایش باید عددی از ۱ تا ۱۰۰ باشد.');
  if(!$policy&&($rules[$group][$key]['locked']||!$rules[$group][$key]['allowed']))continue;
  $out[$group][$key]=['visible'=>isset($raw['visible']),'order'=>$order];
  if($policy)$out[$group][$key]+=['allowed'=>isset($raw['allowed']),'locked'=>isset($raw['locked'])];
 }
 $db->beginTransaction();try{
  if($reset)q('DELETE FROM settings WHERE `key`=?',[$storage]);else setsetting($storage,json_encode($out,JSON_THROW_ON_ERROR));
  audit($policy?'display_policy_updated':'display_preferences_updated',(int)user()['id']);$db->commit();
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
function displaySettingsView():void{
 need('dashboard');$role=is_string($_GET['role']??null)?$_GET['role']:user()['role'];
 $admin=user()['role']==='admin'&&isset($_GET['policy']);
 if(!in_array($role,['admin','reception','therapist','finance'],true))$role=user()['role'];
 $profile=$admin?displayPolicy($role):displayEffective();
 layout('dashboard','تنظیم نمایش','انتخاب ستون‌ها، بخش‌های صفحه و منوی شخصی');
 if(user()['role']==='admin'){
  echo '<div class="actions"><a class="button secondary" href="/display">تنظیمات شخصی من</a>';
  foreach(['admin'=>'مدیر','reception'=>'پذیرش','therapist'=>'درمانگر','finance'=>'مالی'] as $key=>$label)echo '<a class="button secondary" href="/display?policy=1&amp;role='.$key.'">پیش‌فرض '.e($label).'</a>';echo '</div>';
 }
 formstart('display_save');hidden('policy',$admin?1:0);hidden('role',$role);
 foreach(displayOptions() as $group=>$items){echo '<section class="panel form-panel"><h2>'.e(['columns'=>'ستون‌های نوبت‌ها','panels'=>'بخش‌های داشبورد و مراجعه','sidebar'=>'منوی کناری'][$group]).'</h2><div class="table-scroll"><table><thead><tr><th>عنوان</th><th>نمایش</th><th>ترتیب</th>'.($admin?'<th>مجاز برای نقش</th><th>قفل مدیر</th>':'<th>وضعیت</th>').'</tr></thead><tbody>';
  foreach($items as $key=>$title){$r=$profile[$group][$key];$disabled=!$admin&&($r['locked']||!$r['allowed']);$prefix='display['.$group.']['.$key.']';
   echo '<tr><td>'.e($title).'</td><td><input aria-label="نمایش '.e($title).'" type="checkbox" name="'.$prefix.'[visible]" '.($r['visible']?'checked ':'').($disabled?'disabled':'').'></td><td><input aria-label="ترتیب '.e($title).'" type="number" min="1" max="100" name="'.$prefix.'[order]" value="'.(int)$r['order'].'" '.($disabled?'disabled':'').'></td>';
   if($admin)foreach(['allowed','locked'] as $prop)echo '<td><input aria-label="'.e($prop==='allowed'?'مجاز':'قفل').' '.e($title).'" type="checkbox" name="'.$prefix.'['.$prop.']" '.($r[$prop]?'checked':'').'></td>';
   else echo '<td>'.($disabled?'تعیین‌شده توسط مدیر':'شخصی').'</td>';
   echo '</tr>';
  }echo '</tbody></table></div></section>';
 }
 echo '<div class="form-actions"><button class="button primary">ذخیره نمایش</button><button class="button secondary" name="reset" value="1">بازنشانی پیش‌فرض</button><a class="button secondary" href="/appointments">نوبت‌ها</a></div></form>';endLayout();
}
