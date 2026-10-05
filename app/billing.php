<?php
declare(strict_types=1);

function billingAudit(string $type,int $id,string $action,string $reason,$before,$after):void{
 global $db;if(!$db->inTransaction())throw new LogicException('billing_audit_requires_transaction');
 q('INSERT INTO billing_events(entity_type,entity_id,action,actor_id,reason,details) VALUES(?,?,?,?,?,?)',[$type,$id,$action,(int)(user()['id']??0),$reason,json_encode(compact('before','after'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
}
function billingEpisode(int $id,bool $edit=false):array{
 $ep=q('SELECT id,pid,fee_toman FROM physio_episodes WHERE id=?',[$id])->fetch();
 if(!$ep||!user()||!patientAllowed($ep['pid'])||($edit?!allowed('finance.edit'):(!allowed('finance.debt')&&!allowed('finance.edit'))))throw new DomainException('اجازه مشاهده یا ویرایش صورتحساب این دوره را ندارید.');
 return $ep;
}
function billingAmounts(int $base,string $kind,int $value):array{
 if($base<0||$base>999999999999||$value<0||!in_array($kind,['fixed','percent'],true)||($kind==='percent'&&$value>100)||$value>999999999999)throw new DomainException('مبلغ یا تخفیف معتبر نیست.');
 // Whole toman, half-up percentage rounding; a fixed discount never makes the bill negative.
 $discount=$kind==='percent'?intdiv($base*$value+50,100):min($base,$value);
 return ['base_toman'=>$base,'discount_toman'=>$discount,'net_toman'=>$base-$discount];
}
function discountCategorySave(int $id,string $name,string $kind,int $value,bool $active):int{
 global $db;if(!allowed('finance.edit'))throw new DomainException('اجازه مدیریت تخفیف ندارید.');$name=trim($name);
 if($name===''||mb_strlen($name)>160)throw new DomainException('نام دسته تخفیف را وارد کنید.');billingAmounts(0,$kind,$value);
 $db->beginTransaction();try{$old=$id?q('SELECT * FROM discount_categories WHERE id=? FOR UPDATE',[$id])->fetch():null;if($id&&!$old)throw new DomainException('دسته تخفیف پیدا نشد.');
  if($id)q('UPDATE discount_categories SET name=?,kind=?,value=?,active=? WHERE id=?',[$name,$kind,$value,(int)$active,$id]);
  else{q('INSERT INTO discount_categories(name,kind,value,active) VALUES(?,?,?,?)',[$name,$kind,$value,(int)$active]);$id=(int)$db->lastInsertId();}
  billingAudit('discount',$id,'category_saved','مدیریت دسته تخفیف',$old,compact('name','kind','value','active'));$db->commit();return $id;
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
function billingSave(int $id,int $version,string $mode,int $base,int $discountId,int $packageId,string $reason):void{
 global $db;billingEpisode($id,true);$reason=trim($reason);
 if(!in_array($mode,['manual','services','package'],true)||$reason===''||mb_strlen($reason)>1000)throw new DomainException('مبنای صورتحساب و دلیل تغییر را وارد کنید.');
 $db->beginTransaction();try{
  $ep=q('SELECT id,fee_toman FROM physio_episodes WHERE id=? FOR UPDATE',[$id])->fetch();
  $old=q('SELECT * FROM episode_billing WHERE episode_id=? FOR UPDATE',[$id])->fetch();
  if(($old?(int)$old['version']:0)!==$version)throw new DomainException('صورتحساب تغییر کرده است؛ صفحه را تازه کنید.');
  $discount=$discountId?q('SELECT * FROM discount_categories WHERE id=? AND active=1',[$discountId])->fetch():null;
  if($discountId&&!$discount)throw new DomainException('دسته تخفیف فعال انتخاب کنید.');
  $snapshot=null;
  if($mode==='services')$base=array_sum(array_map('intval',q("SELECT price_snapshot FROM physio_sessions WHERE episode_id=? AND status='done' FOR UPDATE",[$id])->fetchAll(PDO::FETCH_COLUMN)));
  elseif($mode==='package'){
   $package=q('SELECT id,name,price FROM packages WHERE id=? AND active=1 AND deleted=0',[$packageId])->fetch();if(!$package)throw new DomainException('پکیج فعال انتخاب کنید.');
   $items=q('SELECT service_id,quantity FROM package_items WHERE package_id=? ORDER BY service_id',[$packageId])->fetchAll();if(!$items)throw new DomainException('پکیج باید حداقل یک خدمت داشته باشد.');
   $base=(int)$package['price'];$snapshot=json_encode(['package'=>$package,'items'=>$items],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);packageAllowance($id,json_decode($snapshot,true),true);
  }
  $amounts=billingAmounts($base,$discount['kind']??'fixed',(int)($discount['value']??0));
  q('INSERT INTO episode_billing(episode_id,mode,base_toman,discount_id,discount_name,discount_kind,discount_value,discount_toman,net_toman,package_id,package_snapshot,version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode),base_toman=VALUES(base_toman),discount_id=VALUES(discount_id),discount_name=VALUES(discount_name),discount_kind=VALUES(discount_kind),discount_value=VALUES(discount_value),discount_toman=VALUES(discount_toman),net_toman=VALUES(net_toman),package_id=VALUES(package_id),package_snapshot=VALUES(package_snapshot),version=VALUES(version)',[$id,$mode,$base,$discountId?:null,$discount['name']??'',$discount['kind']??'fixed',$discount['value']??0,$amounts['discount_toman'],$amounts['net_toman'],$mode==='package'?$packageId:null,$snapshot,$version+1]);
  q('UPDATE physio_episodes SET fee_toman=? WHERE id=?',[$amounts['net_toman'],$id]);
  billingAudit('episode',$id,'invoice_saved',$reason,$old?:['legacy_fee_toman'=>(int)$ep['fee_toman']],q('SELECT * FROM episode_billing WHERE episode_id=?',[$id])->fetch());$db->commit();
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
/** Called in the visit transaction, only for courses explicitly opted into completed-service billing. */
function packageAllowance(int $episodeId,array $snapshot,bool $includeScheduled=false):array{
 $limits=[];foreach(($snapshot['items']??[]) as $item)$limits[(int)$item['service_id']]=(int)$item['quantity'];
 $where=$includeScheduled?"status IN ('scheduled','done')":"status='done'";
 $used=[];foreach(q("SELECT service_id FROM physio_sessions WHERE episode_id=? AND $where FOR UPDATE",[$episodeId])->fetchAll(PDO::FETCH_COLUMN) as $service)$used[(int)$service]=($used[(int)$service]??0)+1;
 foreach($used as $service=>$n)if(!isset($limits[$service])||$n>$limits[$service])throw new DomainException('خدمت یا تعداد جلسات از سهم پکیج این دوره بیشتر است؛ ابتدا پکیج یا نوبت‌ها را اصلاح کنید.');
 return ['limits'=>$limits,'used'=>$used];
}
function packageCheckBooking(int $episodeId):void{
 $b=q("SELECT package_snapshot FROM episode_billing WHERE episode_id=? AND mode='package' FOR UPDATE",[$episodeId])->fetchColumn();if($b)packageAllowance($episodeId,json_decode($b,true),true);
}
function billingSyncVisit(int $episodeId,int $sessionId):void{
 global $db;if(!$db->inTransaction())throw new LogicException('billing_sync_requires_transaction');
 q('SELECT id FROM physio_episodes WHERE id=? FOR UPDATE',[$episodeId]);
 $old=q('SELECT * FROM episode_billing WHERE episode_id=? FOR UPDATE',[$episodeId])->fetch();if(!$old)return;if($old['mode']==='package'){packageAllowance($episodeId,json_decode($old['package_snapshot'],true));return;}if($old['mode']!=='services')return;
 // Locking/current reads include a concurrent visit that committed while waiting on this course.
 $prices=q("SELECT price_snapshot FROM physio_sessions WHERE episode_id=? AND status='done' FOR UPDATE",[$episodeId])->fetchAll(PDO::FETCH_COLUMN);$base=array_sum(array_map('intval',$prices));
 $amounts=billingAmounts($base,$old['discount_kind'],(int)$old['discount_value']);if($base===(int)$old['base_toman']&&$amounts['net_toman']===(int)$old['net_toman'])return;
 q('UPDATE episode_billing SET base_toman=?,discount_toman=?,net_toman=?,version=version+1 WHERE episode_id=?',[$base,$amounts['discount_toman'],$amounts['net_toman'],$episodeId]);q('UPDATE physio_episodes SET fee_toman=? WHERE id=?',[$amounts['net_toman'],$episodeId]);
 billingAudit('episode',$episodeId,'visit_recalculated','به‌روزرسانی مراجعه '.$sessionId,$old,q('SELECT * FROM episode_billing WHERE episode_id=?',[$episodeId])->fetch());
}
function billingView():void{
 $id=(int)($_GET['episode']??0);try{$ep=billingEpisode($id);}catch(DomainException $ex){http_response_code(403);echo e($ex->getMessage());return;}
 $bill=q('SELECT * FROM episode_billing WHERE episode_id=?',[$id])->fetch();$paid=(int)totalpaid($id);$net=(int)$ep['fee_toman'];
 layout('finance','صورتحساب دوره '.fa($id),'مبالغ تومان؛ سوابق مالی بقراط مستقل باقی می‌مانند.');
 echo '<section class="panel form-panel"><dl>';foreach(['مبلغ پیش از تخفیف'=>$bill['base_toman']??$net,'تخفیف'=>$bill['discount_toman']??0,'مبلغ صورتحساب'=>$net,'خالص پرداخت'=>$paid,'بدهی'=>max(0,$net-$paid),'بستانکاری'=>max(0,$paid-$net)] as $label=>$value)echo '<dt>'.e($label).'</dt><dd>'.money($value).'</dd>';echo '</dl><p>دسته تخفیف: '.e($bill['discount_name']??'بدون تخفیف ثبت‌شده').'</p></section>';
 echo '<button class="button secondary" type="button" onclick="window.print()">چاپ صورتحساب</button>';
 if($bill&&$bill['mode']==='package'){echo '<section class="panel form-panel"><h2>سهم خدمات پکیج</h2>';foreach((json_decode($bill['package_snapshot'],true)['items']??[]) as $item){$used=(int)q("SELECT COUNT(*) FROM physio_sessions WHERE episode_id=? AND service_id=? AND status IN ('scheduled','done')",[$id,$item['service_id']])->fetchColumn();$done=(int)q("SELECT COUNT(*) FROM physio_sessions WHERE episode_id=? AND service_id=? AND status='done'",[$id,$item['service_id']])->fetchColumn();$name=q('SELECT name FROM services WHERE id=?',[$item['service_id']])->fetchColumn();echo '<p>'.e($name?:'خدمت '.$item['service_id']).': '.fa($done).' انجام‌شده، '.fa($used-$done).' رزرو، '.fa(max(0,(int)$item['quantity']-$used)).' باقی‌مانده از '.fa($item['quantity']).'</p>';}echo '</section>';}
 if(allowed('finance.pay'))echo '<a class="button primary" href="/finance?episode='.$id.'">ثبت دریافت / بازگشت وجه این دوره</a>';
 if(allowed('finance.edit')){echo '<section class="panel form-panel">';formstart('billing_save');hidden('episode_id',$id);hidden('version',$bill['version']??0);selectfield('mode','مبنای صورتحساب',['manual'=>'مبلغ توافقی دوره','services'=>'خدمات جلسات انجام‌شده؛ به‌روزرسانی خودکار','package'=>'قیمت ثابت پکیج برای این دوره'],$bill['mode']??'manual');field('base_toman','مبلغ توافقی',$bill['base_toman']??$net,'number','فقط در حالت توافقی استفاده می‌شود.',true,false,'min="0"');selectfield('discount_id','دسته تخفیف',['0'=>'بدون تخفیف']+options('discount_categories','active=1'),$bill['discount_id']??0);selectfield('package_id','پکیج',['0'=>'انتخاب کنید']+options('packages','active=1 AND deleted=0'),$bill['package_id']??0);field('reason','دلیل ثبت یا تغییر','','textarea','نسخه قبلی و مبلغ جدید در تاریخچه می‌مانند.',true);echo '<p class="hint">در حالت خدمات، فقط تعرفه ذخیره‌شده جلسات انجام‌شده محاسبه می‌شود. انتخاب پکیج مبلغ همین دوره را تعیین می‌کند. تغییر قیمت پکیج یا دسته تخفیف، صورتحساب‌های قبلی را خودکار تغییر نمی‌دهد. تخفیف ثابت حداکثر تا مبلغ صورتحساب اعمال می‌شود.</p>';formend('ثبت صورتحساب','/finance');echo '</section><section class="panel form-panel"><h2>دسته‌های تخفیف</h2>';
  foreach(q('SELECT * FROM discount_categories ORDER BY id')->fetchAll() as $category){formstart('discount_save');hidden('episode_id',$id);hidden('id',$category['id']);field('name','نام دسته',$category['name']);selectfield('kind','نوع',['percent'=>'درصد صحیح','fixed'=>'مبلغ ثابت تومان'],$category['kind']);field('value','مقدار',$category['value'],'number');echo '<label><input type="checkbox" name="active" '.($category['active']?'checked':'').'> فعال</label>';formend('ذخیره دسته','/billing?episode='.$id);}
  formstart('discount_save');hidden('id',0);hidden('episode_id',$id);hidden('active',1);field('name','نام دسته جدید','','text','',true);selectfield('kind','نوع',['percent'=>'درصد صحیح','fixed'=>'مبلغ ثابت تومان'],'percent');field('value','مقدار',0,'number','درصد بین صفر تا صد.',true);formend('افزودن دسته','/billing?episode='.$id);echo '</section>';
 }
 if(allowed('finance.history')||allowed('finance.edit')){echo '<section class="panel form-panel"><h2>تاریخچه صورتحساب</h2>';foreach(q("SELECT b.*,u.name actor FROM billing_events b LEFT JOIN staff u ON u.id=b.actor_id WHERE entity_type='episode' AND entity_id=? ORDER BY b.id DESC LIMIT 100",[$id])->fetchAll() as $event){$details=json_decode($event['details'],true);echo '<p>'.jd($event['created_at'],'yyyy/MM/dd HH:mm:ss').' · '.e($event['actor']).' · '.e($event['reason']).'<br>مبلغ قبل: '.money($details['before']['net_toman']??$details['before']['legacy_fee_toman']??0).' ← مبلغ بعد: '.money($details['after']['net_toman']??0).'</p>';}echo '</section>';}
 endLayout();
}
