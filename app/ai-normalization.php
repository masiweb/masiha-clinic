<?php
declare(strict_types=1);

/** Normalize Persian and Arabic numerals without ever casting identifier strings to int. */
function aiDigits(string $value):string{
 return strtr($value,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
}
/** Small, deliberately conservative parser for spoken Persian numbers in dates. */
function aiSpokenNumber(string $raw):?int{
 $raw=trim(preg_replace('/\s+/u',' ',str_replace(["‌","ـ"],' ' ,aiDigits($raw))));
 if(preg_match('/^\d{1,4}$/D',$raw))return (int)$raw;
 $values=['صفر'=>0,'یک'=>1,'یه'=>1,'اول'=>1,'یکم'=>1,'دو'=>2,'دوم'=>2,'سه'=>3,'سوم'=>3,'چهار'=>4,'چهارم'=>4,'پنج'=>5,'پنجم'=>5,'شش'=>6,'ششم'=>6,'هفت'=>7,'هفتم'=>7,'هشت'=>8,'هشتم'=>8,'نه'=>9,'نهم'=>9,'ده'=>10,'دهم'=>10,'یازده'=>11,'یازدهم'=>11,'دوازده'=>12,'دوازدهم'=>12,'سیزده'=>13,'سیزدهم'=>13,'چهارده'=>14,'چهاردهم'=>14,'پانزده'=>15,'پانزدهم'=>15,'شانزده'=>16,'شانزدهم'=>16,'هفده'=>17,'هفدهم'=>17,'هجده'=>18,'هجدهم'=>18,'نوزده'=>19,'نوزدهم'=>19,'بیست'=>20,'سی'=>30,'چهل'=>40,'پنجاه'=>50,'شصت'=>60,'هفتاد'=>70,'هشتاد'=>80,'نود'=>90,'صد'=>100,'دویست'=>200,'سیصد'=>300,'چهارصد'=>400,'پانصد'=>500,'ششصد'=>600,'هفتصد'=>700,'هشتصد'=>800,'نهصد'=>900,'هزار'=>1000];
 $words=preg_split('/\s+/u',$raw);
 if(!$words||count($words)>12)return null;
 $sum=0;$previous=10000;
 foreach($words as $word){
  if($word==='و')continue;
  if(!array_key_exists($word,$values))return null;
  $n=$values[$word];
  if($n>=$previous)return null; // Reject malformed phrases rather than invent a value.
  $previous=$n;$sum+=$n;
 }
 return $sum;
}
function aiJalaliDateFromSpeech(string $transcript,string $field):?array{
 $s=preg_replace('/\s+/u',' ',str_replace('‌',' ',aiDigits($transcript)));
 $prefix=$field==='DOB'?'(?:تاریخ\s*تولد|متولد|تولد)':'(?:تاریخ\s*نوبت|تاریخ\s*مراجعه|روز\s*نوبت)';
 if(!preg_match('/'.$prefix.'\s*[:：،=-]?\s*(.{1,120})/u',$s,$m))return null;
 $tail=trim(preg_split('/(?:\s+(?=نام\s*پدر|جنسیت|وضعیت|شغل|تحصیلات|نشانی|آدرس|معرف|شماره|تلفن|کد\s*ملی|یادداشت|بیماری|ساعت|مدت|اتاق|پزشک|درمانگر|تاریخ\s*ثبت)|[،؛.])/u',$m[1],2)[0]);
 $tail=trim(preg_replace('/^[\\s،؛:=-]+|[\\s،؛:=-]+$/u','',$tail));
 if($tail==='')return null;
 $candidates=[];
 $add=function(int $y,int $mo,int $day)use(&$candidates,$field):void{
  if($y>=0&&$y<=99)$y+=1300; // A spoken 68 in a Persian birth date is 1368, not 1968.
  if($y<1250||$y>1500||$mo<1||$mo>12||$day<1||$day>31)return;
  $j=sprintf('%04d-%02d-%02d',$y,$mo,$day);
  $g=MasihaJalali::fromPersianDate($j);
  if($g===null||$g>date('Y-m-d')&&$field==='DOB')return;
  $candidates[$j]=['jalali'=>$j,'gregorian'=>$g];
 };
 if(preg_match('/^(\d{4})[\/.-](\d{1,2})[\/.-](\d{1,2})/u',$tail,$p))$add((int)$p[1],(int)$p[2],(int)$p[3]);
 if(preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})/u',$tail,$p))$add((int)$p[3],(int)$p[2],(int)$p[1]);
 $months=['فروردین'=>1,'اردیبهشت'=>2,'خرداد'=>3,'تیر'=>4,'مرداد'=>5,'شهریور'=>6,'مهر'=>7,'آبان'=>8,'آذر'=>9,'دی'=>10,'بهمن'=>11,'اسفند'=>12];
 foreach($months as $name=>$mo){
  if(preg_match('/^(.{1,35}?)\s*'.preg_quote($name,'/').'\s+(.{1,50})$/u',$tail,$p)){
   $day=aiSpokenNumber($p[1]);$year=aiSpokenNumber($p[2]);
   if($day!==null&&$year!==null)$add($year,$mo,$day);
  }
 }
 // E.g. «چهارده ده شصت و هشت» => 14 / 10 / 1368.
 $tokens=preg_split('/\s+/u',preg_replace('/[\/،:]/u',' ',$tail));
 if($tokens&&count($tokens)>=3&&count($tokens)<=13){
  $total=count($tokens);
  for($i=1;$i<=min(4,$total-2);$i++){
   for($j=$i+1;$j<=min($i+4,$total-1);$j++){
    $day=aiSpokenNumber(implode(' ',array_slice($tokens,0,$i)));
    $month=aiSpokenNumber(implode(' ',array_slice($tokens,$i,$j-$i)));
    $year=aiSpokenNumber(implode(' ',array_slice($tokens,$j)));
    if($day!==null&&$month!==null&&$year!==null)$add($year,$month,$day);
   }
  }
 }
 return count($candidates)===1?reset($candidates):null;
}
/** Split speech into identifier digits, without arithmetic on zero repetitions. */
function aiSpokenDigitRun(string $raw):?string{
 $raw=trim(preg_replace('/\s+/u',' ',str_replace('‌',' ',aiDigits($raw))));
 $raw=trim(preg_replace('/^[\\s،.؛:=-]+|[\\s،.؛:=-]+$/u','',$raw));
 if(preg_match('/^[0-9\s\-]+$/D',$raw))return preg_replace('/[^0-9]/','',$raw);
 $tokens=preg_split('/\s+/u',$raw);if(!$tokens||count($tokens)>40)return null;
 $units=['صفر'=>'0','یک'=>'1','یه'=>'1','دو'=>'2','سه'=>'3','چهار'=>'4','پنج'=>'5','شش'=>'6','هفت'=>'7','هشت'=>'8','نه'=>'9'];
 $tens=['ده'=>10,'بیست'=>20,'سی'=>30,'چهل'=>40,'پنجاه'=>50,'شصت'=>60,'هفتاد'=>70,'هشتاد'=>80,'نود'=>90];
 $teens=['یازده'=>11,'دوازده'=>12,'سیزده'=>13,'چهارده'=>14,'پانزده'=>15,'شانزده'=>16,'هفده'=>17,'هجده'=>18,'نوزده'=>19];
 $out='';$n=count($tokens);
 for($i=0;$i<$n;$i++){
  $w=$tokens[$i];if($w==='و'||$w==='عدد')continue;
  if(isset($units[$w])&&$w!=='صفر'){
   $j=$i+1;if(($tokens[$j]??'')==='تا'||($tokens[$j]??'')==='عدد')$j++;
   if(($tokens[$j]??'')==='صفر'){$out.=str_repeat('0',(int)$units[$w]);$i=$j;continue;}
  }
  if(isset($tens[$w])){
   $j=$i+1;if(($tokens[$j]??'')==='و')$j++;
   if($j>$i+1&&isset($units[$tokens[$j]??''])&&$tokens[$j]!=='صفر'){$out.=(string)($tens[$w]+(int)$units[$tokens[$j]]);$i=$j;continue;}
   $out.=(string)$tens[$w];continue;
  }
  if(isset($units[$w])){$out.=$units[$w];continue;}
  if(isset($teens[$w])){$out.=(string)$teens[$w];continue;}
  if(preg_match('/^\d+$/D',$w)){$out.=$w;continue;}
  return null;
 }
 return $out===''?null:$out;
}
function aiSpokenIdentifier(string $transcript,string $key):?string{
 $regex=match($key){
  'national_id'=>'(?:کد\s*ملی)',
  'phone_cell'=>'(?:شماره\s*(?:همراه|موبایل)|تلفن\s*همراه|موبایل)',
  'phone_home'=>'(?:تلفن\s*(?:منزل|ثابت|خانه)|شماره\s*منزل)',
  default=>null
 };
 if($regex===null)return null;
 $text=str_replace('‌',' ',aiDigits($transcript));
 if(!preg_match('/'.$regex.'\s*[:：=-]?\s*(.{1,160})/u',$text,$m))return null;
 $raw=trim(preg_split('/(?=\s*(?:کد\s*ملی|شماره\s*همراه|تلفن\s*منزل|نام\s*پدر|تاریخ\s*تولد|جنسیت|وضعیت|تحصیلات|نشانی|آدرس|معرف|یادداشت|شغل))|[،؛.]/u',trim($m[1]),2)[0]);
 return aiSpokenDigitRun($raw);
}
function aiSafeFieldValue(string $key,string $value,string $transcript):?string{
 if(in_array($key,['DOB','date'],true)){
  $spoken=aiJalaliDateFromSpeech($transcript,$key);
  if($spoken!==null)return $spoken['gregorian'];
  // Never trust a guessed Gregorian conversion of a Persian spoken date.
  return null;
 }
 if(in_array($key,['national_id','phone_cell','phone_home'],true)){
  $expected=$key==='national_id'?10:($key==='phone_cell'?11:null);
  $spoken=aiSpokenIdentifier($transcript,$key);
  if($spoken!==null&&($expected===null?(strlen($spoken)>=7&&strlen($spoken)<=15):strlen($spoken)===$expected)&&($key!=='phone_cell'||str_starts_with($spoken,'09')))return $spoken;
  $v=aiDigits($value);
  $v=aiSpokenDigitRun($v);
  if($v===null||($expected!==null&&strlen($v)!==$expected)||($key==='phone_cell'&&!str_starts_with($v,'09')))return null;
  // If the transcript explicitly says multiple zeros, do not silently accept a value missing them.
  if(preg_match('/(?:دو|سه|چهار|پنج)\s*(?:تا|عدد)?\s+صفر/u',$transcript) && !str_contains($v,'00'))return null;
  return $v;
 }
 return $value;
}
