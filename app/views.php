<?php

function patientsView():void{
 need('patients');$search=trim((string)($_GET['q']??''));$page=max(1,(int)($_GET['p']??1));$where='active=1 AND '.patientScope('patients');$a=[];$filterTherapist=max(0,(int)($_GET['therapist']??0));$filterLabel=max(0,(int)($_GET['label']??0));if($filterTherapist)$where.=' AND (providerID='.$filterTherapist.' OR EXISTS(SELECT 1 FROM physio_episodes fe JOIN physio_sessions fs ON fs.episode_id=fe.id WHERE fe.pid=patients.pid AND fs.therapist_id='.$filterTherapist.'))';if($filterLabel)$where.=' AND label_id='.$filterLabel;if($search!==''){$where.=' AND (fname LIKE ? OR lname LIKE ? OR ('.patientScope('patients','contact').' AND (phone_cell LIKE ? OR phone_home LIKE ?)) OR national_id LIKE ? OR pid=?)';$a=['%'.$search.'%','%'.$search.'%','%'.$search.'%','%'.$search.'%','%'.$search.'%',ctype_digit($search)?(int)$search:0];}$count=(int)q('SELECT COUNT(*) FROM patients WHERE '.$where,$a)->fetchColumn();$rows=q('SELECT * FROM patients WHERE '.$where.' ORDER BY pid DESC LIMIT 20 OFFSET '.(($page-1)*20),$a)->fetchAll();layout('patients','پرونده بیماران','اطلاعات تماس، سوابق و مسیر درمان هر بیمار در یک پرونده.');?>
<section class="panel"><div class="panel-header"><?php liveSearch();?><form class="search-form" method="get"><?=icon('search')?><input name="q" value="<?=e($search)?>" placeholder="نام، شماره همراه، کد ملی یا شماره پرونده" aria-label="جستجوی بیمار"><?php selectfield('therapist','درمانگر',['0'=>'همه درمانگران']+therapists(),$filterTherapist);selectfield('label','لیبل',options('labels','deleted=0'),$filterLabel);?><button class="button secondary" type="submit">جستجو و فیلتر</button></form><?php if(allowed('patients.create')):?><a class="button primary" href="/patients/new"><?=icon('plus')?> بیمار جدید</a><?php endif;?></div><?php if(!$rows):emptyState('پرونده‌ای پیدا نشد','بیمار جدید را ثبت کنید یا عبارت جستجو را تغییر دهید.','/patients/new','ثبت بیمار');else:?><div class="table-scroll"><table><thead><tr><th>بیمار</th><th>شماره پرونده</th><th>شماره همراه</th><th>تاریخ تولد</th><th>پورتال بیمار</th><th></th></tr></thead><tbody><?php foreach($rows as $p):?><tr><td><a class="person" href="/patient?id=<?=$p['pid']?>"><span class="mini-avatar"><?=e(mb_substr($p['fname'],0,1))?></span><strong><?=e(patientName($p))?></strong></a></td><td><?=fa($p['pid'])?></td><td class="numeric"><?=patientAllowed($p['pid'],'contact')?fa($p['phone_cell']?:'—'):'بدون دسترسی'?></td><td><?=jd($p['DOB'])?></td><td><span class="badge <?=$p['allow_patient_portal']==='YES'?'active':'cancelled'?>"><?=$p['allow_patient_portal']==='YES'?'مجاز':'غیرفعال'?></span></td><td><a class="text-link" href="/patient?id=<?=$p['pid']?>">مشاهده پرونده <?=icon('arrow')?></a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?><div class="pagination"><span><?=fa($count)?> پرونده</span><div><?php if($page>1):?><a class="button secondary" href="?p=<?=$page-1?>&q=<?=urlencode($search)?>&therapist=<?=$filterTherapist?>&label=<?=$filterLabel?>">قبلی</a><?php endif;if($page*20<$count):?><a class="button secondary" href="?p=<?=$page+1?>&q=<?=urlencode($search)?>&therapist=<?=$filterTherapist?>&label=<?=$filterLabel?>">بعدی</a><?php endif;?></div></div></section><?php endLayout();
}
function patientForm():void{need('patients');$id=(int)($_GET['id']??0);$p=$id?(q('SELECT * FROM patients WHERE pid=?',[$id])->fetch()?:[]):[];if($id&&!$p){http_response_code(404);exit('پرونده پیدا نشد.');}layout('patients',$id?'ویرایش پرونده بیمار':'پذیرش بیمار جدید','فیلدهای ستاره‌دار ضروری‌اند. اطلاعات واکشی‌شده از بقراط نیز در همین فیلدها نگهداری می‌شوند.');formstart('patient');if(($_GET['next']??'')==='booking')hidden('next','booking');?><input type="hidden" name="pid" value="<?=$id?>"><div class="form-layout"><section class="panel form-panel"><?php sectiontitle('اطلاعات هویتی و تماس','مشخصات اصلی بیمار و راه‌های ارتباط');?><div class="form-grid"><?php field('fname','نام',$p['fname']??'','text','نام کوچک بیمار، مطابق اطلاعات هویتی.',true,false,'maxlength="80" autocomplete="given-name"');field('lname','نام خانوادگی',$p['lname']??'','text','نام خانوادگی بیمار برای جستجو و تشکیل پرونده.',true,false,'maxlength="100" autocomplete="family-name"');field('phone_cell','شماره همراه',$p['phone_cell']??'','tel','شماره اختصاصی بیمار؛ کد ورود پیامکی به این شماره ارسال می‌شود.',false,false,'maxlength="20" inputmode="tel"');field('phone_home','تلفن منزل',$p['phone_home']??'','tel','شماره تلفن ثابت یا شماره تماس منزل.');field('national_id','کد ملی',$p['national_id']??'','text','کد ملی ۱۰ رقمی؛ اگر در دسترس نیست خالی بگذارید.',false,false,'maxlength="10" inputmode="numeric"');field('father_name','نام پدر',$p['father_name']??'','text','نام پدر مطابق اطلاعات پذیرش یا مدرک هویتی.');field('DOB','تاریخ تولد',$p['DOB']??'','date','تاریخ را شمسی انتخاب کنید. ذخیره در سامانه به میلادی انجام می‌شود.');selectfield('sex','جنسیت',[''=>'انتخاب نشده','female'=>'زن','male'=>'مرد','other'=>'سایر'],$p['sex']??'','برای تکمیل اطلاعات هویتی؛ اختیاری است.');field('marital_status','وضعیت تأهل',$p['marital_status']??'','text','مانند مجرد، متأهل، مطلقه یا بیوه.');field('occupation','شغل',$p['occupation']??'','text','شغل ثبت‌شده بیمار.');field('education','تحصیلات',$p['education']??'','text','سطح تحصیلات ثبت‌شده بیمار.');field('height_cm','قد (سانتی‌متر)',$p['height_cm']??'','number','قد بیمار در صورت وجود.',false,false,'min="30" max="250"');field('email','ایمیل (اختیاری)',$p['email']??'','email','برای مکاتبه اختیاری؛ هیچ نقشی در ورود بیمار ندارد.');field('address','نشانی',$p['address']??'','textarea','نشانی محل سکونت بیمار.',false,true);?></div><?php sectiontitle('اطلاعات پذیرش و سابقه اولیه');?><div class="form-grid"><?php field('referral_source','معرف',$p['referral_source']??'','text','شخص یا مرکز معرفی‌کننده بیمار.');field('source_registered_date','تاریخ ثبت در بقراط',$p['source_registered_date']??'','date','تاریخ ثبت اولیه در سامانه منبع.');field('clinic_registered_date','تاریخ ثبت در مطب',$p['clinic_registered_date']??'','date','تاریخ ثبت بیمار در مطب.');field('medical_conditions','بیماری‌ها / مشکلات خاص',$p['medical_conditions']??'','textarea','بیماری‌های خاص یا مشکل ثبت‌شده در پذیرش؛ برای ارزیابی کامل از دوره درمان استفاده کنید.',false,true);field('notes','یادداشت پذیرش',$p['notes']??'','textarea','نکات داخلی پذیرش؛ برای ثبت ارزیابی پزشکی از دوره درمان استفاده کنید.',false,true);?></div><?php sectiontitle('تماس اضطراری');?><div class="form-grid"><?php field('emergency_name','نام فرد تماس',$p['emergency_name']??'','text','نام همراه یا فردی که در شرایط ضروری می‌توان با او تماس گرفت.');field('emergency_phone','تلفن تماس اضطراری',$p['emergency_phone']??'','tel','شماره همراه یا تلفن فرد تماس اضطراری.');?></div></section><aside><section class="panel form-panel"><span class="soft-icon"><?=icon('lock')?></span><h2>دسترسی بیمار</h2><p class="muted">بیمار می‌تواند با شماره همراه ثبت‌شده، نوبت‌ها، مدارک و روند درمانش را ببیند.</p><label class="switch-row"><input type="checkbox" name="allow_portal" <?=($_POST['allow_portal']??(($p['allow_patient_portal']??'')==='YES'))?'checked':''?>><span>اجازه ورود به پورتال بیمار</span></label><p class="hint">ارسال کد فقط پس از تنظیم سرویس پیامک فعال می‌شود.</p></section><div class="info-card"><?=icon('heart')?><p>اطلاعات واکشی‌شده قابل ویرایش است؛ مقدارهای دستی موجود هنگام همگام‌سازی خودکار بازنویسی نمی‌شوند.</p></div></aside></div><?php formend('ذخیره پرونده',$id?'/patient?id='.$id:'/patients');endLayout();}

