<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Presentation only: every query, URL and submitted date remains Gregorian.
final class MasihaJalali {
 public static function calendar($date): IntlCalendar {
  $c=IntlCalendar::createInstance('Asia/Tehran','en_US@calendar=persian');
  $c->setTime((is_int($date)?$date:strtotime($date))*1000);return $c;
 }
 public static function format($date,$pattern='yyyy/MM/dd'): string {
  $f=new IntlDateFormatter('fa_IR@calendar=persian',0,0,'Asia/Tehran',IntlDateFormatter::TRADITIONAL,$pattern);
  return (string)$f->format(is_int($date)?$date:strtotime($date));
 }
 public static function bounds($date):array {
  $c=self::calendar($date);$c->set(IntlCalendar::FIELD_DAY_OF_MONTH,1);$c->set(IntlCalendar::FIELD_HOUR_OF_DAY,12);$c->set(IntlCalendar::FIELD_MINUTE,0);$c->set(IntlCalendar::FIELD_SECOND,0);
  $start=(int)($c->getTime()/1000);$c->add(IntlCalendar::FIELD_MONTH,1);$end=(int)($c->getTime()/1000)-86400;return [$start,$end];
 }
 public static function fromPersianDate(string $value):?string {
  $v=strtr(trim($value),['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
  if(!preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/D',$v,$m))return null;
  [$y,$mo,$d]=[(int)$m[1],(int)$m[2],(int)$m[3]];
  if($y<1200||$y>1600||$mo<1||$mo>12||$d<1||$d>31)return null;
  $c=IntlCalendar::createInstance('Asia/Tehran','en_US@calendar=persian');$c->clear();$c->set($y,$mo-1,$d,12,0,0);
  if($c->get(IntlCalendar::FIELD_YEAR)!==$y||$c->get(IntlCalendar::FIELD_MONTH)!==$mo-1||$c->get(IntlCalendar::FIELD_DAY_OF_MONTH)!==$d)return null;
  return date('Y-m-d',(int)($c->getTime()/1000));
 }
 public static function adjacent($date,int $offset):string {$c=self::calendar($date);$c->add(IntlCalendar::FIELD_MONTH,$offset);return date('Ymd',(int)($c->getTime()/1000));}
 public static function range($date):array {[$s,$e]=self::bounds($date);while((int)date('w',$s)!==6)$s-=86400;while((int)date('w',$e)!==5)$e+=86400;return [date('m/d/Y',$s),date('m/d/Y',$e)];}
 public static function mini($date,?string $current=null):array {
  [$first,$last]=self::bounds($date);$c=self::calendar($first);$year=$c->get(IntlCalendar::FIELD_YEAR);$month=$c->get(IntlCalendar::FIELD_MONTH)+1;$s=$first;$e=$last;
  while((int)date('w',$s)!==6)$s-=86400;while((int)date('w',$e)!==5)$e+=86400;
  $weeks=[];$week=[];for($t=$s;$t<=$e;$t+=86400){$j=self::calendar($t);$ymd=date('Ymd',$t);$week[]=['dateYmd'=>$ymd,'day'=>$j->get(IntlCalendar::FIELD_DAY_OF_MONTH),'dayOfWeek'=>(int)date('w',$t),'inMonth'=>$t>=$first&&$t<=$last,'isWeekend'=>date('w',$t)==='5','isCurrent'=>$ymd===$current];if(count($week)===7){$weeks[]=$week;$week=[];}}
  return ['monthLabel'=>self::format($first,'MMMM'),'year'=>$year,'month'=>$month,'weeks'=>$weeks];
 }
}
