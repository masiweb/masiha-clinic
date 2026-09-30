<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/import.php';

$c=importConfig();
if(!$c || !$c['username'] || !$c['password_cipher'] || !$c['clinic_name']){
    fwrite(STDERR,"Boghrat connection settings are incomplete.\n");
    exit(2);
}
if(q("SELECT id FROM import_runs WHERE status='running' LIMIT 1")->fetchColumn()){
    fwrite(STDERR,"An import run is already active.\n");
    exit(3);
}
q("UPDATE import_connections SET hourly_limit=0,delay_seconds=8,total_limit=100 WHERE id=1");
$c=importConfig();
$key=importAccount($c);
$admin=(int)(q("SELECT id FROM staff WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn()?:0);
if(!$admin){
    fwrite(STDERR,"No active admin user found.\n");
    exit(4);
}
q("INSERT INTO import_runs(account_key,status,hourly_limit,delay_seconds,total_limit,storage_mb,created_by,message)
   VALUES(?,'running',0,8,100,?,?,'تست کنترل‌شده ۱۰۰ مراجعه؛ واکشی پروفایل، فرم‌ها، سوابق و داده مالی قابل‌مشاهده')",
  [$key,(int)$c['storage_mb'],$admin]);
$id=(int)$db->lastInsertId();
echo "IMPORT_RUN_ID=".$id."\n";