function patientView():void{
 need('patients');$pid=(int)($_GET['id']??0);$p=q('SELECT * FROM patients WHERE pid=?',[$pid])->fetch();
 $contactAllowed=$p&&patientAllowed($pid,'contact');if($p&&!$contactAllowed)foreach(['phone_cell','phone_home','email','address','emergency_name','emergency_phone'] as $k)$p[$k]='';
 if(!$p){http_response_code(404);exit('پرونده پیدا نشد.');}
 $eps=q('SELECT * FROM physio_episodes e WHERE pid=? AND '.formScope('e').' ORDER BY id DESC',[$pid])->fetchAll();
 $source=q('SELECT id,updated_at FROM import_records WHERE pid=? ORDER BY updated_at DESC LIMIT 1',[$pid])->fetch();
 $sourceId=$source?(int)$source['id']:0;
 $financeAllowed=user()['role']==='admin'||allowed('finance.debt')||allowed('finance.history');
 $events=q('SELECT e.* FROM import_patient_events e JOIN import_records r ON r.id=e.record_id WHERE r.pid=? ORDER BY e.date_jalali DESC,e.time_text DESC,e.id DESC LIMIT 500',[$pid])->fetchAll();
 $services=q('SELECT s.* FROM import_event_services s JOIN import_records r ON r.id=s.record_id WHERE r.pid=? ORDER BY s.record_id,s.event_no,s.item_no',[$pid])->fetchAll();
 $goods=q('SELECT g.* FROM import_event_goods g JOIN import_records r ON r.id=g.record_id WHERE r.pid=? ORDER BY g.record_id,g.event_no,g.item_no',[$pid])->fetchAll();
 $payments=$financeAllowed?q('SELECT x.* FROM import_event_payments x JOIN import_records r ON r.id=x.record_id WHERE r.pid=? ORDER BY x.record_id,x.event_no,x.payment_no',[$pid])->fetchAll():[];
 $sourceTransactions=$financeAllowed?q('SELECT t.* FROM import_financial_transactions t JOIN import_records r ON r.id=t.record_id WHERE r.pid=? ORDER BY t.tx_date DESC,t.record_id DESC,t.event_no DESC,t.tx_no',[$pid])->fetchAll():[];
 $sourceAllocations=$financeAllowed?q('SELECT a.* FROM import_financial_allocations a JOIN import_financial_transactions t ON t.id=a.transaction_id JOIN import_records r ON r.id=t.record_id WHERE r.pid=? ORDER BY a.transaction_id,a.event_no',[$pid])->fetchAll():[];
 $financial=$financeAllowed?q('SELECT f.* FROM import_financial_summary f JOIN import_records r ON r.id=f.record_id WHERE r.pid=? ORDER BY f.updated_at DESC LIMIT 1',[$pid])->fetch():false;
 $legacyLedger=$financeAllowed?q('SELECT * FROM patient_account_ledger WHERE pid=? AND voided=0 ORDER BY entry_date,id',[$pid])->fetchAll():[];
 $legacyBalance=$financeAllowed?(int)q('SELECT COALESCE(SUM(debit_toman-credit_toman),0) FROM patient_account_ledger WHERE pid=? AND voided=0',[$pid])->fetchColumn():0;
 $formRows=q("SELECT s.id submission_id,s.source_submitted_jalali,s.source_submitted_date,t.title,f.label,f.field_type,f.sort_order,v.value_text
   FROM clinic_form_submissions s
   JOIN clinic_form_templates t ON t.id=s.template_id
   LEFT JOIN clinic_form_submission_values v ON v.submission_id=s.id
   LEFT JOIN clinic_form_template_fields f ON f.id=v.field_id
   WHERE s.pid=? AND s.source_system='boghrat'
   ORDER BY COALESCE(s.source_submitted_date,'1000-01-01') DESC,s.id DESC,f.sort_order,f.id",[$pid])->fetchAll();
 $servicesBy=[];foreach($services as $x)$servicesBy[$x['record_id'].':'.$x['event_no']][]=$x;
 $goodsBy=[];foreach($goods as $x)$goodsBy[$x['record_id'].':'.$x['event_no']][]=$x;
 $paymentsBy=[];foreach($payments as $x)$paymentsBy[$x['record_id'].':'.$x['event_no']][]=$x;
 $allocationsBy=[];foreach($sourceAllocations as $x)$allocationsBy[(int)$x['transaction_id']][]=$x;
 $formsBy=[];foreach($formRows as $x){$k=(int)$x['submission_id'];$formsBy[$k]['name']=$x['title'];$formsBy[$k]['submitted_jalali']=$x['source_submitted_jalali'];if($x['label']!==null)$formsBy[$k]['fields'][]=['field_name'=>$x['label'],'field_value'=>$x['value_text'],'field_type'=>$x['field_type']];}
 layout('patients',patientName($p),'پرونده شماره '.fa($pid).' · '.($p['phone_cell']?fa($p['phone_cell']):'شماره همراه ثبت نشده'));?>
 <?php patientExtras($pid,$p);?>
 <div class="patient-overview panel">
  <div class="person"><span class="large-avatar"><?=e(mb_substr($p['fname'],0,1))?></span><div><h2><?=e(patientName($p))?></h2><p class="muted">شماره پرونده: <?=fa($pid)?> · کد ملی: <?=fa($p['national_id']?:'—')?> · تولد: <?=jd($p['DOB'])?></p></div></div>
  <div class="actions"><?php if(allowed('patients.edit')&&$contactAllowed):?><a class="button secondary" href="/patients/edit?id=<?=$pid?>">ویرایش مشخصات</a><?php endif;?><?php if(can('episodes')):?><a class="button primary" href="/episodes/new?pid=<?=$pid?>"><?=icon('plus')?> دوره درمان جدید</a><?php endif;?></div>
 </div>

 <div class="dashboard-grid">
  <section class="panel form-panel">
   <div class="panel-header"><h2>مشخصات هویتی</h2><?php if($source):?><span class="muted">همگام‌سازی بقراط: <?=jd($source['updated_at'],'yyyy/MM/dd HH:mm')?></span><?php endif;?></div>
   <dl class="detail-list">
    <dt>نام و نام خانوادگی</dt><dd><?=e(patientName($p))?></dd>
    <dt>کد ملی</dt><dd><?=fa($p['national_id']?:'ثبت نشده')?></dd>
    <dt>نام پدر</dt><dd><?=e($p['father_name']?:'ثبت نشده')?></dd>
    <dt>تاریخ تولد</dt><dd><?=jd($p['DOB'])?></dd>
    <dt>جنسیت</dt><dd><?=e(['female'=>'زن','male'=>'مرد','other'=>'سایر'][$p['sex']]??'ثبت نشده')?></dd>
    <dt>وضعیت تأهل</dt><dd><?=e($p['marital_status']?:'ثبت نشده')?></dd>
    <dt>شغل</dt><dd><?=e($p['occupation']?:'ثبت نشده')?></dd>
    <dt>تحصیلات</dt><dd><?=e($p['education']?:'ثبت نشده')?></dd>
    <dt>قد</dt><dd><?=$p['height_cm']?fa($p['height_cm']).' سانتی‌متر':'ثبت نشده'?></dd>
   </dl>
  </section>
  <aside class="panel form-panel">
   <h2>اطلاعات تماس و پذیرش</h2>
   <dl>
    <dt>شماره همراه</dt><dd><?=$contactAllowed?fa($p['phone_cell']?:'—'):'بدون دسترسی'?></dd>
    <dt>تلفن منزل</dt><dd><?=$contactAllowed?fa($p['phone_home']?:'—'):'بدون دسترسی'?></dd>
    <dt>ایمیل</dt><dd><?=e($p['email']?:'ثبت نشده')?></dd>
    <dt>معرف</dt><dd><?=e($p['referral_source']?:'ثبت نشده')?></dd>
    <dt>ثبت در بقراط</dt><dd><?=jd($p['source_registered_date'])?></dd>
    <dt>ثبت در مطب</dt><dd><?=jd($p['clinic_registered_date'])?></dd>
    <dt>نشانی</dt><dd><?=e($p['address']?:'ثبت نشده')?></dd>
   </dl>
  </aside>
 </div>

 <?php if($p['medical_conditions']||$p['notes']):?>
 <section class="panel form-panel"><h2>اطلاعات بالینی اولیه</h2>
  <?php if($p['medical_conditions']):?><h3>بیماری‌ها / مشکلات خاص</h3><div class="note-box"><?=nl2br(e($p['medical_conditions']))?></div><?php endif;?>
  <?php if($p['notes']):?><h3>یادداشت پذیرش</h3><div class="note-box"><?=nl2br(e($p['notes']))?></div><?php endif;?>
 </section>
 <?php endif;?>

 <?php if($financial&&$financeAllowed):?>
 <section class="panel form-panel">
  <div class="panel-header"><h2>خلاصه مالی واردشده از بقراط</h2><span class="muted">آرشیو ساختاریافته منبع؛ هنوز سند حسابداری داخلی نیست</span></div>
  <div class="stats-grid three">
   <article class="stat-card"><span>درآمد خدمات</span><strong><?=fa(number_format((int)($financial['service_revenue_toman']??0)))?><small> تومان</small></strong></article>
   <article class="stat-card"><span>درآمد کالا</span><strong><?=fa(number_format((int)($financial['goods_revenue_toman']??0)))?><small> تومان</small></strong></article>
   <article class="stat-card"><span>پرداختی‌ها</span><strong><?=fa(number_format((int)($financial['payments_toman']??0)))?><small> تومان</small></strong></article>
   <article class="stat-card"><span>بازگشت وجه</span><strong><?=fa(number_format((int)($financial['refunds_toman']??0)))?><small> تومان</small></strong></article>
   <article class="stat-card"><span>تخفیف</span><strong><?=fa(number_format((int)($financial['discounts_toman']??0)))?><small> تومان</small></strong></article>
   <article class="stat-card"><span>مانده بدهی</span><strong><?=fa(number_format((int)($financial['outstanding_toman']??0)))?><small> تومان</small></strong></article>
   <article class="stat-card"><span>مانده بستانکاری</span><strong><?=fa(number_format((int)($financial['credit_balance_toman']??0)))?><small> تومان</small></strong></article>
  </div>
 </section>
 <?php endif;?>

 <?php if($legacyLedger&&$financeAllowed):?>
 <section class="panel form-panel">
  <div class="panel-header"><h2>حساب افتتاحیه و مانده قبلی</h2><strong><?=fa(number_format(abs($legacyBalance)))?> تومان <?=$legacyBalance>0?'بدهکار':($legacyBalance<0?'بستانکار':'تسویه')?></strong></div>
  <p class="hint">این دفتر برای مانده منتقل‌شده از بقراط و پرداخت‌های مربوط به همان مانده است و از دوره‌های درمان جدید جدا نگهداری می‌شود.</p>
  <div class="table-scroll"><table><thead><tr><th>تاریخ</th><th>نوع</th><th>بدهکار</th><th>بستانکار</th><th>شرح</th></tr></thead><tbody>
  <?php $ledgerLabels=['opening_balance'=>'افتتاحیه بقراط','payment'=>'پرداخت مانده قبلی','adjustment'=>'تعدیل'];foreach($legacyLedger as $l):?>
   <tr><td><?=jd($l['entry_date'])?></td><td><?=e($ledgerLabels[$l['entry_type']]??$l['entry_type'])?></td><td><?=money($l['debit_toman'])?></td><td><?=money($l['credit_toman'])?></td><td><?=e($l['reference'])?></td></tr>
  <?php endforeach;?>
  </tbody></table></div>
  <?php if($legacyBalance>0&&allowed('finance.pay')):?><p><a class="button secondary" href="/finance?legacy_patient=<?=$pid?>">ثبت پرداخت مانده قبلی</a></p><?php endif;?>
 </section>
 <?php endif;?>

 <?php if($sourceTransactions&&$financeAllowed):?>
 <section class="panel form-panel">
  <div class="panel-header"><h2>گردش مالی تفکیک‌شده بقراط</h2><span class="count"><?=fa(count($sourceTransactions))?></span></div>
  <p class="hint">«پرداخت»، «جمع هزینه مراجعه» و «تخفیف» حرکت مالی منبع‌اند؛ «وضعیت بدهی/بستانکاری» فقط snapshot بقراط است و دوباره در جمع گردش مالی محاسبه نمی‌شود.</p>
  <div class="table-scroll"><table><thead><tr><th>تاریخ</th><th>نوع</th><th>مبلغ (تومان)</th><th>روش</th><th>کد نوبت</th><th>توضیح</th></tr></thead><tbody>
  <?php $txLabels=['service_charge'=>'جمع هزینه مراجعه','payment'=>'پرداخت','discount'=>'تخفیف','debt_snapshot'=>'وضعیت بدهی','credit_snapshot'=>'وضعیت بستانکاری'];foreach($sourceTransactions as $tx):?>
   <tr>
    <td><?=e($tx['date_jalali']?:'—')?></td>
    <td><?=e($txLabels[$tx['tx_type']]??$tx['tx_type'])?><?=$tx['is_snapshot']?' <small>وضعیت</small>':''?></td>
    <td><?=$tx['amount_toman']===null?'—':fa(number_format((int)$tx['amount_toman']))?></td>
    <td><?=e($tx['method']?:'—')?></td>
    <td><?php if($tx['appointment_code']):?><?=fa($tx['appointment_code'])?><?php elseif(!empty($allocationsBy[(int)$tx['id']])):foreach($allocationsBy[(int)$tx['id']] as $a):?><div><?=fa($a['appointment_code'])?> <small>· <?=fa(number_format((int)$a['amount_toman']))?> ت</small></div><?php endforeach;else:?>—<?php endif;?></td>
    <td><?=e($tx['description']?:'—')?></td>
   </tr>
  <?php endforeach;?>
  </tbody></table></div>
 </section>
 <?php endif;?>

 <?php if($events):?>
 <section class="panel form-panel">
  <div class="panel-header"><h2>سوابق ویزیت و مراجعه</h2><span class="count"><?=fa(count($events))?></span></div>
  <div class="table-scroll"><table><thead><tr><th>تاریخ/ساعت</th><th>کد نوبت</th><th>درمانگر</th><th>نوع مراجعه</th><th>وضعیت</th><th>خدمات</th><th>کالا</th><?php if($financeAllowed):?><th>مالی</th><?php endif;?></tr></thead><tbody>
  <?php foreach($events as $ev):$key=$ev['record_id'].':'.$ev['event_no'];?>
   <tr>
    <td><?=e($ev['date_jalali']?:'—')?> <?=e($ev['time_text']?:'')?><?php if($ev['registered_at_jalali']):?><br><small>ثبت نوبت: <?=e($ev['registered_at_jalali'])?><?=($ev['registration_method']?' · '.e($ev['registration_method']):'')?></small><?php endif;?></td>
    <td><?=fa($ev['appointment_code']?:'—')?></td>
    <td><?=e($ev['practitioner']?:'—')?></td>
    <td><strong><?=e($ev['reason']?:'—')?></strong><br><small><?=e($ev['mode']?:'')?></small></td>
    <td><?=e($ev['status']?:'—')?></td>
    <td><?php if(!empty($servicesBy[$key])):foreach($servicesBy[$key] as $x):?><div><?=e($x['service_name'])?><?php if($x['amount_toman']!==null):?> <small>· <?=fa(number_format((int)$x['amount_toman']))?> ت</small><?php endif;?></div><?php endforeach;else:?>—<?php endif;?></td>
    <td><?php if(!empty($goodsBy[$key])):foreach($goodsBy[$key] as $x):?><div><?=fa(rtrim(rtrim(number_format((float)$x['quantity'],3,'.',''),'0'),'.'))?> × <?=e($x['goods_name'])?><?php if($x['amount_toman']!==null):?> <small>· <?=fa(number_format((int)$x['amount_toman']))?> ت</small><?php endif;?></div><?php endforeach;else:?>—<?php endif;?></td>
    <?php if($financeAllowed):?><td>
     <?php if($ev['charge_total_toman']!==null):?><div>جمع هزینه مراجعه: <?=fa(number_format((int)$ev['charge_total_toman']))?> ت</div><?php endif;?>
     <?php if($ev['service_items_total_toman']!==null):?><div>جمع خدمات: <?=fa(number_format((int)$ev['service_items_total_toman']))?> ت</div><?php endif;?>
     <?php if($ev['goods_cost_toman']!==null):?><div>جمع کالا: <?=fa(number_format((int)$ev['goods_cost_toman']))?> ت</div><?php endif;?>
     <?php if($ev['discounts_toman']):?><div>تخفیف: <?=fa(number_format((int)$ev['discounts_toman']))?> ت</div><?php endif;?>
     <?php if($ev['payments_toman']!==null):?><div>مجموع پرداخت: <?=fa(number_format((int)$ev['payments_toman']))?> ت</div><?php endif;?>
     <?php if($ev['debt_toman']!==null):?><div>مانده بدهی: <?=fa(number_format((int)$ev['debt_toman']))?> ت</div><?php endif;?>
     <?php if($ev['credit_toman']!==null):?><div>مانده بستانکاری: <?=fa(number_format((int)$ev['credit_toman']))?> ت</div><?php endif;?>
     <?php if(!empty($paymentsBy[$key]))foreach($paymentsBy[$key] as $x):?><small><?=e($x['method']?:'نامشخص')?>: <?=fa(number_format((int)$x['amount_toman']))?> ت</small><br><?php endforeach;?>
    </td><?php endif;?>
   </tr>
   <?php if($ev['notes']):?><tr><td colspan="<?=$financeAllowed?8:7?>"><strong>توضیحات:</strong> <?=e($ev['notes'])?></td></tr><?php endif;?>
  <?php endforeach;?>
  </tbody></table></div>
 </section>
 <?php endif;?>

 <?php if($formsBy):?>
 <section class="panel form-panel"><div class="panel-header"><h2>فرم‌های پذیرش و اختصاصی</h2><span class="count"><?=fa(count($formsBy))?></span></div>
 <?php foreach($formsBy as $form):?><details><summary><?=e($form['name']?:'فرم')?><?php if(!empty($form['submitted_jalali'])):?> · <?=fa($form['submitted_jalali'])?><?php endif;?></summary><dl class="detail-list"><?php foreach(($form['fields']??[]) as $field):?><dt><?=e($field['field_name'])?></dt><dd><?=e($field['field_value']!==''?$field['field_value']:'ثبت نشده')?></dd><?php endforeach;?></dl></details><?php endforeach;?>
 </section>
 <?php endif;?>

 <div class="dashboard-grid">
  <section class="panel"><div class="panel-header"><h2>دوره‌های درمان مسیحا</h2><span class="count"><?=fa(count($eps))?></span></div><?php if(!$eps)emptyState('هنوز دوره‌ای ثبت نشده','ارزیابی اولیه و تعداد جلسات مورد نیاز را در یک دوره درمان ثبت کنید.','/episodes/new?pid='.$pid,'ایجاد دوره درمان');else foreach($eps as $ep):$done=(int)q("SELECT COUNT(*) FROM physio_sessions WHERE episode_id=? AND status='done'",[$ep['id']])->fetchColumn();?><a class="episode-row" href="/episode?id=<?=$ep['id']?>"><span class="soft-icon"><?=icon('heart')?></span><div><strong><?=e($ep['diagnosis'])?></strong><small><?=e($ep['body_region'])?> · <?=fa($done)?> از <?=fa($ep['planned_sessions'])?> جلسه انجام شده</small><div class="progress"><span style="width:<?=min(100,$done/$ep['planned_sessions']*100)?>%"></span></div></div><?=badge($ep['status'])?><?=icon('arrow')?></a><?php endforeach;?></section>
  <aside class="panel form-panel"><h2>منبع و همگام‌سازی</h2><?php if($source):?><p>این پرونده به داده ساختاریافته بقراط متصل است.</p><p>آخرین همگام‌سازی: <?=jd($source['updated_at'],'yyyy/MM/dd HH:mm')?></p><?php if(user()['role']==='admin'):?><a class="button secondary" href="/imports?record=<?=$sourceId?>">مشاهده آرشیو خام منبع</a><?php endif;?><?php else:?><p class="muted">رکوردی از بقراط به این پرونده متصل نیست.</p><?php endif;?></aside>
 </div>

 <section class="panel"><div class="panel-header"><h2>نوبت‌ها و جلسات مسیحا</h2><a class="text-link" href="/appointment/new?pid=<?=$pid?>">ثبت نوبت <?=icon('plus')?></a></div><?php sessionTable(sessions('p.pid=?',[$pid]),false);?></section>
 <section class="panel" id="documents"><div class="panel-header"><h2>مدارک بیمار</h2><span class="muted">PDF، JPG یا PNG · تا ۱۰ مگابایت</span></div><div class="panel-body"><?php $docs=q('SELECT * FROM physio_patient_documents WHERE pid=? ORDER BY id DESC',[$pid])->fetchAll();foreach($docs as $doc):?><a class="document-row" href="/document?id=<?=$doc['id']?>"><?=icon('file')?><strong><?=e($doc['title'])?></strong><span><?=jd($doc['created_at'])?></span><small>دریافت فایل</small></a><?php endforeach;if(allowed('patients.edit')){formstart('document',true);?><input type="hidden" name="pid" value="<?=$pid?>"><div class="form-grid"><?php field('title','عنوان مدرک','','text','نامی کوتاه مانند «گزارش تصویربرداری زانو».',true);field('file','انتخاب فایل','','file','مدارک تنها در همین پرونده قابل دسترسی هستند.',true,false,'accept="application/pdf,image/jpeg,image/png"');?></div><?php formend('بارگذاری مدرک','/patients');}?></div></section>
 <?php endLayout();
}

function episodesView():void{need('episodes');$rows=q("SELECT e.*,p.fname,p.lname,(SELECT COUNT(*) FROM physio_sessions s WHERE s.episode_id=e.id AND s.status='done') done FROM physio_episodes e JOIN patients p ON p.pid=e.pid WHERE ".patientScope()." AND ".formScope('e')." ORDER BY e.id DESC LIMIT 100")->fetchAll();layout('episodes','دوره‌های درمان','ارزیابی اولیه، اهداف درمان و پیشرفت جلسات را یکجا دنبال کنید.');?><section class="panel"><div class="panel-header"><h2>دوره‌های ثبت‌شده</h2><a class="button primary" href="/episodes/new"><?=icon('plus')?> دوره جدید</a></div><?php if(!$rows):emptyState('یک مسیر درمان تازه بسازید','پس از ثبت بیمار، ارزیابی اولیه و برنامه جلسات را وارد کنید.','/episodes/new','ثبت دوره');else:?><div class="table-scroll"><table><thead><tr><th>بیمار</th><th>تشخیص و ناحیه</th><th>پیشرفت جلسات</th><th>وضعیت</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(patientName($r))?></td><td><strong><?=e($r['diagnosis'])?></strong><small><?=e($r['body_region'])?></small></td><td><?=fa($r['done'])?> از <?=fa($r['planned_sessions'])?><div class="progress"><span style="width:<?=min(100,$r['done']/$r['planned_sessions']*100)?>%"></span></div></td><td><?=badge($r['status'])?></td><td><a class="text-link" href="/episode?id=<?=$r['id']?>">مشاهده <?=icon('arrow')?></a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section><?php endLayout();}
function episodeForm():void{need('episodes');$ps=[''=>'انتخاب بیمار'];foreach(q('SELECT pid,fname,lname FROM patients WHERE active=1 AND '.patientScope('patients').' ORDER BY lname')->fetchAll() as $p)$ps[$p['pid']]=patientName($p).' · '.fa($p['pid']);layout('episodes','برنامه درمان جدید','برای بیمار، هدف‌های روشن و یک برنامه قابل پیگیری تعریف کنید.');formstart('episode');?><section class="panel form-panel"><?php sectiontitle('مشخصات دوره');?><div class="form-grid"><?php selectfield('pid','بیمار',$ps,$_GET['pid']??'','بیماری که این دوره درمان برای او ثبت می‌شود.',true);field('referral','پزشک ارجاع‌دهنده','','text','نام پزشک یا مرکز معرفی‌کننده بیمار.');field('diagnosis','تشخیص یا علت مراجعه','','text','تشخیص ثبت‌شده توسط درمانگر یا علت اصلی مراجعه بیمار.',true);field('body_region','ناحیه تحت درمان','','text','مانند زانو، شانه، کمر یا گردن.',true);field('planned_sessions','تعداد جلسات',10,'number','تعداد جلسات پیش‌بینی‌شده در این دوره؛ حداکثر ۳۶۵ جلسه.',true,false,'min="1" max="365"');if(allowed('finance.edit'))field('fee_toman','هزینه کل دوره (تومان)',0,'number','مبلغ کل توافق‌شده برای دوره، نه هزینه هر جلسه.',true,false,'min="0"');?></div><?php sectiontitle('ارزیابی و هدف‌گذاری');?><div class="form-grid"><?php field('assessment','ارزیابی اولیه','','textarea','شرح وضعیت فعلی، محدودیت حرکتی، نتایج معاینه و سابقه مرتبط.',false,true);field('goals','اهداف درمان','','textarea','نتایج قابل اندازه‌گیری مورد انتظار از دوره، مانند کاهش درد یا افزایش دامنه حرکت.');field('precautions','احتیاط‌ها و محدودیت‌ها','','textarea','مواردی که درمانگر باید پیش از انجام خدمت در نظر بگیرد.');field('exercises','تمرین‌های خانگی','','textarea','تمرین‌ها و توضیحات قابل نمایش برای بیمار در پورتال.',false,true);?></div></section><?php formend('ایجاد دوره درمان','/episodes');endLayout();}
function episodeView():void{need('episodes');$ep=episode((int)($_GET['id']??0));$rows=sessions('s.episode_id=?',[$ep['id']]);$done=count(array_filter($rows,fn($r)=>$r['status']==='done'));layout('episodes',$ep['diagnosis'],patientName($ep).' · '.$ep['body_region']);?><div class="stats-grid three"><article class="stat-card"><span class="stat-label">پیشرفت دوره</span><strong><?=fa($done)?> <small>از <?=fa($ep['planned_sessions'])?> جلسه</small></strong><div class="progress"><span style="width:<?=min(100,$done/$ep['planned_sessions']*100)?>%"></span></div></article><article class="stat-card"><span class="stat-label">جلسات باقی‌مانده</span><strong><?=fa(max(0,$ep['planned_sessions']-$done))?></strong><small>طبق برنامه تجویزشده</small></article><article class="stat-card"><span class="stat-label">وضعیت دوره</span><?=badge($ep['status'])?><small>شروع <?=jd($ep['created_at'])?></small></article></div><section class="panel"><div class="panel-header"><h2>جلسات این دوره</h2><a class="button primary" href="/appointment/new?episode=<?=$ep['id']?>"><?=icon('plus')?> ثبت جلسه</a></div><?php sessionTable($rows,false);?></section><?php formstart('episode_update');?><input type="hidden" name="episode_id" value="<?=$ep['id']?>"><section class="panel form-panel"><?php sectiontitle('برنامه و یادداشت‌های درمان');?><div class="form-grid"><?php field('assessment','ارزیابی اولیه',$ep['assessment'],'textarea','یافته‌های بالینی و وضعیت شروع درمان.',false,true);field('goals','اهداف درمان',$ep['goals'],'textarea','نتایجی که با این دوره دنبال می‌کنید.');field('precautions','احتیاط‌ها',$ep['precautions'],'textarea','محدودیت‌ها و نکات لازم قبل از ارائه خدمت.');field('exercises','تمرین‌های خانگی',$ep['exercises'],'textarea','این متن در بخش روند درمان بیمار نمایش داده می‌شود.',false,true);selectfield('status','وضعیت دوره',['active'=>'در حال درمان','completed'=>'تکمیل‌شده','cancelled'=>'لغوشده'],$ep['status'],'با پایان دوره، وضعیت را تکمیل‌شده قرار دهید.');?></div></section><?php formend('ذخیره برنامه','/patient?id='.$ep['pid']);endLayout();}
function appointmentsView():void{need('appointments');$date=(string)($_GET['date']??date('Y-m-d'));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!strtotime($date))$date=date('Y-m-d');$mini=MasihaJalali::mini($date,str_replace('-','',$date));$rows=sessions('DATE(s.starts_at)=?',[$date]);layout('appointments','تقویم و نوبت‌ها','روز را انتخاب کنید و برنامه درمانگران را ببینید.');?><div class="actions" style="margin-bottom:20px"><a class="button secondary" href="/availability">برنامه حضور و زمان‌های آزاد</a><a class="button secondary" href="/turn-board">تابلو نوبت امروز</a></div><div class="calendar-layout"><aside class="panel calendar-panel"><div class="calendar-heading"><a href="?date=<?=date('Y-m-d',strtotime(MasihaJalali::adjacent($date,-1)))?>" aria-label="ماه قبل">‹</a><h2><?=jd($date,'MMMM yyyy')?></h2><a href="?date=<?=date('Y-m-d',strtotime(MasihaJalali::adjacent($date,1)))?>" aria-label="ماه بعد">›</a></div><div class="calendar-grid"><?php foreach(['ش','ی','د','س','چ','پ','ج'] as $d):?><b><?=$d?></b><?php endforeach;foreach($mini['weeks'] as $week)foreach($week as $cell):?><a class="<?=$cell['inMonth']?'':'outside'?> <?=$cell['isCurrent']?'selected':''?> <?=$cell['isWeekend']?'friday':''?>" href="?date=<?=date('Y-m-d',strtotime($cell['dateYmd']))?>"><?=fa($cell['day'])?></a><?php endforeach;?></div><a class="button secondary full" href="/appointments">رفتن به امروز</a><form method="get" class="jump-form"><?php field('date','رفتن به تاریخ',$date,'date','تاریخ شمسی را انتخاب کنید.');?><button class="button secondary full">نمایش برنامه</button></form><div class="info-card"><?=icon('calendar')?><p>نمایش و انتخاب تاریخ شمسی است؛ تاریخ‌های سامانه به میلادی ذخیره می‌شوند.</p></div></aside><section class="panel calendar-agenda"><div class="panel-header"><div><h2><?=jd($date,'EEEE، d MMMM yyyy')?></h2><p><?=fa(count($rows))?> نوبت در این روز</p></div><a class="button primary" href="/appointment/new?date=<?=e($date)?>"><?=icon('plus')?> نوبت جدید</a></div><?php sessionTable($rows);?></section></div><?php endLayout();}

