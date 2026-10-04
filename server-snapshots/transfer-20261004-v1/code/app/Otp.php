<?php
// SPDX-License-Identifier: GPL-3.0-or-later
final class MasihaOtp {
 public function __construct(private PDO $db,private string $secret,private Closure $sender){}
 public static function digits(string $v):string {return strtr($v,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));}
 public static function mobile(string $v):?string {
  $v=strtr($v,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
  $v=preg_replace('/[\s()+-]/u','',$v);if(str_starts_with($v,'0098'))$v='0'.substr($v,4);elseif(str_starts_with($v,'98'))$v='0'.substr($v,2);
  return preg_match('/^09[0-9]{9}$/D',$v)?$v:null;
 }
 private function q($s,$a=[]):PDOStatement{$q=$this->db->prepare($s);$q->execute($a);return $q;}
 private function hash($v):string{return hash_hmac('sha256',$v,$this->secret);}
 private function limit(string $scope,int $max,int $seconds,int $now):void {
  $key=$this->hash($scope);$r=$this->q('SELECT * FROM masiha_otp_limits WHERE bucket=? FOR UPDATE',[$key])->fetch(PDO::FETCH_ASSOC);
  if(!$r||$now-(int)$r['window_start']>=$seconds){$this->q('INSERT INTO masiha_otp_limits VALUES(?,?,1) ON DUPLICATE KEY UPDATE window_start=VALUES(window_start),attempts=1',[$key,$now]);return;}
  if((int)$r['attempts']>=$max)throw new DomainException('درخواست‌های زیادی ثبت شده است. کمی صبر کنید و دوباره تلاش کنید.');
  $this->q('UPDATE masiha_otp_limits SET attempts=attempts+1 WHERE bucket=?',[$key]);
 }
 public function request(string $mobile,string $ip):string {
  $mobile=self::mobile($mobile)??throw new DomainException('شماره همراه را به شکل ۰۹۱۲۳۴۵۶۷۸۹ وارد کنید.');$now=time();
  // Serialize short rate-limit updates to avoid first-insert races on a small server.
  if((int)$this->q("SELECT GET_LOCK('masiha_otp_rate',3)")->fetchColumn()!==1)throw new DomainException('سامانه مشغول است؛ دوباره تلاش کنید.');
  try{$this->db->beginTransaction();$this->limit('ip:'.$ip,20,3600,$now);$this->limit('mobile-hour:'.$mobile,5,3600,$now);$this->limit('mobile-minute:'.$mobile,1,60,$now);$this->db->commit();}
  catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}finally{$this->q("SELECT RELEASE_LOCK('masiha_otp_rate')");}
  // Do not assign one shared family number to multiple patient identities.
  $rows=$this->q("SELECT pid,phone_cell,allow_patient_portal FROM patients WHERE phone_cell=? LIMIT 3",[$mobile])->fetchAll(PDO::FETCH_ASSOC);
  $valid=count($rows)===1&&self::mobile($rows[0]['phone_cell'])===$mobile&&$rows[0]['allow_patient_portal']==='YES';
  $pid=$valid?(int)$rows[0]['pid']:0;$id=bin2hex(random_bytes(32));$code=(string)random_int(100000,999999);$mobileHash=$this->hash('mobile:'.$mobile);
  $this->q('UPDATE masiha_otp_challenges SET consumed=1 WHERE mobile_hash=? AND consumed=0',[$mobileHash]);
  $this->q('INSERT INTO masiha_otp_challenges(id,pid,mobile_hash,code_hash,expires_at,created_at) VALUES(?,?,?,?,?,?)',[$id,$pid,$mobileHash,$this->hash($id.':'.$code),$now+180,$now]);
  $sent=true;if($valid){try{$sent=($this->sender)($mobile,$code);}catch(Throwable){$sent=false;}}
  if(!$sent){$this->q('UPDATE masiha_otp_challenges SET consumed=1 WHERE id=?',[$id]);throw new DomainException('ارسال پیامک انجام نشد. کمی بعد دوباره تلاش کنید یا با پذیرش تماس بگیرید.');}
  $this->q('DELETE FROM masiha_otp_challenges WHERE expires_at<?',[$now-86400]);$this->q('DELETE FROM masiha_otp_limits WHERE window_start<?',[$now-86400]);
  return $id;
 }
 public function verify(string $id,string $code):?array {
  if(!preg_match('/^[a-f0-9]{64}$/D',$id))return null;
  $code=strtr($code,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
  $this->db->beginTransaction();try{
   $r=$this->q('SELECT * FROM masiha_otp_challenges WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
   if(!$r||$r['consumed']||(int)$r['expires_at']<time()||(int)$r['attempts']>=5){$this->db->commit();return null;}
   $this->q('UPDATE masiha_otp_challenges SET attempts=attempts+1 WHERE id=?',[$id]);
   if(!preg_match('/^[0-9]{6}$/D',$code)||!hash_equals($r['code_hash'],$this->hash($id.':'.$code))||!$r['pid']){$this->db->commit();return null;}
   $patient=$this->q("SELECT pid,fname,lname,providerID,phone_cell FROM patients WHERE pid=? AND allow_patient_portal='YES'",[$r['pid']])->fetch(PDO::FETCH_ASSOC);
   if(!$patient||!hash_equals($r['mobile_hash'],$this->hash('mobile:'.self::mobile($patient['phone_cell'])))){$this->db->commit();return null;}
   $this->q('UPDATE masiha_otp_challenges SET consumed=1 WHERE id=?',[$id]);$this->db->commit();return $patient;
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
