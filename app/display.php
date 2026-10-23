<?php
declare(strict_types=1);

function displayOptions():array{return [
 'booking'=>['service_id'=>'خدمت','tariff_id'=>'تعرفه','episode_id'=>'دوره درمان','date'=>'ورود دستی تاریخ','time'=>'ورود دستی ساعت'],
 'columns'=>['patient'=>'اطلاعات مراجعه','time'=>'زمان نوبت','therapist'=>'دکتر / درمانگر','state'=>'وضعیت بیمار','diagnoses'=>'تشخیص اولیه'],
 'clinical'=>['diagnoses'=>'تنظیم نمایش تشخیص‌ها','programs'=>'تنظیم نمایش برنامه‌های درمانی'],
 'panels'=>['filters'=>'فیلترهای نوبت‌ها','stats'=>'آمار نوبت‌ها','labels'=>'هشتگ‌ها','room'=>'اتاق در فهرست نوبت‌ها','shortcuts'=>'میانبرهای نوبت‌ها','timeline'=>'Timeline مراجعه'],
 'sidebar'=>['dashboard'=>'نمای کلی','appointments'=>'نوبت‌ها','patients'=>'بیماران','episodes'=>'دوره‌های درمان','finance'=>'مالی','reports'=>'گزارش‌ها','staff'=>'همکاران','services'=>'خدمات و برنامه‌های درمانی','inventory'=>'انبار','sms'=>'پیامک','labels'=>'لیبل‌ها','forms'=>'فرم‌ها','resources'=>'اتاق‌ها و تجهیزات','imports'=>'انتقال بقراط','settings'=>'تنظیمات']
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
/** Priority is stored per user, never in the shared clinical catalog. */
function displayOrderedClinicalCatalog(string $kind,array $rows):array{
 if(!in_array($kind,['diagnoses','programs'],true))return $rows;
 $priority=displayPreferences()['catalog_order'][$kind]??[];
 if(!is_array($priority))return $rows;
 usort($rows,static function($a,$b)use($priority){
  $x=(int)($priority[(string)$a['id']]??99999);
  $y=(int)($priority[(string)$b['id']]??99999);
  return $x===$y?strcasecmp((string)$a['name'],(string)$b['name']):($x<=>$y);
 });
 return $rows;
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
 // Keep the actions column visible; per-cell clinical content is permission-gated.
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
 if(!$policy&&!$reset){
  $catalog=$input['catalog_order']??[];
  if(!is_array($catalog))throw new DomainException('ترتیب فهرست‌های درمانی معتبر نیست.');
  foreach(['diagnoses'=>'diagnoses','programs'=>'treatment_programs'] as $kind=>$table){
   $list=$catalog[$kind]??[];
   if(!is_array($list)||count($list)>300)throw new DomainException('فهرست اولویت نامعتبر است.');
   $valid=array_flip(array_map('intval',q("SELECT id FROM $table WHERE active=1")->fetchAll(PDO::FETCH_COLUMN)));
   $out['catalog_order'][$kind]=[];
   foreach($list as $id=>$position){
    $id=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!is_scalar($position))throw new DomainException('ترتیب معتبر نیست.');
    $position=filter_var(MasihaOtp::digits((string)$position),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>500]]);
    if(!$id||$position===false||!isset($valid[$id]))throw new DomainException('اولویت تشخیص یا برنامه نامعتبر است.');
    $out['catalog_order'][$kind][(string)$id]=$position;
   }
  }
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
 foreach(displayOptions() as $group=>$items){echo '<section class="panel form-panel"><h2>'.e(['booking'=>'فیلدهای ثبت نوبت','columns'=>'ستون‌های نوبت‌ها','clinical'=>'نمایش و ترتیب شخصی تشخیص‌ها و برنامه‌های درمانی','panels'=>'بخش‌های داشبورد و مراجعه','sidebar'=>'منوی کناری'][$group]).'</h2><div class="table-scroll"><table><thead><tr><th>عنوان</th><th>نمایش</th><th>ترتیب</th>'.($admin?'<th>مجاز برای نقش</th><th>قفل مدیر</th>':'<th>وضعیت</th>').'</tr></thead><tbody>';
  if($group==='clinical')echo '<p class="hint">در فهرست مراجعات، ترتیب ۱ در بخش زیر «زمان نوبت» و ترتیب ۲ در بخش زیر «درمانگر» قرار می‌گیرد. تیک نمایش هر بخش را نیز می‌توانید مستقل تغییر دهید.</p>';
  foreach($items as $key=>$title){$r=$profile[$group][$key];$disabled=!$admin&&($r['locked']||!$r['allowed']);$prefix='display['.$group.']['.$key.']';
   echo '<tr><td>'.e($title).'</td><td><input aria-label="نمایش '.e($title).'" type="checkbox" name="'.$prefix.'[visible]" '.($r['visible']?'checked ':'').($disabled?'disabled':'').'></td><td><input aria-label="ترتیب '.e($title).'" type="number" min="1" max="100" name="'.$prefix.'[order]" value="'.(int)$r['order'].'" '.($disabled?'disabled':'').'></td>';
   if($admin)foreach(['allowed','locked'] as $prop)echo '<td><input aria-label="'.e($prop==='allowed'?'مجاز':'قفل').' '.e($title).'" type="checkbox" name="'.$prefix.'['.$prop.']" '.($r[$prop]?'checked':'').'></td>';
   else echo '<td>'.($disabled?'تعیین‌شده توسط مدیر':'شخصی').'</td>';
   echo '</tr>';
  }echo '</tbody></table></div></section>';
 }
 if(!$admin){
  echo '<section class="panel form-panel"><h2>ترتیب شخصی عناوین بالینی</h2><p class="hint">عدد کوچک‌تر زودتر نمایش داده می‌شود. این ترتیب فقط برای حساب شماست.</p>';
  foreach(['diagnoses'=>['diagnoses','اولویت تشخیص‌ها'],'programs'=>['treatment_programs','اولویت برنامه‌های درمانی']] as $kind=>[$table,$title]){
   $entries=displayOrderedClinicalCatalog($kind,q("SELECT id,name FROM $table WHERE active=1 ORDER BY name")->fetchAll());
   $saved=displayPreferences()['catalog_order'][$kind]??[];
   echo '<details class="display-catalog-order"><summary>'.e($title).' ('.fa(count($entries)).' عنوان)</summary>';
   if($kind==='programs')echo '<p class="hint">برای تغییر اولویت، هر برنامه را از دستگیره بکشید و در جای جدید رها کنید. در موبایل از کلیدهای بالا و پایین هم می‌توانید استفاده کنید؛ سپس «ذخیره نمایش» را بزنید.</p>';
   echo '<div class="display-catalog-order-list" '.($kind==='programs'?'data-program-sortable="true"':'').'>';
   $position=0;
   foreach($entries as $entry){
    $position++;
    $rank=(int)($saved[(string)$entry['id']]??$position);
    if($kind==='programs'){
     echo '<div class="display-catalog-order-item display-program-sort-item" data-program-item draggable="true" data-program-id="'.(int)$entry['id'].'">';
     echo '<button type="button" class="program-drag-handle" aria-label="کشیدن '.e($entry['name']).'" title="برای جابه‌جایی بکشید">⠿</button>';
     echo '<span class="program-sort-rank">'.fa($position).'</span><span class="program-sort-name">'.e($entry['name']).'</span>';
     echo '<button type="button" class="program-move-up" aria-label="انتقال '.e($entry['name']).' به بالا" title="بالا">↑</button><button type="button" class="program-move-down" aria-label="انتقال '.e($entry['name']).' به پایین" title="پایین">↓</button>';
     echo '<input type="number" min="1" max="500" name="display[catalog_order][programs]['.(int)$entry['id'].']" value="'.max(1,min(500,$rank)).'" aria-label="اولویت '.e($entry['name']).'"></div>';
    }else{
     echo '<label class="display-catalog-order-item"><span>'.e($entry['name']).'</span><input type="number" min="1" max="500" name="display[catalog_order]['.e($kind).']['.(int)$entry['id'].']" value="'.max(1,min(500,$rank)).'" aria-label="اولویت '.e($entry['name']).'"></label>';
    }
   }
   echo '</div></details>';
  }
  echo '</section>';
 }
 echo '<div class="form-actions"><button class="button primary">ذخیره نمایش</button><button class="button secondary" name="reset" value="1">بازنشانی پیش‌فرض</button><a class="button secondary" href="/appointments">نوبت‌ها</a></div></form>';endLayout();
}

function themeExtraColors():array{return [
 'theme_text'=>['ink','#332939','متن اصلی'],
 'theme_muted'=>['muted','#726875','متن توضیحات'],
 'theme_border'=>['line','#e6dce4','حاشیه‌ها'],
 'theme_surface'=>['surface','#ffffff','زمینه کارت‌ها'],
 'theme_mint'=>['mint','#e6f6ee','رنگ مکمل اول'],
 'theme_lavender'=>['lavender','#f3e6f3','رنگ مکمل دوم'],
 'theme_peach'=>['peach','#f5e3c7','رنگ مکمل سوم'],
 'theme_sky'=>['sky','#e8f7fc','رنگ مکمل چهارم'],
 'theme_rose'=>['rose','#fce9ef','رنگ مکمل پنجم'],
 'theme_sidebar'=>['sidebar-bg','#79133f','زمینه منو'],
 'theme_sidebar_text'=>['sidebar-ink','#fff1f6','متن منو'],
 'theme_booked'=>['booked-bg','#fff0de','زمینه زمان رزروشده'],
 'theme_booked_text'=>['booked-ink','#895b35','متن زمان رزروشده'],
];}