function turnBoardView():void{
 need('appointments');
 $clinic=max(0,(int)($_GET['clinic']??0));$display=((string)($_GET['display']??''))==='1';
 $clinics=['0'=>'همه کلینیک‌ها'];foreach(q('SELECT id,name FROM clinics WHERE active=1 ORDER BY name')->fetchAll() as $x)$clinics[$x['id']]=$x['name'];
 $where="DATE(s.starts_at)=CURDATE() AND s.status<>'cancelled'";$args=[];if($clinic){$where.=' AND s.clinic_id=?';$args[]=$clinic;}
 $rows=q("SELECT s.id,s.starts_at,s.status,s.turn_state,s.turn_updated_at,p.pid,p.fname,p.lname,u.name therapist_name,c.name clinic_name
          FROM physio_sessions s
          JOIN physio_episodes e ON e.id=s.episode_id
          JOIN patients p ON p.pid=e.pid
          JOIN staff u ON u.id=s.therapist_id
          LEFT JOIN clinics c ON c.id=s.clinic_id
          WHERE $where
          ORDER BY s.starts_at,s.id",$args)->fetchAll();
 $labels=['none'=>'ثبت نشده','waiting'=>'در انتظار','called'=>'فراخوان شده','in_service'=>'در حال درمان','done'=>'پایان'];
 $classes=['none'=>'','waiting'=>'scheduled','called'=>'active','in_service'=>'active','done'=>'completed'];
 layout('appointments',$display?'تابلو نوبت':'مدیریت تابلو نوبت','وضعیت صف امروز؛ صفحه به‌صورت خودکار تازه می‌شود.');
 ?>
 <div data-turn-board-auto="<?=$display?10:20?>">
  <div class="actions" style="margin-bottom:20px">
   <?php if(!$display):?>
    <a class="button secondary" href="/appointments">تقویم نوبت‌ها</a>
    <a class="button secondary" href="/availability">برنامه حضور</a>
    <a class="button primary" href="/turn-board?display=1<?=$clinic?'&clinic='.$clinic:''?>">حالت نمایش مانیتور</a>
    <form method="get" style="display:flex;gap:8px;align-items:end"><?php selectfield('clinic','کلینیک',$clinics,$clinic,'فیلتر تابلو بر اساس محل مراجعه.');?><button class="button secondary">اعمال</button></form>
   <?php else:?><a class="button secondary" href="/turn-board<?=$clinic?'?clinic='.$clinic:''?>">بازگشت به مدیریت</a><?php endif;?>
  </div>

  <section class="panel">
   <div class="panel-header"><div><h2><?=jd(date('Y-m-d'),'EEEE، d MMMM yyyy')?></h2><p><?=fa(count($rows))?> نوبت امروز</p></div></div>
   <?php if(!$rows)emptyState('نوبتی برای امروز نیست','با ثبت نوبت جدید، صف امروز در اینجا نمایش داده می‌شود.');?>
   <?php foreach($rows as $r):
     $full=trim($r['fname'].' '.$r['lname']);$safe=trim($r['fname'].' '.($r['lname']!==''?mb_substr($r['lname'],0,1).'…':''));
     $state=$r['turn_state']?:'none';?>
    <div class="resource-row turn-board-row">
     <span class="soft-icon"><?=icon('calendar')?></span>
     <div class="turn-board-person">
      <strong><?=e($display?$safe:$full)?></strong>
      <small><?=fa(date('H:i',strtotime($r['starts_at'])))?> · <?=e($r['therapist_name'])?><?=($r['clinic_name']?' · '.e($r['clinic_name']):'')?></small>
     </div>
     <span class="badge <?=e($classes[$state]??'')?>"><?=e($labels[$state]??$state)?></span>
     <?php if(!$display):?><form method="post" class="actions"><?php csrf();?><input type="hidden" name="action" value="turn_state"><input type="hidden" name="session_id" value="<?=$r['id']?>"><input type="hidden" name="clinic_id" value="<?=$clinic?>"><select name="turn_state" aria-label="وضعیت صف"><?php foreach($labels as $k=>$label):?><option value="<?=e($k)?>" <?=$state===$k?'selected':''?>><?=e($label)?></option><?php endforeach;?></select><button class="button secondary">ذخیره وضعیت</button></form><?php endif;?>
    </div>
   <?php endforeach;?>
  </section>
 </div>
 <?php endLayout();
}

function sessionView():void{need('episodes');$s=q('SELECT * FROM physio_sessions WHERE id=?',[(int)($_GET['id']??0)])->fetch();if(!$s){http_response_code(404);exit('جلسه پیدا نشد.');}$ep=episode($s['episode_id'],false);layout('episodes','ثبت نتیجه جلسه',patientName($ep).' · '.jd($s['starts_at'],'EEEE d MMMM، HH:mm'));formstart('session');?><input type="hidden" name="session_id" value="<?=$s['id']?>"><section class="panel form-panel"><?php sectiontitle('گزارش جلسه',$s['treatment']);?><div class="form-grid"><?php selectfield('status','نتیجه مراجعه',['done'=>'جلسه انجام شد','absent'=>'بیمار مراجعه نکرد','cancelled'=>'جلسه لغو شد'],$s['status']==='scheduled'?'done':$s['status'],'برای جلسه لغوشده، زمان اتاق و درمانگر دوباره آزاد می‌شود.',true);field('rom','دامنه حرکت',$s['rom'],'text','اندازه‌گیری دامنه حرکت همراه واحد و نام مفصل، مانند خم‌شدن زانو ۱۲۰ درجه.');field('pain_before','درد قبل از جلسه',$s['pain_before']??'','number','شدت درد از صفر (بدون درد) تا ۱۰ (بیشترین درد).',false,false,'min="0" max="10"');field('pain_after','درد بعد از جلسه',$s['pain_after']??'','number','شدت درد پس از جلسه، با همان مقیاس صفر تا ۱۰.',false,false,'min="0" max="10"');field('notes','شرح خدمات و نتیجه',$s['notes'],'textarea','خدمات انجام‌شده، واکنش بیمار و نکات لازم برای جلسه بعد.',false,true);?></div></section><?php formend('ذخیره نتیجه جلسه','/episode?id='.$ep['id']);endLayout();}

function settingsView():void{need('settings');global $config;$smsFile=$config['storage'].'/sms.json';$sms=is_file($smsFile)?json_decode(file_get_contents($smsFile),true):[];layout('settings','تنظیمات کلینیک','نام و نشان خودتان، راهنمای فرم‌ها و اتصال ورود پیامکی.');formstart('settings',true);?><div class="form-layout"><div><section class="panel form-panel"><?php sectiontitle('هویت کلینیک');?><div class="form-grid"><?php field('name','نام سامانه',setting('name','مدیریت کلینیک مسیحا'),'text','در صفحه ورود، سربرگ و منوی کناری نمایش داده می‌شود.',true,true);field('phone','تلفن کلینیک',setting('phone'),'tel','شماره تماس پذیرش برای نمایش به بیماران.');field('logo','لوگوی دلخواه','','file','PNG یا JPG تا ۲ مگابایت؛ تصویر ذخیره‌شده بازپردازش می‌شود.',false,false,'accept="image/png,image/jpeg"');field('address','نشانی کلینیک',setting('address'),'textarea','نشانی مراجعه حضوری برای نمایش به بیمار.',false,true);?></div><?php if(is_file($config['storage'].'/logo.png')):?><img class="logo-preview" src="/logo" alt="لوگوی فعلی"><label class="switch-row"><input type="checkbox" name="remove_logo"> حذف لوگوی فعلی</label><?php endif;?></section><section class="panel form-panel"><?php sectiontitle('ورود پیامکی بیماران','اتصال کاوه‌نگار؛ نیازمند کلید و الگوی تأییدشده');?><label class="switch-row"><input type="checkbox" name="otp" <?=setting('otp')==='1'?'checked':''?>> فعال‌کردن ورود با کد پیامکی</label><div class="form-grid"><?php field('sms_key','کلید اتصال پیامک','','password','کلید اتصال کاوه‌نگار؛ برای حفظ کلید فعلی خالی بگذارید.',false,false,'autocomplete="new-password" maxlength="256"');field('sms_template','نام الگوی کد ورود',$sms['template']??'','text','نام الگوی تأییدشده که متغیر token آن کد ورود است.');?></div><p class="hint">کدها ۳ دقیقه اعتبار دارند و فقط یک‌بار مصرف می‌شوند. ایمیل بیمار لازم نیست.</p></section></div><aside><section class="panel form-panel"><span class="soft-icon"><?=icon('settings')?></span><h2>راهنمای فرم‌ها</h2><p class="muted">کنار هر فیلد یک علامت راهنما وجود دارد. با اشاره، لمس یا فوکوس صفحه‌کلید، توضیح آن نمایش داده می‌شود.</p><label class="switch-row"><input type="checkbox" name="help" <?=setting('help','1')==='1'?'checked':''?>> نمایش راهنمای فیلدها</label></section><section class="info-card"><?=icon('calendar')?><p>تمام تاریخ‌های نمایشی شمسی هستند. اطلاعات تاریخ و ساعت در دیتابیس با قالب میلادی ذخیره می‌شوند.</p></section></aside></div><?php echo '<a class="button secondary" href="/appearance">رنگ، فونت‌ها و معرفی کلینیک جدید</a>';formend('ذخیره تنظیمات','/');endLayout();}

function resourcesView():void{need('resources');layout('resources','خدمات و منابع','اتاق‌ها، تجهیزات و تعرفه خدمات کلینیک را مدیریت کنید.');?><div class="dashboard-grid"><section class="panel form-panel"><?php sectiontitle('اتاق‌ها و تجهیزات');foreach(q('SELECT * FROM resources ORDER BY kind,id')->fetchAll() as $r):?><div class="resource-row"><span class="soft-icon"><?=icon('box')?></span><div><strong><?=e($r['name'])?></strong><small><?=$r['kind']==='room'?'اتاق درمان':'تجهیزات'?> · <?=$r['active']?'فعال':'غیرفعال'?></small></div><form method="post"><?php csrf();?><input type="hidden" name="action" value="toggle_resource"><input type="hidden" name="kind" value="resource"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="button secondary small"><?=$r['active']?'غیرفعال':'فعال'?></button></form></div><?php endforeach;formstart('resource');?><div class="form-grid"><?php field('name','نام مورد جدید','','text','نام یکتا و مشخص، مانند اتاق یک یا دستگاه الکتروتراپی یک.',true);selectfield('kind','نوع',['room'=>'اتاق درمان','equipment'=>'تجهیزات'],'room','برای اتاق و دستگاه، دسته درست را انتخاب کنید.',true);?></div><?php formend('افزودن منبع','/resources');?></section><section class="panel form-panel"><?php sectiontitle('فهرست خدمات و تعرفه‌ها');foreach(q('SELECT * FROM services ORDER BY id')->fetchAll() as $r):?><div class="resource-row"><span class="soft-icon"><?=icon('heart')?></span><div><strong><?=e($r['name'])?></strong><small><?=money($r['price'])?> تومان · <?=fa($r['duration'])?> دقیقه · <?=$r['active']?'فعال':'غیرفعال'?></small></div><form method="post"><?php csrf();?><input type="hidden" name="action" value="toggle_resource"><input type="hidden" name="kind" value="service"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="button secondary small"><?=$r['active']?'غیرفعال':'فعال'?></button></form></div><?php endforeach;formstart('service');?><div class="form-grid"><?php field('name','نام خدمت','','text','نام خدمت کلینیک، مانند تمرین‌درمانی.',true);field('price','تعرفه (تومان)',0,'number','تعرفه مرجع این خدمت؛ مبلغ هر دوره جداگانه ثبت می‌شود.',true,false,'min="0"');field('duration','مدت پیشنهادی (دقیقه)',30,'number','مدت مرجع برای برنامه‌ریزی خدمت.',true,false,'min="5" max="480"');?></div><?php formend('افزودن خدمت','/resources');?></section></div><?php endLayout();}
function loginView():void{head('ورود کارکنان');global $config,$error;?><main class="login-layout"><section class="login-story"><a class="login-brand" href="/login"><?php if(is_file($config['storage'].'/logo.png')):?><img src="/logo" alt="لوگوی کلینیک"><?php endif;?><?=e(setting('name','مدیریت کلینیک مسیحا'))?></a><span class="eyebrow">فضای کاری تیم درمان</span><h1>با نظم بیشتر،<br>به بهبودی نزدیک‌تر.</h1><p>از اولین مراجعه تا آخرین جلسه درمان،<br>همه‌چیز در یک فضای ساده و یکپارچه.</p><div class="login-illustration"><div class="heart-tile"><?=icon('heart')?></div><span class="floating-tag tag-one"><?=icon('calendar')?> نوبت‌دهی منظم</span><span class="floating-tag tag-two"><?=icon('check')?> پیگیری مسیر درمان</span></div><small>مراقبت بهتر، از هماهنگی بهتر شروع می‌شود.</small></section><section class="login-form-wrap"><div class="login-card"><span class="soft-icon"><?=icon('sun')?></span><h2>خوش آمدید</h2><p class="muted">برای ورود به پنل کلینیک، مشخصات حساب خود را وارد کنید.</p><?php if($error??''):?><div class="notice error" role="alert"><?=e($error)?></div><?php endif;formstart('login');field('username','نام کاربری','','text','نام کاربری حساب کارکنان که مدیر کلینیک تعیین کرده است.',true,false,'autocomplete="username"');field('password','رمز ورود','','password','رمز حساب کارکنان؛ آن را در اختیار دیگران قرار ندهید.',true,false,'autocomplete="current-password"');?><button class="button primary full login-submit" type="submit">ورود به پنل <?=icon('arrow')?></button></form><div class="login-patient">بیمار کلینیک هستید؟ <a href="/patient/login">ورود به پرونده من</a></div></div></section></main></body></html><?php }
function availabilityView():void{
 need('appointments');
 $rooms=[''=>'انتخاب اتاق'];$eq=[''=>'بدون تجهیز اختصاصی'];foreach(q('SELECT * FROM resources WHERE active=1')->fetchAll() as $r){if($r['kind']==='room')$rooms[$r['name']]=$r['name'];else $eq[$r['name']]=$r['name'];}
 $staff=therapists();$clinics=['0'=>'همه کلینیک‌ها'];foreach(q('SELECT id,name FROM clinics WHERE active=1 ORDER BY name')->fetchAll() as $r)$clinics[$r['id']]=$r['name'];
 $week=[0=>'شنبه',1=>'یکشنبه',2=>'دوشنبه',3=>'سه‌شنبه',4=>'چهارشنبه',5=>'پنجشنبه',6=>'جمعه'];
 layout('appointments','برنامه حضور و زمان‌های آزاد','برنامه هفتگی درمانگران، روزهای غیبت و زمان‌های قابل رزرو بیمار را یکجا مدیریت کنید.');?>

 <div class="dashboard-grid">
  <section class="panel form-panel">
   <?php sectiontitle('برنامه هفتگی حضور','اگر برای درمانگر هیچ برنامه‌ای تعریف نشده باشد رفتار قبلی سیستم حفظ می‌شود؛ با تعریف اولین برنامه، رزرو فقط داخل ساعت‌های حضور مجاز است.');formstart('work_schedule_save');?>
   <div class="form-grid three">
    <?php selectfield('therapist_id','درمانگر',$staff,'','درمانگر فعال.',true);?>
    <?php selectfield('weekday','روز هفته',$week,0,'روز تکرارشونده برنامه.',true);?>
    <?php field('start_time','شروع','09:00','time','ساعت شروع.',true);?>
    <?php field('end_time','پایان','17:00','time','ساعت پایان.',true);?>
    <?php selectfield('clinic_id','کلینیک',$clinics,0,'برنامه می‌تواند عمومی یا مخصوص یک کلینیک باشد.');?>
    <?php field('effective_from','شروع اعتبار','','date','اختیاری؛ خالی یعنی از ابتدا.');?>
    <?php field('effective_to','پایان اعتبار','','date','اختیاری؛ خالی یعنی بدون پایان.');?>
   </div>
   <?php formend('ثبت بازه حضور','/availability');?>
  </section>

  <section class="panel form-panel">
   <?php sectiontitle('روز یا بازه غیبت','غیبت روی رزرو پذیرش، انتشار زمان آزاد و رزرو بیمار اعمال می‌شود.');formstart('absence_save');?>
   <div class="form-grid">
    <?php selectfield('therapist_id','درمانگر',$staff,'','درمانگر غایب.',true);?>
    <?php field('absence_from','از','','datetime-local','شروع غیبت.',true);?>
    <?php field('absence_to','تا','','datetime-local','پایان غیبت.',true);?>
    <?php field('reason','دلیل','','text','مثلاً مرخصی، مأموریت یا عدم حضور.');?>
   </div>
   <?php formend('ثبت غیبت','/availability');?>
  </section>
 </div>

 <section class="panel form-panel">
  <?php sectiontitle('کپی برنامه حضور','تمام بازه‌های فعال یک درمانگر را برای درمانگر دیگری کپی کنید؛ موارد تکراری دوباره ساخته نمی‌شوند.');formstart('work_schedule_copy');?>
  <div class="form-grid"><?php selectfield('source_therapist_id','از درمانگر',$staff,'','برنامه مبدا.',true);selectfield('target_therapist_id','به درمانگر',$staff,'','درمانگر مقصد.',true);?></div>
  <?php formend('کپی برنامه','/availability');?>
 </section>

 <section class="panel">
  <div class="panel-header"><h2>برنامه‌های حضور فعال</h2></div>
  <?php $rows=q("SELECT s.*,u.name therapist_name,c.name clinic_name FROM therapist_schedules s JOIN staff u ON u.id=s.therapist_id LEFT JOIN clinics c ON c.id=s.clinic_id WHERE s.active=1 ORDER BY u.name,s.weekday,s.start_time")->fetchAll();
  if(!$rows){emptyState('برنامه حضوری تعریف نشده','تا زمانی که برنامه تعریف نشده، محدودیت ساعت حضور برای درمانگر اعمال نمی‌شود.');}else{?><div class="table-scroll"><table><thead><tr><th>درمانگر</th><th>روز</th><th>ساعت</th><th>کلینیک</th><th>اعتبار</th><th></th></tr></thead><tbody>
  <?php foreach($rows as $r):?><tr><td><?=e($r['therapist_name'])?></td><td><?=e($week[(int)$r['weekday']]??'—')?></td><td><?=fa(substr($r['start_time'],0,5))?> تا <?=fa(substr($r['end_time'],0,5))?></td><td><?=e($r['clinic_name']?:'همه کلینیک‌ها')?></td><td><?=($r['effective_from']?jd($r['effective_from']):'بدون شروع')?> تا <?=($r['effective_to']?jd($r['effective_to']):'بدون پایان')?></td><td><form method="post"><?php csrf();?><input type="hidden" name="action" value="work_schedule_delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="button secondary">غیرفعال‌سازی</button></form></td></tr><?php endforeach;?>
  </tbody></table></div><?php }?>
 </section>

 <section class="panel">
  <div class="panel-header"><h2>غیبت‌های فعال</h2></div>
  <?php $abs=q("SELECT a.*,u.name therapist_name FROM therapist_absences a JOIN staff u ON u.id=a.therapist_id WHERE a.active=1 AND a.ends_at>NOW() ORDER BY a.starts_at LIMIT 100")->fetchAll();
  if(!$abs){emptyState('غیبت فعالی ثبت نشده','مرخصی یا بازه عدم حضور درمانگر را از فرم بالا ثبت کنید.');}else{?><div class="table-scroll"><table><thead><tr><th>درمانگر</th><th>از</th><th>تا</th><th>دلیل</th><th></th></tr></thead><tbody>
  <?php foreach($abs as $r):?><tr><td><?=e($r['therapist_name'])?></td><td><?=jd($r['starts_at'],'yyyy/MM/dd HH:mm')?></td><td><?=jd($r['ends_at'],'yyyy/MM/dd HH:mm')?></td><td><?=e($r['reason']?:'—')?></td><td><form method="post"><?php csrf();?><input type="hidden" name="action" value="absence_delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="button secondary">لغو غیبت</button></form></td></tr><?php endforeach;?>
  </tbody></table></div><?php }?>
 </section>

 <section class="panel form-panel">
  <?php sectiontitle('انتشار زمان آزاد برای رزرو بیمار','زمان آزاد باید داخل برنامه حضور درمانگر باشد و با هیچ غیبت، نوبت یا زمان آزاد دیگری هم‌پوشانی نداشته باشد.');formstart('slot');?>
  <div class="form-grid three"><?php selectfield('therapist_id','درمانگر',$staff,'','درمانگر ارائه‌دهنده خدمت.',true);field('date','روز مراجعه',date('Y-m-d'),'date','روز نوبت قابل رزرو.',true);field('time','ساعت شروع','09:00','time','ساعت شروع با قالب ۲۴ ساعته.',true);field('duration','مدت جلسه (دقیقه)',30,'number','مدت زمانی که بیمار رزرو می‌کند.',true,false,'min="5" max="480"');selectfield('room','اتاق',$rooms,'','اتاقی که باید در این زمان آزاد باشد.',true);selectfield('equipment','تجهیزات',$eq,'','تجهیز اختصاصی برای جلسه؛ اختیاری است.');?></div>
  <?php formend('انتشار زمان آزاد','/availability');?>
 </section>

 <section class="panel"><div class="panel-header"><h2>زمان‌های منتشرشده آینده</h2></div><?php $slots=q('SELECT x.*,u.name FROM physio_slots x JOIN staff u ON u.id=x.therapist_id WHERE x.enabled=1 AND x.starts_at>NOW() ORDER BY x.starts_at LIMIT 100')->fetchAll();if(!$slots)emptyState('زمان آزادی منتشر نشده','برای فعال شدن رزرو بیمار، زمان‌های قابل ارائه کلینیک را مشخص کنید.');?><?php foreach($slots as $r):$issue=therapistAvailabilityIssue($r['starts_at'],$r['ends_at'],(int)$r['therapist_id']);?><div class="resource-row" style="padding:18px 24px"><span class="soft-icon"><?=icon('calendar')?></span><div><strong><?=jd($r['starts_at'],'EEEE d MMMM، HH:mm')?></strong><small><?=e($r['name'])?> · <?=e($r['room'])?><?=$issue?' · نامعتبر: '.e($issue):''?></small></div><form method="post"><?php csrf();?><input type="hidden" name="action" value="disable_slot"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="button secondary">توقف نمایش</button></form></div><?php endforeach;?></section>
 <?php endLayout();
}

