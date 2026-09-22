<?php
if(PHP_SAPI!=='cli')exit(1);require __DIR__.'/../app/bootstrap.php';$c=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!(int)q('SELECT COUNT(*) FROM staff')->fetchColumn())q('INSERT INTO staff(username,name,password_hash,role) VALUES(?,?,?,?)',[$c['admin_user'],'مدیر کلینیک',password_hash($c['admin_password'],PASSWORD_DEFAULT),'admin']);
foreach(['name'=>'مدیریت کلینیک مسیحا','help'=>'1','otp'=>'0','phone'=>'','address'=>''] as $k=>$v)q('INSERT IGNORE INTO settings(`key`,value) VALUES(?,?)',[$k,$v]);
// Leave services and facilities to the clinic; no invented clinical or financial data.
echo "Initial admin and clinic settings ready\n";
