<?php
declare(strict_types=1);
/** Patient-only finances: never render the clinic-wide financial aggregates here. */
function patientFinancialView():void{
 if(!allowed('finance.debt')&&!allowed('finance.history')&&!allowed('finance.edit')){http_response_code(403);echo 'مجوز مشاهده امور مالی بیمار را ندارید.';return;}
 $pid=filter_var($_GET['pid']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$pid){http_response_code(400);echo 'شماره پرونده معتبر نیست.';return;}
 $patient=q('SELECT * FROM patients WHERE pid=? AND active=1',[$pid])->fetch();
 if(!$patient||(scope('profile')!=='none'&&!patientAllowed($pid))){http_response_code(403);echo 'پرونده در دسترس نیست.';return;}
 $eps=q("SELECT e.id,e.status,e.fee_toman,e.created_at,
   COALESCE(b.net_toman,e.fee_toman) charge,
   (SELECT COALESCE(SUM(pay.amount_toman),0) FROM physio_payments pay WHERE pay.episode_id=e.id AND pay.voided=0) paid
   FROM physio_episodes e LEFT JOIN episode_billing b ON b.episode_id=e.id
   WHERE e.pid=? ORDER BY e.id DESC",[$pid])->fetchAll();
 $charges=0;$paid=0;
 foreach($eps as $ep){$charges+=(int)$ep['charge'];$paid+=(int)$ep['paid'];}
 $oldBalance=(int)q('SELECT COALESCE(SUM(debit_toman-credit_toman),0) FROM patient_account_ledger WHERE pid=? AND voided=0',[$pid])->fetchColumn();
 layout('finance','امور مالی '.patientName($patient),'فقط اطلاعات مالی این بیمار ـ شماره پرونده '.fa($pid));
 echo '<section class="panel form-panel patient-finance-only"><div class="panel-header"><h2>خلاصه مالی بیمار</h2><a class="button secondary small" href="/patient?id='.$pid.'">بازگشت به پرونده</a></div>';
 echo '<div class="stats-grid three"><article class="stat-card"><span>مبلغ دوره‌های درمان</span><strong>'.money($charges).' تومان</strong></article><article class="stat-card"><span>دریافتی دوره‌ها</span><strong>'.money($paid).' تومان</strong></article><article class="stat-card"><span>مانده دوره‌های درمان</span><strong>'.money($charges-$paid).' تومان</strong></article></div>';
 echo '<p class="hint">مانده افتتاحیه منتقل‌شده از بقراط: '.money($oldBalance).' تومان (جدا از دوره‌های درمان).</p>';
 echo '<div class="table-scroll"><table><thead><tr><th>دوره</th><th>تاریخ</th><th>مبلغ دوره</th><th>پرداخت‌ها</th><th>مانده</th><th>جزئیات</th></tr></thead><tbody>';
 foreach($eps as $ep){$id=(int)$ep['id'];echo '<tr><td>'.fa($id).'</td><td>'.jd($ep['created_at'],'yyyy/MM/dd').'</td><td>'.money($ep['charge']).'</td><td>'.money($ep['paid']).'</td><td>'.money((int)$ep['charge']-(int)$ep['paid']).'</td><td><a href="/billing?episode='.$id.'">صورتحساب</a></td></tr>';}
 if(!$eps)echo '<tr><td colspan="6">دوره درمان ثبت‌شده‌ای وجود ندارد.</td></tr>';
 echo '</tbody></table></div></section>';
 if(allowed('finance.history')){
  $history=q('SELECT x.* FROM physio_payments x JOIN physio_episodes e ON e.id=x.episode_id WHERE e.pid=? AND x.voided=0 ORDER BY x.created_at DESC,x.id DESC LIMIT 100',[$pid])->fetchAll();
  echo '<section class="panel form-panel"><h2>سوابق دریافتی همین بیمار</h2><div class="table-scroll"><table><thead><tr><th>زمان</th><th>دوره</th><th>مبلغ</th><th>شرح</th></tr></thead><tbody>';
  foreach($history as $x)echo '<tr><td>'.jd($x['created_at'],'yyyy/MM/dd HH:mm').'</td><td>'.fa($x['episode_id']).'</td><td>'.money($x['amount_toman']).'</td><td>'.e($x['reference']).'</td></tr>';
  if(!$history)echo '<tr><td colspan="4">پرداختی ثبت نشده است.</td></tr>';
  echo '</tbody></table></div></section>';
 }
 endLayout();
}
function patientTherapistPanel(int $pid):void{
 if(!allowed('appointments')&&!allowed('patients.edit'))return;
 $active=q('SELECT pt.therapist_id,pt.service_id,u.name therapist,s.name service
 FROM patient_therapists pt JOIN staff u ON u.id=pt.therapist_id
 LEFT JOIN services s ON s.id=pt.service_id
 WHERE pt.pid=? ORDER BY u.name,s.name',[$pid])->fetchAll();
 echo '<section class="panel form-panel patient-therapist-panel"><div class="panel-header"><h2>درمانگران مرتبط با بیمار</h2><span class="muted">هر درمانگر می‌تواند خدمت و نوبت مستقلی داشته باشد.</span></div>';
 if($active)echo '<div class="patient-therapist-list">';
 foreach($active as $item){
  echo '<div class="patient-therapist-chip"><strong>'.e($item['therapist']).'</strong><span>'.e($item['service']?:'همه خدمات').'</span>';
  if(allowed('patients.edit')){echo '<form method="post">';csrf();hidden('action','patient_therapist_delete');hidden('pid',$pid);hidden('therapist_id',$item['therapist_id']);hidden('service_id',$item['service_id']);echo '<button type="submit" class="button secondary small">حذف ارتباط</button></form>';}
  echo '</div>';
 }
 if($active)echo '</div>';
 if(allowed('patients.edit')){
  echo '<form method="post" class="patient-therapist-add"><h3>افزودن درمانگر</h3>';
  csrf();hidden('action','patient_therapist_add');hidden('pid',$pid);
  selectfield('therapist_id','درمانگر',array_filter(therapists()),'','هر بیمار می‌تواند بیش از یک درمانگر داشته باشد.',true);
  selectfield('service_id','خدمت',['0'=>'همه خدمات']+options('services','active=1 AND deleted=0'),'0');
  echo '<button class="button primary small" type="submit">ثبت ارتباط</button></form>';
 }
 echo '</section>';
}
