<?php
// Local stdin/stdout IPC only. Never place under public/ or invoke config in logs.
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../app/bootstrap.php';require __DIR__.'/../app/import.php';
function response($v):never{echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function importSourceDate(?string $value):?string{
 $value=trim((string)$value);if($value==='')return null;
 $date=preg_split('/\s+/',$value,2)[0]??'';
 return MasihaJalali::fromPersianDate($date);
}
function importSourceDateTime(?string $value):?string{
 $value=trim((string)$value);if(!preg_match('/^(1[2345]\d{2}\/\d{1,2}\/\d{1,2})\s+([0-2]?\d):([0-5]?\d)$/',$value,$m))return null;
 $date=MasihaJalali::fromPersianDate($m[1]);if(!$date)return null;
 return $date.' '.sprintf('%02d:%02d:00',(int)$m[2],(int)$m[3]);
}
function importSourceKey(string $value):string{
 $value=str_replace(['ي','ك','‌'],['ی','ک',' '],trim($value));
 $value=mb_strtolower(preg_replace('/\s+/u',' ',$value));
 return hash('sha256',$value);
}
function importCatalogId(string $table,string $name):int{
 $name=mb_substr(trim($name),0,255);if($name==='')return 0;
 q("INSERT INTO $table(source_name,normalized_key) VALUES(?,?) ON DUPLICATE KEY UPDATE normalized_key=VALUES(normalized_key),last_seen=CURRENT_TIMESTAMP",[$name,importSourceKey($name)]);
 return (int)q("SELECT id FROM $table WHERE source_name=?",[$name])->fetchColumn();
}
function importServiceMapping(int $sourceId,string $name,?int $price):int{
 $mapped=(int)q('SELECT service_id FROM import_source_service_map WHERE source_service_id=?',[$sourceId])->fetchColumn();
 if($mapped)return $mapped;
 $category=(int)q("SELECT id FROM service_categories WHERE name='بقراط - واردشده' AND deleted=0 ORDER BY id LIMIT 1")->fetchColumn();
 if(!$category){q("INSERT INTO service_categories(name,active,deleted) VALUES('بقراط - واردشده',1,0)");$category=(int)$GLOBALS['db']->lastInsertId();}
 $service=(int)q('SELECT id FROM services WHERE name=? AND deleted=0 ORDER BY id LIMIT 1',[$name])->fetchColumn();
 if(!$service){q('INSERT INTO services(name,price,duration,active,category_id,deleted) VALUES(?,?,?,?,?,0)',[$name,max(0,(int)($price??0)),30,1,$category]);$service=(int)$GLOBALS['db']->lastInsertId();}
 q("INSERT INTO import_source_service_map(source_service_id,service_id,mapping_mode) VALUES(?,?,'auto') ON DUPLICATE KEY UPDATE service_id=VALUES(service_id)",[$sourceId,$service]);
 return $service;
}
function importInventoryItem(int $sourceId,string $name,?int $total,?float $quantity):int{
 $item=(int)q('SELECT id FROM inventory_items WHERE source_good_id=?',[$sourceId])->fetchColumn();
 if($item)return $item;
 $price=0;if($total!==null&&$quantity!==null&&$quantity>0)$price=(int)round($total/$quantity);
 q("INSERT INTO inventory_items(name,unit,sale_price_toman,active,source_good_id) VALUES(?,'عدد',?,1,?) ON DUPLICATE KEY UPDATE source_good_id=COALESCE(source_good_id,VALUES(source_good_id))",[$name,max(0,$price),$sourceId]);
 return (int)q('SELECT id FROM inventory_items WHERE source_good_id=? OR name=? ORDER BY source_good_id=? DESC,id LIMIT 1',[$sourceId,$name,$sourceId])->fetchColumn();
}
function importPractitionerMapping(string $name):array{
 $name=mb_substr(trim($name),0,255);if($name==='')return [0,0];
 $sourceId=importCatalogId('import_source_practitioners',$name);
 $staffId=(int)q('SELECT staff_id FROM import_source_practitioner_map WHERE source_practitioner_id=?',[$sourceId])->fetchColumn();
 if($staffId)return [$sourceId,$staffId];
 $staffId=(int)q("SELECT id FROM staff WHERE name=? AND role='therapist' AND deleted=0 ORDER BY active DESC,id LIMIT 1",[$name])->fetchColumn();
 if(!$staffId){
  $username='boghrat_'.substr(importSourceKey($name),0,16);
  $password=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
  q("INSERT INTO staff(username,name,password_hash,role,active,deleted) VALUES(?,?,?,'therapist',0,0)",[$username,$name,$password]);
  $staffId=(int)$GLOBALS['db']->lastInsertId();
  q('INSERT IGNORE INTO staff_permissions(staff_id,permissions) VALUES(?,?)',[$staffId,json_encode(defaultsFor(''),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
 }
 q("INSERT INTO import_source_practitioner_map(source_practitioner_id,staff_id,mapping_mode) VALUES(?,?,'auto') ON DUPLICATE KEY UPDATE staff_id=VALUES(staff_id)",[$sourceId,$staffId]);
 return [$sourceId,$staffId];
}
function importStatusCode(string $value):string{return match(trim($value)){'ویزیت شده'=>'done','غایب در مطب'=>'absent','کنسل شده'=>'cancelled',default=>'unknown'};}
function importModeCode(string $value):string{return match(trim($value)){'حضوری'=>'in_person','آنلاین'=>'online','تلفنی'=>'phone','تصویری'=>'video',default=>'unknown'};}
function importRegistrationCode(string $value):string{return match(trim($value)){'توسط مطب'=>'clinic','توسط بیمار'=>'patient','آنلاین'=>'online',default=>'unknown'};}
function importReasonCode(string $value):string{return match(trim($value)){'ویزیت'=>'visit','حضوری'=>'in_person','نرمال'=>'normal','ویزیت درمان'=>'treatment_visit','بیمار جدید'=>'new_patient','تمرین پلاس'=>'exercise_plus','ورزش درمانی'=>'exercise_therapy',default=>'other'};}
function importSyncOpeningLedger(int $pid):void{
 if(q("SELECT id FROM patient_account_ledger WHERE pid=? AND voided=0 AND entry_type<>'opening_balance' LIMIT 1",[$pid])->fetchColumn())return;
 $row=q("SELECT r.id,r.updated_at,f.outstanding_toman,f.credit_balance_toman
   FROM import_records r
   LEFT JOIN import_financial_summary f ON f.record_id=r.id
   WHERE r.pid=?
   ORDER BY r.updated_at DESC,r.id DESC LIMIT 1",[$pid])->fetch();
 if(!$row)return;
 $debt=max(0,(int)($row['outstanding_toman']??0));$credit=max(0,(int)($row['credit_balance_toman']??0));
 $date=substr((string)$row['updated_at'],0,10);if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))$date=date('Y-m-d');
 q("INSERT INTO patient_account_ledger(pid,entry_date,entry_type,debit_toman,credit_toman,reference,source_system,source_record_id,entry_key,created_by,voided)
    VALUES(? ,? ,'opening_balance',?,?, 'مانده افتتاحیه انتقال‌یافته از بقراط','boghrat',?,CONCAT('boghrat-opening:',?),0,0)
    ON DUPLICATE KEY UPDATE entry_date=VALUES(entry_date),debit_toman=VALUES(debit_toman),credit_toman=VALUES(credit_toman),source_record_id=VALUES(source_record_id),reference=VALUES(reference),voided=0",
   [$pid,$date,$debt,$credit,(int)$row['id'],$pid]);
}
function persistStructuredRecord(int $recordId,array $record):void{
 $profile=is_array($record['profile']??null)?$record['profile']:[];
 $currentPid=(int)(q('SELECT pid FROM import_records WHERE id=?',[$recordId])->fetchColumn()?:0);
 q('REPLACE INTO import_patient_profiles(record_id,full_name,mobile,phone_home,national_id,father_name,marital_status,birth_jalali,referral_source,source_registered_jalali,clinic_registered_jalali,address,medical_conditions,occupation,education,height_cm) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
  $recordId,mb_substr((string)($record['name']??''),0,255),mb_substr((string)($profile['mobile']??$record['mobile']??''),0,30),mb_substr((string)($profile['phone_home']??''),0,30),mb_substr((string)($profile['national_id']??$record['national_id']??''),0,20),mb_substr((string)($profile['father_name']??''),0,160),mb_substr((string)($profile['marital_status']??''),0,30),mb_substr((string)($profile['birth_jalali']??''),0,20),mb_substr((string)($profile['referral_source']??''),0,160),mb_substr((string)($profile['source_registered_jalali']??''),0,20),mb_substr((string)($profile['clinic_registered_jalali']??''),0,20),(string)($profile['address']??''),(string)($profile['medical_conditions']??''),mb_substr((string)($profile['occupation']??''),0,160),mb_substr((string)($profile['education']??''),0,160),isset($profile['height_cm'])&&is_numeric($profile['height_cm'])?(int)$profile['height_cm']:null
 ]);
 q('DELETE a FROM import_financial_allocations a JOIN import_financial_transactions t ON t.id=a.transaction_id WHERE t.record_id=?',[$recordId]);
 q("DELETE FROM inventory_movements WHERE source_record_id=? AND movement_type='historical_usage'",[$recordId]);
 foreach(['import_patient_events','import_financial_lines','import_event_services','import_event_goods','import_event_payments','import_patient_form_fields','import_financial_transactions'] as $table)q("DELETE FROM $table WHERE record_id=?",[$recordId]);
 q('DELETE FROM import_financial_summary WHERE record_id=?',[$recordId]);
 $summary=is_array($record['history_summary']??null)?$record['history_summary']:[];
 foreach(array_values($summary['events']??[]) as $eventNo=>$event){
  $event=is_array($event)?$event:[];
  $methods=is_array($event['payment_methods']??null)?$event['payment_methods']:[];
  q('INSERT INTO import_patient_events(record_id,event_no,appointment_code,date_jalali,event_date,time_text,registered_at_jalali,registered_at,registration_method,practitioner,reason,mode,status,debt_text,debt_toman,credit_toman,service_cost_toman,charge_total_toman,service_items_total_toman,goods_cost_toman,discounts_toman,payments_toman,settled_toman,payment_methods,notes,services,goods,payload) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
   $recordId,$eventNo,mb_substr((string)($event['appointment_code']??''),0,40),mb_substr((string)($event['date_jalali']??''),0,20),importSourceDate((string)($event['date_jalali']??'')),mb_substr((string)($event['time']??''),0,20),mb_substr((string)($event['registered_at_jalali']??''),0,40),importSourceDateTime((string)($event['registered_at_jalali']??'')),mb_substr((string)($event['registration_method']??''),0,160),mb_substr((string)($event['practitioner']??''),0,255),mb_substr((string)($event['reason']??''),0,255),mb_substr((string)($event['mode']??''),0,120),mb_substr((string)($event['status']??''),0,160),mb_substr((string)($event['debt']??''),0,255),isset($event['debt_toman'])?(int)$event['debt_toman']:null,isset($event['credit_toman'])?(int)$event['credit_toman']:null,isset($event['service_cost_toman'])?(int)$event['service_cost_toman']:null,isset($event['charge_total_toman'])?(int)$event['charge_total_toman']:null,isset($event['service_items_total_toman'])?(int)$event['service_items_total_toman']:null,isset($event['goods_cost_toman'])?(int)$event['goods_cost_toman']:null,isset($event['discounts_toman'])?(int)$event['discounts_toman']:null,isset($event['payments_toman'])?(int)$event['payments_toman']:null,isset($event['settled_toman'])?(int)$event['settled_toman']:null,json_encode($methods,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(string)($event['notes']??''),(string)($event['services']??''),(string)($event['goods']??''),json_encode($event,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
  ]);
  foreach(array_values($event['service_items']??[]) as $itemNo=>$item){$name=mb_substr(trim((string)($item['name']??'')),0,255);if($name!==''){$amount=isset($item['amount_toman'])&&$item['amount_toman']!==null?(int)$item['amount_toman']:null;$sourceId=importCatalogId('import_source_services',$name);importServiceMapping($sourceId,$name,$amount);q('INSERT INTO import_event_services(record_id,event_no,item_no,source_service_id,service_name,amount_toman) VALUES(?,?,?,?,?,?)',[$recordId,$eventNo,$itemNo,$sourceId?:null,$name,$amount]);}}
  foreach(array_values($event['goods_items']??[]) as $itemNo=>$item){$name=mb_substr(trim((string)($item['name']??'')),0,255);if($name!==''){$qty=is_numeric($item['quantity']??null)?(float)$item['quantity']:1.0;$amount=isset($item['amount_toman'])&&$item['amount_toman']!==null?(int)$item['amount_toman']:null;$sourceId=importCatalogId('import_source_goods',$name);$inventoryId=importInventoryItem($sourceId,$name,$amount,$qty);q('INSERT INTO import_event_goods(record_id,event_no,item_no,source_goods_id,goods_name,quantity,amount_toman) VALUES(?,?,?,?,?,?,?)',[$recordId,$eventNo,$itemNo,$sourceId?:null,$name,$qty,$amount]);if($inventoryId){$unitPrice=$amount!==null&&$qty>0?(int)round($amount/$qty):null;q("INSERT INTO inventory_movements(item_id,pid,source_record_id,event_no,item_no,appointment_code,movement_type,quantity_delta,unit_price_toman,total_toman,occurred_on,affects_stock,note,created_by) VALUES(?,?,?,?,?,?,'historical_usage',?,?,?,?,0,'مصرف تاریخی انتقال‌یافته از بقراط',0)",[$inventoryId,$currentPid?:null,$recordId,$eventNo,$itemNo,mb_substr((string)($event['appointment_code']??''),0,40),-$qty,$unitPrice,$amount,importSourceDate((string)($event['date_jalali']??''))]);}}}
  foreach($methods as $paymentNo=>$method){q('INSERT INTO import_event_payments(record_id,event_no,payment_no,amount_toman,method) VALUES(?,?,?,?,?)',[$recordId,$eventNo,$paymentNo,isset($method['amount_toman'])?(int)$method['amount_toman']:null,mb_substr((string)($method['method']??''),0,160)]);}
 }
 foreach(array_values($summary['financial_lines']??[]) as $lineNo=>$line){$line=mb_substr(trim((string)$line),0,1000);if($line!=='')q('INSERT INTO import_financial_lines(record_id,line_no,line_text,line_hash) VALUES(?,?,?,?)',[$recordId,$lineNo,$line,hash('sha256',$line)]);}
 $txCounters=[];
 foreach(array_values($summary['transactions']??[]) as $tx){
  $eventNo=is_numeric($tx['event_no']??null)?(int)$tx['event_no']:-1;
  $txNo=$txCounters[$eventNo]??0;$txCounters[$eventNo]=$txNo+1;
  q('INSERT INTO import_financial_transactions(record_id,event_no,tx_no,tx_type,amount_toman,method,date_jalali,tx_date,appointment_code,description,is_snapshot,payload) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[
   $recordId,$eventNo,$txNo,mb_substr((string)($tx['type']??'unknown'),0,40),
   array_key_exists('amount_toman',$tx)&&$tx['amount_toman']!==null?(int)$tx['amount_toman']:null,
   mb_substr((string)($tx['method']??''),0,160),mb_substr((string)($tx['date_jalali']??''),0,20),importSourceDate((string)($tx['date_jalali']??'')),
   mb_substr((string)($tx['appointment_code']??''),0,40),mb_substr((string)($tx['description']??''),0,500),
   !empty($tx['is_snapshot'])?1:0,json_encode($tx,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
  ]);
  $transactionId=(int)$GLOBALS['db']->lastInsertId();
  foreach(array_values($tx['allocations']??[]) as $allocation){q('INSERT INTO import_financial_allocations(transaction_id,record_id,event_no,appointment_code,amount_toman) VALUES(?,?,?,?,?)',[$transactionId,$recordId,(int)($allocation['event_no']??-1),mb_substr((string)($allocation['appointment_code']??''),0,40),(int)($allocation['amount_toman']??0)]);}
 }
 $finance=is_array($summary['financial_summary']??null)?$summary['financial_summary']:[];
 if($finance)q('INSERT INTO import_financial_summary(record_id,service_revenue_toman,goods_revenue_toman,payments_toman,refunds_toman,discounts_toman,difference_toman,outstanding_toman,credit_balance_toman,payload) VALUES(?,?,?,?,?,?,?,?,?,?)',[
  $recordId,$finance['service_revenue_toman']??null,$finance['goods_revenue_toman']??null,$finance['payments_toman']??null,$finance['refunds_toman']??null,$finance['discounts_toman']??null,$finance['difference_toman']??null,$finance['outstanding_toman']??null,$finance['credit_balance_toman']??null,json_encode($finance,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
 ]);
 $formGroups=[];$formNo=0;
 foreach(array_values($record['form_fields']??[]) as $fieldNo=>$field){$formName=mb_substr((string)($field['form_name']??''),0,160);if(!array_key_exists($formName,$formGroups))$formGroups[$formName]=$formNo++;q('INSERT INTO import_patient_form_fields(record_id,form_no,field_no,form_name,field_name,field_value) VALUES(?,?,?,?,?,?)',[$recordId,$formGroups[$formName],$fieldNo,$formName,mb_substr((string)($field['field_name']??''),0,160),(string)($field['field_value']??'')]);}
}
try{$in=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$op=$in['op']??'';
 if($op==='audit_config'){$c=importConfig();if(!$c['username']||!$c['password_cipher']||!$c['clinic_name'])response(['active'=>false]);$c['password']=importDecrypt($c['password_cipher']);unset($c['password_cipher']);response(['active'=>true,'connection'=>$c,'storage'=>$config['storage']]);}
 if($op==='config'){$r=q("SELECT * FROM import_runs WHERE status='running' ORDER BY id LIMIT 1")->fetch();if(!$r)response(['active'=>false]);$c=importConfig();if($r['account_key']!==importAccount($c)||!$c['password_cipher']){q("UPDATE import_runs SET status='blocked',message='تنظیمات حساب تغییر کرده یا رمز ذخیره نشده است.' WHERE id=?",[$r['id']]);response(['active'=>false]);}$c['password']=importDecrypt($c['password_cipher']);unset($c['password_cipher']);response(['active'=>true,'run'=>$r,'connection'=>$c,'storage'=>$config['storage']]);}
 if($op==='rebuild'){$recordId=(int)($in['record_id']??0);$record=$in['record']??[];$row=q('SELECT r.*,COALESCE(ir.created_by,0) actor FROM import_records r LEFT JOIN import_runs ir ON ir.id=r.run_id WHERE r.id=?',[$recordId])->fetch();if(!$row)response(['error'=>'missing']);$db->beginTransaction();persistStructuredRecord($recordId,$record);if($row['pid']&&function_exists('importApplyProfileArrayToPatient')){importApplyProfileArrayToPatient((int)$row['pid'],is_array($record['profile']??null)?$record['profile']:[],(int)$row['actor']);q("UPDATE inventory_movements SET pid=? WHERE source_record_id=? AND movement_type='historical_usage'",[(int)$row['pid'],$recordId]);importSyncOpeningLedger((int)$row['pid']);}$db->commit();response(['ok'=>true,'pid'=>$row['pid']?(int)$row['pid']:null]);}
 $id=(int)($in['run_id']??0);$r=q('SELECT * FROM import_runs WHERE id=?',[$id])->fetch();if(!$r)response(['error'=>'missing']);
 if($op==='cursor'){$p=max(1,(int)($in['page']??1));$row=max(0,(int)($in['row']??0));q("UPDATE import_runs SET page_no=?,row_no=? WHERE id=? AND status='running'",[$p,$row,$id]);response(['ok'=>true]);}
 if($op==='state'){response(['run'=>$r,'free'=>disk_free_space($config['storage'])]);}
 if($op==='stop'){$status=in_array($in['status']??'',['blocked','error','complete','space'])?$in['status']:'error';$messages=['blocked'=>'ورود، تأیید انسانی یا سطح دسترسی نیاز به بررسی دارد.','error'=>'ساختار صفحه یا اتصال معتبر نبود؛ برای جلوگیری از جاافتادن پرونده انتقال متوقف شد.','complete'=>'به پایان صفحه‌بندی فهرست رسیدیم. رکوردهای آرشیو را بررسی کنید.','space'=>'سقف آرشیو یا حداقل فضای آزاد دیسک رعایت نشده؛ انتقال متوقف شد.'];q("UPDATE import_runs SET status=?,message=? WHERE id=? AND status='running'",[$status,$messages[$status],$id]);q('INSERT INTO import_events(run_id,code,page_no,row_no) VALUES(?,?,?,?)',[$id,preg_replace('/[^a-z_]/','',substr((string)($in['code']??$status),0,40)),$r['page_no'],$r['row_no']]);response(['ok'=>true]);}
 if($op==='reserve'){$db->beginTransaction();$r=q('SELECT * FROM import_runs WHERE id=? FOR UPDATE',[$id])->fetch();if($r['status']!=='running'){$db->commit();response(['ok'=>false,'stop'=>true]);}if($r['processed']>=$r['total_limit']){q("UPDATE import_runs SET status='limit',message='سقف کل بررسی پرونده‌ها تکمیل شد.' WHERE id=?",[$id]);$db->commit();response(['ok'=>false,'stop'=>true]);}q('INSERT IGNORE INTO import_quota(account_key,window_start,attempts,next_at) VALUES(?,NOW(),0,NOW())',[$r['account_key']]);$quota=q('SELECT *,TIMESTAMPDIFF(SECOND,NOW(),next_at) wait_seconds,TIMESTAMPDIFF(SECOND,window_start,NOW()) elapsed FROM import_quota WHERE account_key=? FOR UPDATE',[$r['account_key']])->fetch();$attempts=(int)$quota['attempts'];if((int)$quota['elapsed']>=3600){$attempts=0;q('UPDATE import_quota SET attempts=0,window_start=NOW() WHERE account_key=?',[$r['account_key']]);}$wait=max(0,(int)$quota['wait_seconds']);if((int)$r['hourly_limit']>0&&$attempts>=(int)$r['hourly_limit'])$wait=max($wait,3600-(int)$quota['elapsed']);if($wait>0){q('UPDATE import_runs SET not_before=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE id=?',[$wait,$id]);$db->commit();response(['ok'=>false,'wait'=>$wait]);}$used=(int)q('SELECT (SELECT COALESCE(SUM(bytes),0) FROM import_records)+(SELECT COALESCE(SUM(bytes),0) FROM import_versions)')->fetchColumn();if($used>=(int)$r['storage_mb']*1048576||disk_free_space($config['storage'])<536870912){q("UPDATE import_runs SET status='space',message='فضای کافی برای ادامه نیست.' WHERE id=?",[$id]);$db->commit();response(['ok'=>false,'stop'=>true]);}$delay=max(3,min(120,(int)($r['delay_seconds']??8)));q('UPDATE import_quota SET attempts=attempts+1,next_at=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE account_key=?',[$delay,$r['account_key']]);q('UPDATE import_runs SET not_before=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE id=?',[$delay,$id]);$db->commit();response(['ok'=>true]);}
 if($op==='save'){$record=$in['record']??[];$payload=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$bytes=strlen($payload);if($bytes>1048576)throw new RuntimeException('record_size');$key=$record['source_key']??'';if(!preg_match('/^[0-9a-f]{64}$/D',$key))throw new RuntimeException('key');$db->beginTransaction();$r=q('SELECT * FROM import_runs WHERE id=? FOR UPDATE',[$id])->fetch();if(!in_array($r['status'],['running','paused'])){$db->commit();response(['ok'=>false,'stop'=>true]);}$existing=q('SELECT id,bytes,payload,payload_hash FROM import_records WHERE account_key=? AND source_key=? FOR UPDATE',[$r['account_key'],$key])->fetch();$used=(int)q('SELECT (SELECT COALESCE(SUM(bytes),0) FROM import_records)+(SELECT COALESCE(SUM(bytes),0) FROM import_versions)')->fetchColumn();if($used+$bytes>(int)$r['storage_mb']*1048576){q("UPDATE import_runs SET status='space',message='سقف حجم آرشیو تکمیل شد؛ رکورد جاری ذخیره نشد.' WHERE id=?",[$id]);$db->commit();response(['ok'=>false,'stop'=>true]);}$vals=[$record['page'],$record['row'],mb_substr($record['name']??'',0,255),substr($record['national_id']??'',0,20),substr($record['mobile']??'',0,30),in_array($record['confidence']??'',['national','contact'])?$record['confidence']:'review',$payload,hash('sha256',$payload),$bytes,($record['complete']??false)?'tabs_saved':'partial'];if($existing&&$existing['payload_hash']!==hash('sha256',$payload))q('INSERT INTO import_versions(record_id,payload,bytes) VALUES(?,?,?)',[$existing['id'],$existing['payload'],$existing['bytes']]);if($existing)q('UPDATE import_records SET source_page=?,source_row=?,display_name=?,national_id=?,mobile=?,confidence=?,payload=?,payload_hash=?,bytes=?,completeness=? WHERE id=?',[...$vals,$existing['id']]);else q('INSERT INTO import_records(source_page,source_row,display_name,national_id,mobile,confidence,payload,payload_hash,bytes,completeness,account_key,source_key,run_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$vals,$r['account_key'],$key,$id]);$recordId=$existing?(int)$existing['id']:(int)$db->lastInsertId();persistStructuredRecord($recordId,$record);
$registration=null;if(importPatientAutoEnabled())$registration=importRegisterRecord($recordId,(int)$r['created_by']);$linkedPid=(int)(q('SELECT pid FROM import_records WHERE id=?',[$recordId])->fetchColumn()?:0);if($linkedPid){q("UPDATE inventory_movements SET pid=? WHERE source_record_id=? AND movement_type='historical_usage'",[$linkedPid,$recordId]);importSyncOpeningLedger($linkedPid);}$nextPage=max(1,(int)$in['next_page']);$nextRow=max(0,(int)$in['next_row']);q('UPDATE import_runs SET processed=processed+1,saved=saved+?,duplicates=duplicates+?,page_no=?,row_no=?,message=? WHERE id=?',[$existing?0:1,$existing?1:0,$nextPage,$nextRow,'رکورد ذخیره شد؛ در انتظار سهمیه بعدی.',$id]);$db->commit();response(['ok'=>true,'duplicate'=>(bool)$existing,'registration'=>$registration]);}
 response(['error'=>'operation']);
}catch(Throwable $ex){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,'import bridge failed: '.get_class($ex)."\n");exit(1);}
