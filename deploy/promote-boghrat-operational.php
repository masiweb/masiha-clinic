<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require __DIR__.'/../app/bootstrap.php';

$db->beginTransaction();
try{
    $category=(int)q("SELECT id FROM service_categories WHERE name='بقراط - واردشده' AND deleted=0 ORDER BY id LIMIT 1")->fetchColumn();
    if(!$category){
        q("INSERT INTO service_categories(name,active,deleted) VALUES('بقراط - واردشده',1,0)");
        $category=(int)$db->lastInsertId();
    }

    $serviceCount=0;
    foreach(q("SELECT id,source_name FROM import_source_services ORDER BY id")->fetchAll() as $src){
        $mapped=(int)q('SELECT service_id FROM import_source_service_map WHERE source_service_id=?',[$src['id']])->fetchColumn();
        if(!$mapped){
            $price=q("SELECT x.amount_toman
                FROM import_event_services x
                JOIN import_patient_events e ON e.record_id=x.record_id AND e.event_no=x.event_no
                WHERE x.source_service_id=? AND x.amount_toman IS NOT NULL
                ORDER BY e.event_date DESC,x.id DESC LIMIT 1",[$src['id']])->fetchColumn();
            $service=(int)q('SELECT id FROM services WHERE name=? AND deleted=0 ORDER BY id LIMIT 1',[$src['source_name']])->fetchColumn();
            if(!$service){
                q('INSERT INTO services(name,price,duration,active,category_id,deleted) VALUES(?,?,?,?,?,0)',[
                    $src['source_name'],max(0,(int)($price?:0)),30,1,$category
                ]);
                $service=(int)$db->lastInsertId();
            }
            q("INSERT INTO import_source_service_map(source_service_id,service_id,mapping_mode) VALUES(?,?,'auto')",[$src['id'],$service]);
        }
        $serviceCount++;
    }

    $goodsCount=0;
    foreach(q("SELECT id,source_name FROM import_source_goods ORDER BY id")->fetchAll() as $src){
        $item=(int)q('SELECT id FROM inventory_items WHERE source_good_id=?',[$src['id']])->fetchColumn();
        if(!$item){
            $price=q("SELECT CASE WHEN x.quantity>0 AND x.amount_toman IS NOT NULL THEN ROUND(x.amount_toman/x.quantity) ELSE NULL END
                FROM import_event_goods x
                JOIN import_patient_events e ON e.record_id=x.record_id AND e.event_no=x.event_no
                WHERE x.source_goods_id=?
                ORDER BY (x.amount_toman IS NOT NULL) DESC,e.event_date DESC,x.id DESC LIMIT 1",[$src['id']])->fetchColumn();
            $existing=(int)q('SELECT id FROM inventory_items WHERE name=? ORDER BY id LIMIT 1',[$src['source_name']])->fetchColumn();
            if($existing){
                q('UPDATE inventory_items SET source_good_id=COALESCE(source_good_id,?),sale_price_toman=IF(sale_price_toman=0,?,sale_price_toman) WHERE id=?',[$src['id'],max(0,(int)($price?:0)),$existing]);
            }else{
                q("INSERT INTO inventory_items(name,unit,sale_price_toman,active,source_good_id) VALUES(?,'عدد',?,1,?)",[$src['source_name'],max(0,(int)($price?:0)),$src['id']]);
            }
        }
        $goodsCount++;
    }

    $openingCount=0;
    foreach(q('SELECT pid FROM patients WHERE active=1 ORDER BY pid')->fetchAll() as $patient){
        $pid=(int)$patient['pid'];
        $row=q("SELECT r.id,r.updated_at,f.outstanding_toman,f.credit_balance_toman
            FROM import_records r
            LEFT JOIN import_financial_summary f ON f.record_id=r.id
            WHERE r.pid=?
            ORDER BY r.updated_at DESC,r.id DESC LIMIT 1",[$pid])->fetch();
        if(!$row)continue;
        $debt=max(0,(int)($row['outstanding_toman']??0));
        $credit=max(0,(int)($row['credit_balance_toman']??0));
        $date=substr((string)$row['updated_at'],0,10);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))$date=date('Y-m-d');
        $locked=(bool)q("SELECT id FROM patient_account_ledger WHERE pid=? AND voided=0 AND entry_type<>'opening_balance' LIMIT 1",[$pid])->fetchColumn();
        if(!$locked){
            q("INSERT INTO patient_account_ledger(pid,entry_date,entry_type,debit_toman,credit_toman,reference,source_system,source_record_id,entry_key,created_by,voided)
               VALUES(? ,? ,'opening_balance',?,?, 'مانده افتتاحیه انتقال‌یافته از بقراط','boghrat',?,CONCAT('boghrat-opening:',?),0,0)
               ON DUPLICATE KEY UPDATE entry_date=VALUES(entry_date),debit_toman=VALUES(debit_toman),credit_toman=VALUES(credit_toman),source_record_id=VALUES(source_record_id),reference=VALUES(reference),voided=0",
              [$pid,$date,$debt,$credit,(int)$row['id'],$pid]);
        }
        $openingCount++;
    }

    $db->commit();
    echo "PROMOTE_COMPLETE services=$serviceCount goods=$goodsCount openings=$openingCount\n";
}catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR,"PROMOTE_FAILED ".get_class($e)."\n");
    exit(2);
}
