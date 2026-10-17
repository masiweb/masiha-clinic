<?php
declare(strict_types=1);
function stockUnits(string $value):int{
 $value=MasihaOtp::digits(trim($value));if(!preg_match('/^(-?)(\d{1,9})(?:\.(\d{1,3}))?$/D',$value,$m))throw new DomainException('مقدار باید حداکثر سه رقم اعشار داشته باشد.');
 return ($m[1]==='-'?-1:1)*((int)$m[2]*1000+(int)str_pad($m[3]??'',3,'0'));
}
function stockDecimal(int $units):string{return ($units<0?'-':'').intdiv(abs($units),1000).'.'.str_pad((string)(abs($units)%1000),3,'0',STR_PAD_LEFT);}
function stockDate(string $date):void{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new DomainException('تاریخ معتبر نیست.');}
function stockBalance(array $item):int{
 if($item['opening_quantity']===null)throw new DomainException('موجودی شروع کالا نامعلوم است.');
 $n=stockUnits((string)$item['opening_quantity']);foreach(q('SELECT quantity_delta FROM inventory_movements WHERE item_id=? AND affects_stock=1 FOR UPDATE',[$item['id']])->fetchAll(PDO::FETCH_COLUMN) as $v)$n+=stockUnits((string)$v);return $n;
}
function stockAudit(int $item,string $action,array $details):void{q('INSERT INTO stock_events(item_id,actor_id,action,details) VALUES(?,?,?,?)',[$item,user()['id'],$action,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);}
function stockOpening(int $id,string $quantity,string $date):void{
 global $db;if(!allowed('inventory'))throw new DomainException('اجازه مدیریت انبار ندارید.');$units=stockUnits($quantity);stockDate($date);if($units<0)throw new DomainException('موجودی منفی مجاز نیست.');
 $db->beginTransaction();try{$item=q('SELECT * FROM inventory_items WHERE id=? FOR UPDATE',[$id])->fetch();if(!$item)throw new DomainException('کالا پیدا نشد.');if($item['opening_quantity']!==null)throw new DomainException('موجودی شروع ثبت شده است؛ تغییر بعدی را با تعدیل و دلیل ثبت کنید.');q('UPDATE inventory_items SET opening_quantity=?,opening_as_of=? WHERE id=?',[stockDecimal($units),$date,$id]);stockAudit($id,'opening',['quantity'=>stockDecimal($units),'date'=>$date]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function stockMove(int $id,string $quantity,string $date,string $note,string $token,int $session=0,int $reverse=0):int{
 global $db;$delta=stockUnits($quantity);stockDate($date);$note=trim($note);
 if(!$delta||$note===''||mb_strlen($note)>500||!preg_match('/^[a-f0-9]{32}$/D',$token))throw new DomainException('مقدار، شرح و شناسه درخواست معتبر وارد کنید.');
 if(!$session&&!allowed('inventory'))throw new DomainException('اجازه تعدیل انبار ندارید.');
 if($session&&!allowed('inventory.consume')&&!allowed('inventory'))throw new DomainException('اجازه ثبت مصرف ندارید.');
 $db->beginTransaction();try{
  $pid=null;if($session){$s=q('SELECT s.*,ep.pid FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id WHERE s.id=? FOR UPDATE',[$session])->fetch();if(!$s||!patientAllowed($s['pid']))throw new DomainException('به این مراجعه دسترسی ندارید.');if(!allowed('inventory'))workflowAccess($s,true);$pid=$s['pid'];if(!$reverse&&in_array($s['status'],['absent','cancelled'],true))throw new DomainException('مصرف برای مراجعه لغوشده یا غیبت مجاز نیست.');if(!$reverse&&$delta>0)throw new DomainException('برای مصرف، مقدار خروج کالا لازم است.');}
  $item=q('SELECT * FROM inventory_items WHERE id=? FOR UPDATE',[$id])->fetch();if(!$item||(!$item['active']&&!$reverse))throw new DomainException('کالای فعال انتخاب کنید.');
  $existing=q('SELECT * FROM inventory_movements WHERE request_key=?',[$token])->fetch();if($existing){if((int)$existing['item_id']!==$id||(int)$existing['session_id']!==$session||stockUnits($existing['quantity_delta'])!==$delta||(int)$existing['reversal_of']!==$reverse||$existing['occurred_on']!==$date||$existing['note']!==$note)throw new DomainException('شناسه درخواست برای عملیات دیگری استفاده شده است.');$db->commit();return (int)$existing['id'];}
  if($date<$item['opening_as_of'])throw new DomainException('تاریخ حرکت قبل از موجودی شروع است.');
  if($reverse){if(!allowed('inventory'))throw new DomainException('بازگشت مصرف فقط با مجوز انبار مجاز است.');$old=q('SELECT * FROM inventory_movements WHERE id=? FOR UPDATE',[$reverse])->fetch();if(!$old||(int)$old['item_id']!==$id||(int)$old['session_id']!==$session||$old['movement_type']!=='consumption'||$delta!==-stockUnits($old['quantity_delta'])||q('SELECT id FROM inventory_movements WHERE reversal_of=?',[$reverse])->fetchColumn())throw new DomainException('این مصرف قابل برگشت نیست یا قبلاً برگشته است.');}
  $before=stockBalance($item);if($before+$delta<0)throw new DomainException('موجودی کالا کافی نیست.');
  q('INSERT INTO inventory_movements(item_id,pid,session_id,movement_type,quantity_delta,occurred_on,affects_stock,note,created_by,request_key,reversal_of) VALUES(?,?,?,?,?,?,1,?,?,?,?)',[$id,$pid,$session?:null,$reverse?'consumption_return':($session?'consumption':'adjustment'),stockDecimal($delta),$date,$note,user()['id'],$token,$reverse?:null]);$move=(int)$db->lastInsertId();stockAudit($id,'movement',['movement_id'=>$move,'session_id'=>$session?:null,'before'=>stockDecimal($before),'after'=>stockDecimal($before+$delta),'reason'=>$note]);$db->commit();return $move;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function stockVisitView(int $session):void{
 if(!allowed('inventory')&&!allowed('inventory.consume'))return;
 echo '<section class="panel form-panel"><h2>کالای مصرفی مراجعه</h2>';formstart('stock_consume');hidden('session_id',$session);hidden('request_key',bin2hex(random_bytes(16)));selectfield('item_id','کالا',options('inventory_items','active=1'),'','',true);field('quantity','مقدار مصرف',1,'number','حداکثر سه رقم اعشار.',true,false,'min="0.001" step="0.001"');field('note','شرح مصرف','','text','',true);formend('ثبت مصرف','/visit?id='.$session);
 foreach(q("SELECT m.*,i.name FROM inventory_movements m JOIN inventory_items i ON i.id=m.item_id WHERE m.session_id=? ORDER BY m.id",[$session])->fetchAll() as $m){echo '<p>'.e($m['name']).' · '.fa($m['quantity_delta']).' · '.e($m['note']).'</p>';if(allowed('inventory')&&$m['movement_type']==='consumption'&&!q('SELECT id FROM inventory_movements WHERE reversal_of=?',[$m['id']])->fetchColumn()){formstart('stock_return');hidden('session_id',$session);hidden('movement_id',$m['id']);hidden('request_key',bin2hex(random_bytes(16)));field('note','دلیل برگشت','','text','',true);formend('برگشت کامل مصرف','/visit?id='.$session);}}
 echo '</section>';
}
