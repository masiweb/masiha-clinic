<?php
declare(strict_types=1);
/** Per-patient report: never double charge individual session tariffs over episode invoices. */
function patientReportAllowed():bool{return allowed('reports');}
function patientReportDate(string $s):string{
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);
 if(!$d||$d->format('Y-m-d')!==$s)throw new DomainException('تاریخ گزارش معتبر نیست.');
 return $s;
}
function patientReportFilters(array $input):array{
 $from=patientReportDate((string)($input['from']??date('Y-m-01')));
 $to=patientReportDate((string)($input['to']??date('Y-m-d')));
 if($from>$to||strtotime($to)-strtotime($from)>366*86400)throw new DomainException('بازه گزارش حداکثر یک سال است.');
 $mode=(string)($input['mode']??'all');
 if(!in_array($mode,['all','services'],true))throw new DomainException('نوع گزارش معتبر نیست.');
 $discount=(string)($input['discount']??'include');
 if(!in_array($discount,['include','exclude'],true))throw new DomainException('تنظیم تخفیف معتبر نیست.');
 $pid=filter_var($input['pid']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
 if($pid===false)throw new DomainException('شناسه پرونده معتبر نیست.');
 $search=trim(MasihaOtp::digits((string)($input['q']??'')));
 if(mb_strlen($search)>90)throw new DomainException('عبارت جستجو طولانی است.');
 $page=filter_var($input['p']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 return compact('from','to','mode','discount','pid','search')+['page'=>$page?:1];
}
function patientReportServiceCondition(string $alias='s'):string{
 return "($alias.status='done')";
}
function patientReportLegacyServiceCondition(string $alias='ie'):string{
 return "(COALESCE($alias.service_cost_toman,0)<>0 OR COALESCE($alias.goods_cost_toman,0)<>0 OR COALESCE($alias.service_items_total_toman,0)<>0)";
}
function patientReportPatients(array $f):array{
 $pred=['p.active=1'];$args=[];
 if($f['search']!==''){
  $pred[]="(CONCAT(p.fname,' ',p.lname) LIKE ? OR CAST(p.pid AS CHAR)=? OR (".patientScope('p','contact')." AND p.phone_cell LIKE ?))";
  array_push($args,'%'.$f['search'].'%',$f['search'],'%'.$f['search'].'%');
 }
 $native="EXISTS(SELECT 1 FROM physio_episodes ep JOIN physio_sessions s ON s.episode_id=ep.id WHERE ep.pid=p.pid AND s.starts_at>=? AND s.starts_at<DATE_ADD(?,INTERVAL 1 DAY)".($f['mode']==='services'?' AND '.patientReportServiceCondition():'').')';
 $legacy="EXISTS(SELECT 1 FROM import_records ir JOIN import_patient_events ie ON ie.record_id=ir.id WHERE ir.pid=p.pid AND ie.event_date>=? AND ie.event_date<=?".($f['mode']==='services'?' AND '.patientReportLegacyServiceCondition():'').')';
 $pred[]="($native OR $legacy)";
 array_push($args,$f['from'],$f['to'],$f['from'],$f['to']);
 $where=implode(' AND ',$pred);
 $count=(int)q("SELECT COUNT(*) FROM patients p WHERE $where",$args)->fetchColumn();
 $pages=max(1,(int)ceil($count/35));$page=min($f['page'],$pages);
 $rows=q("SELECT p.pid,p.fname,p.lname FROM patients p WHERE $where ORDER BY p.lname,p.fname,p.pid LIMIT 35 OFFSET ".(($page-1)*35),$args)->fetchAll();
 return compact('count','pages','page','rows');
}
function patientReportItemText(?string $raw):string{
 $raw=trim((string)$raw);
 if($raw==='')return '—';
 $value=json_decode($raw,true);
 if(!is_array($value))return mb_substr($raw,0,600);
 $out=[];$visit=static function($node,int $depth=0)use(&$visit,&$out):void{
  if(count($out)>=25||$depth>3)return;
  if(is_string($node)){$t=trim($node);if($t!=='')$out[]=mb_substr($t,0,150);return;}
  if(!is_array($node))return;
  $name='';foreach(['name','title','service_name','item_name','description','label'] as $key){if(isset($node[$key])&&is_scalar($node[$key])){$name=trim((string)$node[$key]);break;}}
  if($name!==''){
   $piece=mb_substr($name,0,110);
   foreach(['quantity'=>'تعداد','qty'=>'تعداد','price'=>'مبلغ','amount'=>'مبلغ','total'=>'جمع','price_toman'=>'تومان'] as $field=>$title)if(isset($node[$field])&&is_scalar($node[$field]))$piece.=' · '.$title.': '.mb_substr((string)$node[$field],0,30);
   $out[]=$piece;return;
  }
  foreach($node as $item)$visit($item,$depth+1);
 };
 $visit($value);
 return $out?mb_substr(implode('، ',$out),0,750):mb_substr($raw,0,450);
}
