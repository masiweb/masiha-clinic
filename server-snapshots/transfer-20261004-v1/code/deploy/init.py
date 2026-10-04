#!/usr/bin/env python3
import pathlib,json,secrets,subprocess,os
root=pathlib.Path(__file__).resolve().parent.parent
cfg=pathlib.Path('/etc/masiha-clinic');cfg.mkdir(mode=0o750,exist_ok=True)
p=cfg/'install.json'
if not p.exists():
 exists=subprocess.check_output(['mariadb','-Nse',"SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='masiha_clinic'"]).strip()
 if exists:raise SystemExit('Database exists without install configuration; stop for recovery')
 old=pathlib.Path('/etc/physio-clinic/credentials.json')
 legacy=json.loads(old.read_text()) if old.exists() else {}
 c={'db_password':secrets.token_hex(32),'secret':secrets.token_hex(32),'admin_user':legacy.get('admin_user','clinicadmin'),'admin_password':legacy.get('admin_password',secrets.token_urlsafe(24))}
 p.write_text(json.dumps(c));p.chmod(0o600)
c=json.loads(p.read_text())
subprocess.run(['mariadb'],input="CREATE DATABASE IF NOT EXISTS masiha_clinic CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;CREATE USER IF NOT EXISTS 'masiha_clinic'@'localhost' IDENTIFIED BY '"+c['db_password']+"';GRANT ALL ON masiha_clinic.* TO 'masiha_clinic'@'localhost';",text=True,check=True)
for name in ['schema.sql','therapy.sql','portal.sql','otp.sql','admin-v2.sql','import.sql']:
 subprocess.run(['mariadb','masiha_clinic'],input=(root/'deploy'/name).read_text(),text=True,check=True)
phpcfg={'dsn':'mysql:host=localhost;dbname=masiha_clinic;charset=utf8mb4','user':'masiha_clinic','password':c['db_password'],'secret':c['secret'],'storage':'/var/lib/masiha-clinic'}
# PHP var_export avoids quoting mistakes; secrets only through stdin.
script='$x=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo "<?php\\nreturn ".var_export($x,true).";\\n";'
out=subprocess.check_output(['php','-r',script],input=json.dumps(phpcfg).encode());f=cfg/'config.php';f.write_bytes(out);f.chmod(0o640)
subprocess.run(['chown','root:www-data',str(cfg),str(f)],check=True)
for d in ['/var/lib/masiha-clinic','/var/lib/masiha-clinic/documents','/var/backups/masiha-clinic']:
 pathlib.Path(d).mkdir(mode=0o700,exist_ok=True)
subprocess.run(['chown','-R','www-data:www-data','/var/lib/masiha-clinic'],check=True)
subprocess.run(['php',str(root/'deploy'/'seed.php')],input=json.dumps(c).encode(),check=True)
print('Standalone database and private configuration ready')
