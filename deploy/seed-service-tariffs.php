<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require __DIR__.'/../app/bootstrap.php';

$rows=q("SELECT s.id,s.name,s.price
         FROM services s
         WHERE s.deleted=0
           AND NOT EXISTS(SELECT 1 FROM service_tariffs t WHERE t.service_id=s.id AND t.deleted=0)
         ORDER BY s.id")->fetchAll();

$seeded=0;$skipped=0;
foreach($rows as $s){
 $price=(int)$s['price'];
 if($price<=0){$skipped++;continue;}
 $date=q("SELECT MAX(e.event_date)
          FROM import_event_services x
          JOIN import_patient_events e ON e.record_id=x.record_id AND e.event_no=x.event_no
          WHERE x.service_id=? AND x.amount_toman=?",[(int)$s['id'],$price])->fetchColumn();
 if(!$date||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$date))$date=date('Y-m-d');
 q("INSERT INTO service_tariffs(service_id,tariff_type,title,contract_name,price_toman,effective_from,effective_to,active,source_system,created_by)
    VALUES(?,'normal','تعرفه پایه انتقال از بقراط','',?,?,NULL,1,'boghrat',0)",[(int)$s['id'],$price,$date]);
 $seeded++;
}
echo "SEEDED=$seeded SKIPPED_ZERO_PRICE=$skipped\n";
