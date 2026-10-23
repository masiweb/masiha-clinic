<?php
declare(strict_types=1);
/**
 * Unified patient invoice export. Financial figures are drawn from the existing
 * patientReportDetails aggregates; neither session tariffs nor import detail
 * lines are added to the balance again.
 */
function patientInvoiceDocument(array $filter,array $report):array{
 $pid=(int)$filter['pid'];
 $patient=patientName($report['person']);
 $meta=[
  'clinic'=>setting('clinic_name','کلینیک مسیحا'),
  'patient'=>$patient,
  'pid'=>(string)$pid,
  'from'=>jd($filter['from'],'yyyy/MM/dd'),
  'to'=>jd($filter['to'],'yyyy/MM/dd'),
  'issue_date'=>jd(date('Y-m-d'),'yyyy/MM/dd'),
  'mode'=>$filter['mode']==='services'?'فقط جلسات و خدمات دریافت‌شده':'تمام نوبت‌ها',
  'discount'=>$filter['discount']==='exclude'?'عدم اعمال تخفیف (محاسبه فرضی)':'با اعمال تخفیف ثبت‌شده'
 ];
 $visits=[];$payments=[];
 foreach($report['rows'] as $row){
  $goodRows=$report['bySession'][(int)$row['id']]??[];
  $goods=[];$goodValue=0;
  foreach($goodRows as $g){
   $goods[]=trim((string)$g['name']).' × '.fa(abs((float)$g['quantity_delta'])).' '.(string)($g['unit']??'');
   $goodValue+=abs((int)$g['total_toman']);
  }
  if(trim((string)$row['equipment'])!=='')$goods[]=$row['equipment'];
  $complete=$row['status']==='done'||$row['treatment_finished_at']!==null||in_array($row['state'],['visited','discharged'],true);
  $service=trim(implode('، ',array_filter([
   (string)($row['service']??''),
   implode('، ',$report['programs'][(int)$row['id']]??[]),
   (string)($row['treatment']??'')
  ])));
  $visits[]=[
   '_sort'=>(string)$row['starts_at'],
   'date'=>jd($row['starts_at'],'yyyy/MM/dd HH:mm'),
   'source'=>'مسیحا','code'=>(string)$row['id'],
   'doctor'=>(string)($row['doctor']??''),
   'status'=>$complete?'تکمیل درمان':'ثبت / در حال درمان',
   'service'=>$service?:'—',
   'goods'=>implode('، ',$goods)?:'—',
   'service_amount'=>(int)($row['price_snapshot']??0),
   'goods_amount'=>$goodValue,'discount'=>0,'payment'=>0
  ];
 }
 foreach($report['history'] as $row){
  $visits[]=[
   '_sort'=>(string)$row['event_date'].' 00:00:00',
   'date'=>jd($row['event_date'],'yyyy/MM/dd'),
   'source'=>'بقراط','code'=>(string)($row['appointment_code']??''),
   'doctor'=>(string)($row['practitioner']??''),
   'status'=>(string)($row['status']??''),
   'service'=>patientReportExtract($row['services']),
   'goods'=>patientReportExtract($row['goods']),
   'service_amount'=>(int)($row['service_cost_toman']??0),
   'goods_amount'=>(int)($row['goods_cost_toman']??0),
   'discount'=>(int)($row['discounts_toman']??0),
   'payment'=>(int)($row['payments_toman']??0)
  ];
 }
 foreach($report['unlinked'] as $row){
  $visits[]=[
   '_sort'=>(string)$row['occurred_on'].' 00:00:00',
   'date'=>jd($row['occurred_on'],'yyyy/MM/dd'),
   'source'=>'انبار','code'=>(string)($row['appointment_code']??''),
   'doctor'=>'','status'=>'تحویل کالا','service'=>'کالای تحویلی بدون اتصال به جلسه',
   'goods'=>(string)$row['name'].' × '.fa(abs((float)$row['quantity_delta'])).' '.($row['unit']??''),
   'service_amount'=>0,'goods_amount'=>abs((int)($row['total_toman']??0)),
   'discount'=>0,'payment'=>0
  ];
 }
 usort($visits,static fn($a,$b)=>strcmp($a['_sort'],$b['_sort']));
 foreach($visits as &$visit)unset($visit['_sort']);
 unset($visit);
 foreach($report['payments'] as $row){
  $payments[]=[
   'date'=>jd($row['created_at'],'yyyy/MM/dd HH:mm'),
   'source'=>'مسیحا',
   'reference'=>(string)$row['episode_id'],
   'description'=>(string)($row['reference']??'پرداخت دوره درمان'),
   'amount'=>(int)$row['amount_toman']
  ];
 }
 foreach($report['importPays'] as $row){
  $payments[]=[
   'date'=>jd($row['tx_date'],'yyyy/MM/dd'),
   'source'=>'بقراط',
   'reference'=>(string)($row['appointment_code']??''),
   'description'=>(string)($row['method']??'پرداخت انتقالی'),
   'amount'=>(int)$row['amount_toman']
  ];
 }
 return ['meta'=>$meta,'sections'=>['visits'=>$visits,'payments'=>$payments],
  'summary'=>$report['summary']];
}
function patientInvoiceCsv(array $document,int $pid):never{
 header('Content-Type: text/csv; charset=utf-8');
 header('Content-Disposition: attachment; filename="patient-'.$pid.'-invoice.csv"');
 header('Cache-Control: private, no-store, max-age=0');
 header('X-Content-Type-Options: nosniff');
 echo "\xEF\xBB\xBF";
 $h=fopen('php://output','wb');
 $write=static function(array $items)use($h){
  $items=array_map(static function($v){
   if(is_int($v)||is_float($v))return (string)$v;
   $v=(string)($v??'');
   return preg_match('/^\s*[=+@\-\t\r]/u',$v)?"'".$v:$v;
  },$items);
  fputcsv($h,$items);
 };
 $m=$document['meta'];$write(['صورتحساب و ریز خدمات درمانی مراجع']);$write(['کلینیک',$m['clinic'],'تاریخ صدور',$m['issue_date']]);
 $write(['مراجع',$m['patient'],'شماره پرونده',$m['pid']]);
 $write(['از تاریخ',$m['from'],'تا تاریخ',$m['to'],'مبنای گزارش',$m['mode'],'تخفیف',$m['discount']]);
 $write([]);$write(['ریز نوبت‌ها و خدمات']);
 $write(['تاریخ','شماره نوبت','درمانگر','وضعیت','خدمات و برنامه‌های درمانی','تجهیزات','مبلغ خدمات/جلسه (تومان)','ارزش کالای تحویلی (تومان)','تخفیف','پرداخت']);
 foreach($document['sections']['visits'] as $x){
  $write([$x['date'],$x['code'],$x['doctor'],$x['status'],$x['service'],$x['goods'],$x['service_amount'],$x['goods_amount'],$x['discount'],$x['payment']]);
 }
 $write([]);$write(['ریز دریافت‌ها و پرداخت‌ها']);
 $write(['تاریخ','دوره/نوبت','شرح','مبلغ (تومان)']);
 foreach($document['sections']['payments'] as $x)$write([$x['date'],$x['reference'],$x['description'],$x['amount']]);
 $write([]);$write(['جمع‌بندی حساب','مبلغ (تومان)']);
 foreach(['مانده ابتدای دوره'=>'opening','هزینه دوره درمان'=>'billed','پرداخت داخلی'=>'paid',
          'بدهکار بقراط'=>'legacy_debit','بستانکار بقراط'=>'legacy_credit',
          'تخفیف ثبت‌شده'=>'discount','اثر حذف تخفیف'=>'adjust',
          'تغییر خالص دوره'=>'change','بدهی'=>'debt',
          'بستانکاری'=>'credit','مانده نهایی حساب'=>'balance'] as $label=>$key)$write([$label,(int)$document['summary'][$key]]);
 $write([]);$write(['یادداشت','مبالغ جلسه، اقلام تحویلی و پرداخت‌های سوابق بقراط اطلاعاتی‌اند و در مانده حساب دوباره محاسبه نشده‌اند.']);
 fclose($h);exit;
}
function patientInvoiceExport(array $filter,array $report,string $format):never{
 if(!in_array($format,['csv','xlsx','pdf'],true))throw new DomainException('نوع خروجی معتبر نیست.');
 $document=patientInvoiceDocument($filter,$report);
 if($format==='csv')patientInvoiceCsv($document,(int)$filter['pid']);
 $python='/opt/masiha-clinic-report/.venv/bin/python';
 $script=__DIR__.'/../deploy/patient-invoice-export.py';
 if(!is_file($python)||!is_file($script))throw new RuntimeException('موتور خروجی نصب نشده است.');
 $command=[$python,$script,$format];
 $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
 $process=proc_open($command,$spec,$pipes,null,['PATH'=>'/usr/bin:/bin','HOME'=>'/tmp']);
 if(!is_resource($process))throw new RuntimeException('اجرای موتور خروجی ممکن نیست.');
 $json=json_encode($document,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
 if(strlen($json)>8000000){
  fclose($pipes[0]);fclose($pipes[1]);fclose($pipes[2]);proc_terminate($process);proc_close($process);
  throw new DomainException('گزارش خیلی بزرگ است؛ بازه کوتاه‌تری انتخاب کنید.');
 }
 $offset=0;$total=strlen($json);
 while($offset<$total){
  $written=fwrite($pipes[0],substr($json,$offset,8192));
  if($written===false||$written===0)break;
  $offset+=$written;
 }
 fclose($pipes[0]);
 $binary=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $error=stream_get_contents($pipes[2]);fclose($pipes[2]);
 $exit=proc_close($process);
 if($exit!==0||$offset!==$total||!is_string($binary)||strlen($binary)<80){
  error_log('Patient invoice export failed: format='.$format.' exit='.$exit.' '.substr((string)$error,0,450));
  throw new RuntimeException('ساخت فایل خروجی انجام نشد؛ لطفاً دوباره تلاش کنید.');
 }
 $magic=$format==='pdf'?'%PDF-':"PK\x03\x04";
 if(strncmp($binary,$magic,strlen($magic))!==0)throw new RuntimeException('قالب فایل خروجی معتبر نیست.');
 header('Content-Type: '.($format==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
 header('Content-Disposition: attachment; filename="patient-'.(int)$filter['pid'].'-invoice.'.$format.'"');
 header('Cache-Control: private, no-store, max-age=0');
 header('X-Content-Type-Options: nosniff');
 header('Content-Length: '.strlen($binary));
 echo $binary;exit;
}
