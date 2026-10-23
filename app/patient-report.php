<?php
declare(strict_types=1);
function patientReportInput():array {
 $from=(string)($_GET['from']??date('Y-m-01'));$to=(string)($_GET['to']??date('Y-m-d'));
 foreach([$from,$to] as $dt){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$dt);if(!$d||$d->format('Y-m-d')!==$dt)throw new DomainException('تاریخ گزارش معتبر نیست.');}
 if($from>$to||strtotime($to)-strtotime($from)>366*86400)throw new DomainException('بازه حداکثر یک سال مجاز است.');
 $mode=(string)($_GET['mode']??'all');$discount=(string)($_GET['discount']??'include');
 if(!in_array($mode,['all','services'],true)||!in_array($discount,['include','exclude'],true))throw new DomainException('فیلتر گزارش معتبر نیست.');
 $pid=filter_var($_GET['pid']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($pid===false)throw new DomainException('پرونده نامعتبر است.');
 $search=mb_substr(trim(MasihaOtp::digits((string)($_GET['q']??''))),0,80);$page=filter_var($_GET['p']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);return compact('from','to','mode','discount','pid','search')+['page'=>$page?:1];
}
function patientReportList(array $f):array {
 $where=['p.active=1'];$args=[];
 if($f['search']!==''){$where[]="(CONCAT(p.fname,' ',p.lname) LIKE ? OR CAST(p.pid AS CHAR)=? OR (".patientScope('p','contact')." AND p.phone_cell LIKE ?))";array_push($args,'%'.$f['search'].'%',$f['search'],'%'.$f['search'].'%');}
 $native="EXISTS(SELECT 1 FROM physio_episodes e JOIN physio_sessions s ON s.episode_id=e.id WHERE e.pid=p.pid AND s.starts_at>=? AND s.starts_at<DATE_ADD(?,INTERVAL 1 DAY)".($f['mode']==='services'?" AND (s.status='done' OR EXISTS(SELECT 1 FROM visit_workflows fw WHERE fw.session_id=s.id AND fw.treatment_finished_at IS NOT NULL) OR EXISTS(SELECT 1 FROM inventory_movements gm WHERE gm.session_id=s.id AND gm.quantity_delta<0 AND gm.reversal_of IS NULL))":'').')';
 $import="EXISTS(SELECT 1 FROM import_records r JOIN import_patient_events v ON v.record_id=r.id WHERE r.pid=p.pid AND v.event_date>=? AND v.event_date<=?".($f['mode']==='services'?" AND (COALESCE(v.service_cost_toman,0)<>0 OR COALESCE(v.goods_cost_toman,0)<>0 OR v.services IS NOT NULL OR v.goods IS NOT NULL)":'').')';
 $where[]="($native OR $import)";array_push($args,$f['from'],$f['to'],$f['from'],$f['to']);
 $criteria=implode(' AND ',$where);
 $total=(int)q("SELECT COUNT(*) FROM patients p WHERE $criteria",$args)->fetchColumn();
 $pages=max(1,(int)ceil($total/50));$page=min((int)$f['page'],$pages);
 $rows=q("SELECT p.pid,p.fname,p.lname FROM patients p WHERE $criteria ORDER BY p.lname,p.fname,p.pid LIMIT 50 OFFSET ".(($page-1)*50),$args)->fetchAll();
 return compact('total','pages','page','rows');
}
function patientReportExtract($raw):string {
 if(!$raw)return '—';$v=json_decode((string)$raw,true);if(!is_array($v))return mb_substr((string)$raw,0,350);
 $parts=[];$walk=static function($v,int $level=0)use(&$walk,&$parts):void {
  if(count($parts)>20||$level>4)return;
  if(is_string($v)){if(trim($v)!=='')$parts[]=mb_substr($v,0,90);return;}
  if(!is_array($v))return;
  foreach(['name','title','service_name','item_name','description'] as $k)if(isset($v[$k])&&is_scalar($v[$k])){$parts[]=mb_substr((string)$v[$k],0,100);return;}
  foreach($v as $part)$walk($part,$level+1);
 };
 $walk($v);return $parts?implode('، ',$parts):'—';
}
function patientReportDetails(array $f):array {
 $pid=(int)$f['pid'];$person=q('SELECT pid,fname,lname FROM patients WHERE pid=? AND active=1',[$pid])->fetch();
 if(!$person)throw new DomainException('پرونده پیدا نشد.');
 $rows=q("SELECT s.id,s.episode_id,s.starts_at,s.status,s.price_snapshot,s.treatment,s.equipment,sv.name service,
  u.name doctor,COALESCE(w.state,'scheduled') state,w.treatment_finished_at
  FROM physio_sessions s JOIN physio_episodes e ON e.id=s.episode_id
  LEFT JOIN visit_workflows w ON w.session_id=s.id LEFT JOIN staff u ON u.id=s.therapist_id
  LEFT JOIN services sv ON sv.id=s.service_id
  WHERE e.pid=? AND s.starts_at>=? AND s.starts_at<DATE_ADD(?,INTERVAL 1 DAY)".
  ($f['mode']==='services'?" AND (s.status='done' OR EXISTS(SELECT 1 FROM visit_workflows fw WHERE fw.session_id=s.id AND fw.treatment_finished_at IS NOT NULL) OR EXISTS(SELECT 1 FROM inventory_movements gm WHERE gm.session_id=s.id AND gm.quantity_delta<0 AND gm.reversal_of IS NULL))":'')." ORDER BY s.starts_at,s.id LIMIT 1001",[$pid,$f['from'],$f['to']])->fetchAll();
 if(count($rows)>1000)throw new DomainException('تعداد جلسات زیاد است. بازه را کوتاه کنید.');
 $program=q('SELECT sp.session_id,t.name FROM session_treatment_programs sp JOIN treatment_programs t ON t.id=sp.program_id JOIN physio_sessions s ON s.id=sp.session_id JOIN physio_episodes e ON e.id=s.episode_id WHERE e.pid=? AND s.starts_at>=? AND s.starts_at<DATE_ADD(?,INTERVAL 1 DAY)',[$pid,$f['from'],$f['to']])->fetchAll();
 $programs=[];foreach($program as $x)$programs[$x['session_id']][]=$x['name'];
 $goods=q('SELECT m.session_id,m.occurred_on,m.quantity_delta,m.total_toman,m.appointment_code,i.name,i.unit FROM inventory_movements m JOIN inventory_items i ON i.id=m.item_id WHERE m.pid=? AND m.occurred_on>=? AND m.occurred_on<=? AND m.quantity_delta<0 AND m.reversal_of IS NULL ORDER BY m.occurred_on,m.id LIMIT 501',[$pid,$f['from'],$f['to']])->fetchAll();
 if(count($goods)>500)throw new DomainException('تعداد کالاها زیاد است. بازه را کوتاه کنید.');
 $bySession=[];$unlinked=[];foreach($goods as $g){if($g['session_id'])$bySession[(int)$g['session_id']][]=$g;else $unlinked[]=$g;}
 $history=q('SELECT v.event_date,v.appointment_code,v.practitioner,v.status,v.services,v.goods,v.service_cost_toman,v.goods_cost_toman,v.discounts_toman,v.payments_toman,v.charge_total_toman FROM import_patient_events v JOIN import_records r ON r.id=v.record_id WHERE r.pid=? AND v.event_date>=? AND v.event_date<=?'.
  ($f['mode']==='services'?" AND (COALESCE(v.service_cost_toman,0)<>0 OR COALESCE(v.goods_cost_toman,0)<>0 OR v.services IS NOT NULL OR v.goods IS NOT NULL)":'').'
  ORDER BY v.event_date,v.event_no LIMIT 1001',[$pid,$f['from'],$f['to']])->fetchAll();
 if(count($history)>1000)throw new DomainException('سوابق انتقالی زیاد است. بازه را کوتاه کنید.');
 $payments=q('SELECT x.created_at,x.amount_toman,x.episode_id,x.reference FROM physio_payments x JOIN physio_episodes e ON e.id=x.episode_id WHERE e.pid=? AND x.voided=0 AND x.created_at>=? AND x.created_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY x.created_at,x.id',[$pid,$f['from'],$f['to']])->fetchAll();
 $importPays=q("SELECT x.tx_date,x.amount_toman,x.method,x.appointment_code FROM import_financial_transactions x JOIN import_records r ON r.id=x.record_id WHERE r.pid=? AND x.is_snapshot=0 AND x.tx_type='payment' AND x.tx_date>=? AND x.tx_date<=? ORDER BY x.tx_date,x.id LIMIT 1001",[$pid,$f['from'],$f['to']])->fetchAll();
 $amounts=q("SELECT COALESCE(SUM(IF(e.created_at<?,COALESCE(b.net_toman,e.fee_toman),0)),0) opening,
 COALESCE(SUM(IF(e.created_at>=? AND e.created_at<DATE_ADD(?,INTERVAL 1 DAY),COALESCE(b.net_toman,e.fee_toman),0)),0) billed,
 COALESCE(SUM(IF(e.created_at>=? AND e.created_at<DATE_ADD(?,INTERVAL 1 DAY),COALESCE(b.discount_toman,0),0)),0) discounts
 FROM physio_episodes e LEFT JOIN episode_billing b ON b.episode_id=e.id WHERE e.pid=? AND e.created_at<DATE_ADD(?,INTERVAL 1 DAY)",[$f['from'],$f['from'],$f['to'],$f['from'],$f['to'],$pid,$f['to']])->fetch();
 $prior=(int)q('SELECT COALESCE(SUM(x.amount_toman),0) FROM physio_payments x JOIN physio_episodes e ON e.id=x.episode_id WHERE e.pid=? AND x.voided=0 AND x.created_at<?',[$pid,$f['from']])->fetchColumn();
 $ledger=q('SELECT COALESCE(SUM(IF(entry_date<?,debit_toman-credit_toman,0)),0) opening,
 COALESCE(SUM(IF(entry_date>=? AND entry_date<=?,debit_toman,0)),0) debit,
 COALESCE(SUM(IF(entry_date>=? AND entry_date<=?,credit_toman,0)),0) credit FROM patient_account_ledger WHERE pid=? AND voided=0 AND entry_date<=?',[$f['from'],$f['from'],$f['to'],$f['from'],$f['to'],$pid,$f['to']])->fetch();
 $oldDiscount=(int)q("SELECT COALESCE(SUM(x.amount_toman),0) FROM import_financial_transactions x JOIN import_records r ON r.id=x.record_id WHERE r.pid=? AND x.tx_type='discount' AND x.is_snapshot=0 AND x.tx_date>=? AND x.tx_date<=?",[$pid,$f['from'],$f['to']])->fetchColumn();
 $paid=array_sum(array_map(static fn($x)=>(int)$x['amount_toman'],$payments));
 $discount=(int)$amounts['discounts']+max(0,$oldDiscount);
 $adjust=$f['discount']==='exclude'?$discount:0;
 $opening=(int)$amounts['opening']-$prior+(int)$ledger['opening'];
 $change=(int)$amounts['billed']-$paid+(int)$ledger['debit']-(int)$ledger['credit']+$adjust;
 $balance=$opening+$change;
 $summary=['opening'=>$opening,'billed'=>(int)$amounts['billed'],'paid'=>$paid,'legacy_debit'=>(int)$ledger['debit'],'legacy_credit'=>(int)$ledger['credit'],'discount'=>$discount,'adjust'=>$adjust,'change'=>$change,'balance'=>$balance,'debt'=>max(0,$balance),'credit'=>max(0,-$balance)];
 return compact('person','rows','programs','bySession','unlinked','history','payments','importPays','summary');
}
function patientReportView():void {
 demand('reports');try{$f=patientReportInput();$r=$f['pid']?patientReportDetails($f):['people'=>patientReportList($f)];}
 catch(DomainException $ex){layout('reports','گزارش مراجعین');echo '<p class="notice">'.e($ex->getMessage()).'</p>';endLayout();return;}
 if($f['pid']&&in_array(($_GET['export']??''),['pdf','xlsx','csv'],true)){patientInvoiceExport($f,$r,(string)$_GET['export']);return;}
 layout('reports','گزارش کامل مراجعین','نوبت‌ها، روند درمان، خدمات، تجهیزات، پرداخت‌ها و مانده مالی بیماران.');
 echo '<div class="actions"><a href="/reports" class="button secondary">گزارش مالی مجموعه</a><a href="/reports/clinical" class="button secondary">گزارش درمانگران</a></div>';
 echo '<section class="panel form-panel"><form method="get" action="/reports/patients"><div class="form-grid three">';
 if($f['pid'])hidden('pid',$f['pid']);else field('q','جستجوی مراجع',$f['search'],'text');
 field('from','تاریخ شروع',$f['from'],'date');field('to','تاریخ پایان',$f['to'],'date');
 selectfield('mode','مبنای گزارش',['all'=>'تمام نوبت‌ها','services'=>'فقط خدمات دریافت‌شده / درمان تکمیل‌شده'],$f['mode']);
 selectfield('discount','تخفیف',['include'=>'اعمال تخفیف','exclude'=>'عدم اعمال تخفیف (فرضی)'],$f['discount']);
 echo '</div><button class="button primary">نمایش گزارش</button></form></section>';
 if(!$f['pid']){echo '<section class="panel"><h2>انتخاب مراجع</h2><div class="table-scroll"><table><thead><tr><th>نام</th><th>پرونده</th><th>گزارش</th></tr></thead><tbody>';
 foreach($r['people']['rows'] as $p){$url='/reports/patients?'.http_build_query(array_merge($f,['pid'=>$p['pid']]));echo '<tr><td>'.e(patientName($p)).'</td><td>'.fa($p['pid']).'</td><td><a href="'.e($url).'">گزارش کامل</a></td></tr>';}
 if(!$r['people']['rows'])echo '<tr><td colspan="3">بیماری یافت نشد.</td></tr>';
 echo '</tbody></table></div><div class="actions">';
 foreach([$r['people']['page']-1=>'قبلی',$r['people']['page']+1=>'بعدی'] as $page=>$title)
  if($page>=1&&$page<=$r['people']['pages'])
   echo '<a class="button secondary" href="/reports/patients?'.e(http_build_query(array_replace($f,['p'=>$page]))).'">'.e($title).'</a>';
 echo '<span>'.fa($r['people']['total']).' مراجع · صفحه '.fa($r['people']['page']).' از '.fa($r['people']['pages']).'</span></div></section>';endLayout();return;}
 $s=$r['summary'];echo '<section class="panel"><div class="panel-header"><h2>'.e(patientName($r['person'])).' · پرونده '.fa($f['pid']).'</h2><div class="actions">';
 foreach(['pdf'=>'دانلود فاکتور PDF','xlsx'=>'دانلود اکسل','csv'=>'دانلود CSV'] as $format=>$title){
  echo '<a class="button secondary" href="/reports/patients?'.e(http_build_query(array_merge($f,['export'=>$format]))).'">'.e($title).'</a>';
 }
 echo '</div></div>';
 echo '<p class="hint">مبالغ به تومان است؛ بهای جلسه و کالا صرفاً اطلاعاتی است و روی صورتحساب دوره دوباره محاسبه نمی‌شود. پرداخت‌های وارداتی بقراط نیز در مانده دفتر بقراط منظور شده‌اند.</p></section>';
 echo '<div class="stats-grid three">';foreach(['مانده ابتدا'=>$s['opening'],'هزینه دوره‌های درمان'=>$s['billed'],'پرداخت داخلی'=>$s['paid'],'بدهکار دفتر بقراط'=>$s['legacy_debit'],'بستانکار دفتر بقراط'=>$s['legacy_credit'],'تخفیف'=>$s['discount'],'اثر فرضی حذف تخفیف'=>$s['adjust'],'تغییرات بازه'=>$s['change'],'بدهی'=>$s['debt'],'بستانکاری'=>$s['credit'],'مانده حساب'=>$s['balance']] as $title=>$value)
 echo '<article class="stat-card"><span>'.e($title).'</span><strong>'.money($value).' تومان</strong></article>';echo '</div>';
 echo '<section class="panel"><h2>نوبت‌ها و خدمات مسیحا</h2><div class="table-scroll"><table><thead><tr><th>نوبت</th><th>درمانگر</th><th>تکمیل درمان</th><th>خدمات و برنامه‌ها</th><th>تجهیزات تحویلی</th><th>تعرفه جلسه</th><th>مبلغ کالای دریافتی</th></tr></thead><tbody>';
 foreach($r['rows'] as $row){
  $goods=$r['bySession'][(int)$row['id']]??[];$names=array_column($goods,'name');if($row['equipment'])$names[]=$row['equipment'];$value=array_sum(array_map(static fn($v)=>abs((int)$v['total_toman']),$goods));
  $complete=$row['status']==='done'||$row['treatment_finished_at']!==null||in_array($row['state'],['visited','discharged'],true);
  echo '<tr><td><a href="/visit?id='.(int)$row['id'].'">'.jd($row['starts_at'],'yyyy/MM/dd HH:mm').'</a></td><td>'.e($row['doctor']?:'—').'</td><td>'.($complete?'تکمیل‌شده':e($row['state'])).'</td><td>'.e(trim(($row['service']??'').' '.implode('، ',$r['programs'][(int)$row['id']]??[]).' '.($row['treatment']??''))).'</td><td>'.e(implode('، ',$names)?:'—').'</td><td>'.money($row['price_snapshot']).'</td><td>'.money($value).'</td></tr>';
 }if(!$r['rows'])echo '<tr><td colspan="7">نوبتی وجود ندارد.</td></tr>';echo '</tbody></table></div></section>';
 echo '<section class="panel"><h2>سوابق انتقال‌یافته بقراط</h2><div class="table-scroll"><table><thead><tr><th>تاریخ</th><th>پزشک</th><th>وضعیت</th><th>خدمت</th><th>کالا</th><th>مبلغ خدمت</th><th>مبلغ کالا</th><th>تخفیف</th><th>پرداخت درج‌شده</th></tr></thead><tbody>';
 foreach($r['history'] as $item)echo '<tr><td>'.jd($item['event_date'],'yyyy/MM/dd').'</td><td>'.e($item['practitioner']?:'—').'</td><td>'.e($item['status']?:'—').'</td><td>'.e(patientReportExtract($item['services'])).'</td><td>'.e(patientReportExtract($item['goods'])).'</td><td>'.money($item['service_cost_toman']).'</td><td>'.money($item['goods_cost_toman']).'</td><td>'.money($item['discounts_toman']).'</td><td>'.money($item['payments_toman']).'</td></tr>';
 if(!$r['history'])echo '<tr><td colspan="9">مراجعه‌ای ثبت نشده است.</td></tr>';echo '</tbody></table></div></section>';
 if($r['unlinked']){echo '<section class="panel"><h2>کالاهای تحویلی بدون اتصال به نوبت</h2><div class="table-scroll"><table><thead><tr><th>تاریخ</th><th>کالا</th><th>تعداد</th><th>ارزش</th></tr></thead><tbody>';foreach($r['unlinked'] as $g)echo '<tr><td>'.jd($g['occurred_on']).'</td><td>'.e($g['name']).'</td><td>'.fa(abs((float)$g['quantity_delta'])).'</td><td>'.money(abs((int)$g['total_toman'])).'</td></tr>';echo '</tbody></table></div></section>';}
 echo '<section class="panel"><h2>پرداخت‌ها</h2><div class="table-scroll"><table><thead><tr><th>تاریخ</th><th>منبع</th><th>نوبت/دوره</th><th>مبلغ</th></tr></thead><tbody>';
 foreach($r['payments'] as $v)echo '<tr><td>'.jd($v['created_at'],'yyyy/MM/dd HH:mm').'</td><td>مسیحا</td><td>'.fa($v['episode_id']).'</td><td>'.money($v['amount_toman']).'</td></tr>';
 foreach($r['importPays'] as $v)echo '<tr><td>'.jd($v['tx_date']).'</td><td>بقراط</td><td>'.fa($v['appointment_code']?:'—').'</td><td>'.money($v['amount_toman']).'</td></tr>';
 echo '</tbody></table></div></section>';endLayout();
}
function patientReportExport(array $f,array $r):never {
 header('Content-Type: text/csv; charset=utf-8');header('Cache-Control: private, no-store');
 header('Content-Disposition: attachment; filename="patient-'.$f['pid'].'-report.csv"');
 echo "\xEF\xBB\xBF";$out=fopen('php://output','w');
 $emit=static function(array $cells)use($out):void{fputcsv($out,array_map(static function($v){$v=(string)($v??'');return preg_match('/^\s*[=+@\-\t\r]/u',$v)?"'".$v:$v;},$cells));};
 $emit(['تاریخ','منبع','شرح','تکمیل درمان','کالا','تعرفه جلسه','مبلغ کالا','پرداخت','تخفیف']);
 foreach($r['rows'] as $s){$goods=$r['bySession'][(int)$s['id']]??[];$emit([jd($s['starts_at']), 'مسیحا',trim(($s['service']??'').' '.implode('، ',$r['programs'][(int)$s['id']]??[])),($s['status']==='done'?'بله':'خیر'),implode('، ',array_column($goods,'name')),$s['price_snapshot'],array_sum(array_map(static fn($g)=>abs((int)$g['total_toman']),$goods)),'','']);}
 foreach($r['history'] as $s)$emit([jd($s['event_date']),'بقراط',patientReportExtract($s['services']),$s['status'],patientReportExtract($s['goods']),$s['service_cost_toman'],$s['goods_cost_toman'],$s['payments_toman'],$s['discounts_toman']]);
 foreach($r['payments'] as $x)$emit([jd($x['created_at']),'پرداخت مسیحا',$x['reference'],'','','','',$x['amount_toman'],'']);
 foreach($r['importPays'] as $x)$emit([jd($x['tx_date']),'پرداخت بقراط',$x['method'],'','','','',$x['amount_toman'],'']);
 $emit([]);$emit(['مانده ابتدا','بدهی','بستانکاری','مانده حساب','تخفیف ثبت‌شده','عدم اعمال فرضی تخفیف']);$s=$r['summary'];$emit([$s['opening'],$s['debt'],$s['credit'],$s['balance'],$s['discount'],$s['adjust']]);fclose($out);exit;
}
