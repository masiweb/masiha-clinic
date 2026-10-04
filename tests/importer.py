"""Isolated SQL/crypto/quota/import tests. Does not start browsers or contact Boghrat."""
import os,sys,pathlib,subprocess,json,tempfile,secrets,shutil
root=pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0,str(root/'importer'))
from parser import record_from_dom,allowed_link,extract_history_summary
suffix=secrets.token_hex(4);db='masiha_import_test_'+suffix
sql=lambda s:subprocess.check_output(['mariadb','-N','-e',s],text=True).strip()
work=pathlib.Path(tempfile.mkdtemp(prefix='masiha-import-test-'));cfg=work/'config.php';env=os.environ.copy();env['MASIHA_CONFIG']=str(cfg)
config={'dsn':'mysql:host=localhost;dbname='+db+';charset=utf8mb4','user':'root','password':'','secret':secrets.token_hex(32),'storage':str(work)}
phpconfig=subprocess.check_output(['php','-r','$c=json_decode(stream_get_contents(STDIN),true);echo "<?php return ".var_export($c,true).";";'],input=json.dumps(config),text=True)
cfg.write_text(phpconfig);cfg.chmod(0o600)
def bridge(op,**args):
 p=subprocess.run(['php',str(root/'importer/bridge.php')],input=json.dumps({'op':op,**args}),text=True,env=env,capture_output=True)
 assert p.returncode==0,p.stderr
 return json.loads(p.stdout)
def php(code):return subprocess.check_output(['php','-r',"require '"+str(root/'app/bootstrap.php')+"';require '"+str(root/'app/import.php')+"';"+code],env=env,text=True)
try:
 sql('CREATE DATABASE '+db+' CHARACTER SET utf8mb4')
 for f in ['schema.sql','therapy.sql','admin-v2.sql','import.sql']:
  subprocess.run(['mariadb',db],input=(root/'deploy'/f).read_text(),text=True,check=True)
 key='b'*64
 sql(f"USE {db};INSERT INTO import_runs(account_key,status,hourly_limit,total_limit,storage_mb,created_by) VALUES('{key}','running',2,3,16,1)")
 rid=int(sql(f'SELECT MAX(id) FROM {db}.import_runs'))
 assert bridge('reserve',run_id=rid)['ok']
 assert bridge('reserve',run_id=rid)['wait']>0
 sql(f"UPDATE {db}.import_quota SET next_at=DATE_SUB(NOW(),INTERVAL 1 SECOND)")
 assert bridge('reserve',run_id=rid)['ok']
 sql(f"UPDATE {db}.import_quota SET next_at=DATE_SUB(NOW(),INTERVAL 1 SECOND)")
 assert bridge('reserve',run_id=rid)['wait']>0
 sql(f"UPDATE {db}.import_runs SET status='paused' WHERE id={rid}")
 assert bridge('reserve',run_id=rid)['stop']
 sql(f"UPDATE {db}.import_runs SET status='running' WHERE id={rid}")
 assert bridge('reserve',run_id=rid)['wait']>0
 print('PASS hourly pacing, hourly ceiling and pause/resume quota retention')
 sql(f"USE {db};INSERT INTO import_runs(account_key,status,hourly_limit,delay_seconds,total_limit,storage_mb,created_by) VALUES('{'c'*64}','running',0,3,4,16,1)")
 unlimited=int(sql(f'SELECT MAX(id) FROM {db}.import_runs'))
 assert bridge('reserve',run_id=unlimited)['ok']
 paced=bridge('reserve',run_id=unlimited)
 assert paced['wait']>0 and paced['wait']<=3
 sql(f"UPDATE {db}.import_quota SET next_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE account_key='{'c'*64}'")
 assert bridge('reserve',run_id=unlimited)['ok']
 print('PASS unlimited hourly mode keeps explicit per-record pacing')
 row=['۱','آزمایشی بیمار','ثبت ۱۴۰۵/۰۶/۳۱','۱۲۳۴۵۶۷۸۹۰','۰۹۱۲۳۴۵۶۷۸۹','info']
 r=record_from_dom(row,'اطلاعات شخصی\nآزمایشی بیمار','سوابق ویزیت و پرداخت\nآزمایشی',[],1,0,'https://app.boghrat.com/clinic/secretary/reception/')
 assert r['national_id']=='1234567890' and r['mobile']=='09123456789'
 for link in ['https://evil.test/a','javascript:alert(1)','http://127.0.0.1/','https://app.boghrat.com@evil.test/a']:assert allowed_link(link,r['source_url']) is None
 assert bridge('save',run_id=rid,record=r,next_page=1,next_row=1)['ok']
 assert bridge('save',run_id=rid,record=r,next_page=1,next_row=1)['duplicate']
 assert sql(f'SELECT COUNT(*) FROM {db}.import_records')=='1'
 r['history']+='\nیادداشت دوم'
 assert bridge('save',run_id=rid,record=r,next_page=1,next_row=1)['duplicate']
 assert sql(f'SELECT COUNT(*) FROM {db}.import_versions')=='1'
 assert sql(f'SELECT COUNT(*) FROM {db}.patients')=='0'
 assert sql(f'SELECT COUNT(*) FROM {db}.physio_payments')=='0'
 assert bridge('reserve',run_id=rid)['stop']
 assert sql(f'SELECT status FROM {db}.import_runs WHERE id={rid}')=='limit'
 print('PASS Persian normalization, safe links, deduplication, history preservation and total cap')
 print('PASS raw archive does not silently create patient or financial records')
 auto=json.loads(php("$db->exec('ALTER TABLE patients AUTO_INCREMENT=7000');setsetting('import_auto_register','1');echo json_encode(importRegisterPending(1));"))
 assert auto['created']==1 and auto['conflict']==0
 assert sql(f'SELECT pid FROM {db}.patients')=='7000'
 assert sql(f'SELECT pid FROM {db}.import_records')=='7000'
 sql(f"USE {db};INSERT INTO patients(fname,lname,address,notes,created_by) VALUES('دستی','آزمایشی','','',1)")
 assert sql(f"SELECT MAX(pid) FROM {db}.patients")=='7001'
 sql(f"USE {db};INSERT INTO import_runs(account_key,status,hourly_limit,total_limit,storage_mb,created_by) VALUES('{key}','running',300,5,16,1)")
 auto_run=int(sql(f'SELECT MAX(id) FROM {db}.import_runs'))
 row2=['۲','سروش میثمی فرد','ثبت ۱۴۰۵/۰۶/۳۱','۴۷۱۱۳۳۳۵۰۹','۰۹۱۲۳۲۶۱۰۲۹','info']
 personal2='''اطلاعات مراجعه کننده سروش میثمی فرد
person اطلاعات شخصی
سروش میثمی فرد
موبایل: 09123261029 تلفن منزل: 09128001093
معرف: مادر
ثبت در بقراط: 1405/6/28 ثبت در مطب: 1405/6/28
کد ملی: 4711333509 نام پدر: امیر محمد
وضعیت تاهل: مجرد
تاریخ تولد: 1383/7/3 سن: 21 سال و 11 ماه و 28 روز
آدرس: فرمانیه لواسان شرقی نوریان پ 45
بیماری های خاص: Ankle sprain
-برچسب حذف شده-
assignment فرم های اختصاصی مراجعه کننده
فرم پذیرش'''
 r2=record_from_dom(row2,personal2,'سوابق ویزیت و پرداخت\nآزمایشی',[],1,1,'https://app.boghrat.com/clinic/secretary/reception/')
 assert r2['profile']['phone_home']=='09128001093'
 assert r2['profile']['referral_source']=='مادر'
 assert r2['profile']['father_name']=='امیر محمد'
 assert r2['profile']['marital_status']=='مجرد'
 assert r2['profile']['birth_jalali']=='1383/7/3'
 assert r2['profile']['address']=='فرمانیه لواسان شرقی نوریان پ 45'
 assert r2['profile']['medical_conditions']=='Ankle sprain'
 history2='''سه شنبه - 1405/6/31
ساعت 10:00
کد نوبت: 47748304
assignment_ind نیما البرزی
local_hospital بابت: نرمال
location_on به صورت: حضوری
info وضعیت: غایب در مطب
یکشنبه - 1405/6/29
ساعت 10:00
کد نوبت: 47696990
assignment_ind نیما البرزی
local_hospital بابت: ویزیت درمان
location_on به صورت: حضوری
info وضعیت: ویزیت شده
announcement توضیحات: با هماهنگی دکتر ثبت شد
healing خدمات ارائه شده: فیزیو یک (بدون ورزش)
shopping_basket کالاهای ثبت شده: 1 ⨯ ملحفه، 1 ⨯ ساک دستی جدید
نمایش بدهی لحظه ای
مانده بدهی: 1,250,000 تومان'''
 hs=extract_history_summary(history2)
 assert len(hs['events'])==2
 assert hs['events'][0]['appointment_code']=='47748304'
 assert hs['events'][1]['appointment_code']=='47696990'
 assert hs['events'][1]['services']=='فیزیو یک (بدون ورزش)'
 assert 'ملحفه' in hs['events'][1]['goods']
 assert any('مانده بدهی' in x for x in hs['financial_lines'])
 finance_history='''درآمد خدمات: 7,000,000
درآمد کالاها: 50,000
پرداختی ها: 7,050,000
بازگشت وجه: 0
تخفیف ها: 0
مابه التفاوت: 0
شنبه - 1405/7/1
ساعت 10:00
کد نوبت: 999001
healing خدمات ارائه شده: فیزیوتراپی2 (5,000,000)، Extra (2,000,000)
monetization_on هزینه خدمات: 7,050,000
shopping_basket کالاهای ثبت شده: 1 ⨯ ملحفه یکبارمصرف
بستانکاری: 7,050,000
attach_money پرداختی های مراجعه کننده: (مجموع پرداختی: 7,050,000 ، مجموع تسویه حساب: 7,050,000)
7,000,000
تومان کارتخوان
50,000
تومان انتقال به حساب
بدهی: تسویه حساب'''
 fh=extract_history_summary(finance_history.replace('\n','\\n'))
 assert len(fh['events'])==1
 assert fh['events'][0]['service_items'][0]=={'name':'فیزیوتراپی2','amount_toman':5000000}
 assert fh['events'][0]['service_items'][1]=={'name':'Extra','amount_toman':2000000}
 assert fh['events'][0]['charge_total_toman']==7050000
 assert fh['events'][0]['service_items_total_toman']==7000000
 assert fh['events'][0]['goods_cost_toman']==50000
 assert fh['events'][0]['goods_items'][0]['amount_toman']==50000
 assert len([x for x in fh['transactions'] if x['type']=='payment'])==2
 assert sum(x['amount_toman'] for x in fh['transactions'] if x['type']=='payment')==7050000
 assert any(x['type']=='service_charge' and x['amount_toman']==7050000 for x in fh['transactions'])
 assert any(x['type']=='debt_snapshot' and x['amount_toman']==0 for x in fh['transactions'])
 r2['history']=history2
 r2['history_summary']=hs
 r2['forms']={'assignment فرم پذیرش':'فرم پذیرش آزمایشی'}
 saved=bridge('save',run_id=auto_run,record=r2,next_page=1,next_row=2)
 assert saved['ok'] and saved['registration']['status']=='created'
 assert sql(f'SELECT MAX(pid) FROM {db}.patients')=='7002'
 assert sql(f'SELECT COUNT(*) FROM {db}.import_patient_events WHERE record_id=(SELECT id FROM {db}.import_records WHERE national_id="4711333509")')=='2'
 assert int(sql(f'SELECT COUNT(*) FROM {db}.import_financial_lines WHERE record_id=(SELECT id FROM {db}.import_records WHERE national_id="4711333509")'))>=1
 # Rebuild one synthetic record with structured source-finance transactions.
 r2['history']=finance_history
 r2['history_summary']=fh
 saved=bridge('save',run_id=auto_run,record=r2,next_page=1,next_row=2)
 assert saved['ok']
 rid2=sql(f'SELECT id FROM {db}.import_records WHERE national_id="4711333509"')
 assert sql(f'SELECT COUNT(*) FROM {db}.import_event_services WHERE record_id={rid2}')=='2'
 assert sql(f'SELECT SUM(COALESCE(amount_toman,0)) FROM {db}.import_event_services WHERE record_id={rid2}')=='7000000'
 assert sql(f'SELECT COUNT(*) FROM {db}.import_financial_transactions WHERE record_id={rid2} AND tx_type="payment"')=='2'
 assert sql(f'SELECT SUM(amount_toman) FROM {db}.import_financial_transactions WHERE record_id={rid2} AND tx_type="payment"')=='7050000'
 assert sql(f'SELECT COUNT(*) FROM {db}.import_source_practitioners')=='1'
 assert sql(f"SELECT COUNT(*) FROM {db}.staff WHERE role='therapist' AND active=0")=='1'
 assert sql(f'SELECT COUNT(*) FROM {db}.import_event_services WHERE record_id={rid2} AND service_id IS NOT NULL')=='2'
 assert sql(f'SELECT COUNT(*) FROM {db}.import_event_goods WHERE record_id={rid2} AND inventory_item_id IS NOT NULL')=='1'
 fields=sql(f"SELECT CONCAT_WS('|',phone_cell,phone_home,national_id,father_name,marital_status,referral_source,address,medical_conditions,DATE_FORMAT(DOB,'%Y-%m-%d'),DATE_FORMAT(source_registered_date,'%Y-%m-%d'),DATE_FORMAT(clinic_registered_date,'%Y-%m-%d')) FROM {db}.patients WHERE pid=7002")
 assert fields=='09123261029|09128001093|4711333509|امیر محمد|مجرد|مادر|فرمانیه لواسان شرقی نوریان پ 45|Ankle sprain|2004-09-24|2026-09-19|2026-09-19',fields
 print('PASS configurable case-number sequence, structured Boghrat profile mapping, visit/financial summary and automatic registration')
 # Shared family mobile numbers must not merge different patient names.
 sql(f"USE {db};INSERT INTO import_runs(account_key,status,hourly_limit,total_limit,storage_mb,created_by) VALUES('{'d'*64}','running',300,5,16,1)")
 shared_run=int(sql(f'SELECT MAX(id) FROM {db}.import_runs'))
 shared_a=record_from_dom(['1','نام اول','x','','09121111111','info'],'اطلاعات شخصی\nنام اول\nموبایل: 09121111111','سوابق ویزیت و پرداخت\nآزمایشی',[],1,0,'https://app.boghrat.com/clinic/secretary/reception/')
 shared_b=record_from_dom(['2','نام دوم','x','','09121111111','info'],'اطلاعات شخصی\nنام دوم\nموبایل: 09121111111','سوابق ویزیت و پرداخت\nآزمایشی',[],1,1,'https://app.boghrat.com/clinic/secretary/reception/')
 assert bridge('save',run_id=shared_run,record=shared_a,next_page=1,next_row=1)['ok']
 assert bridge('save',run_id=shared_run,record=shared_b,next_page=1,next_row=2)['ok']
 pids=sql(f"SELECT GROUP_CONCAT(pid ORDER BY pid) FROM {db}.import_records WHERE mobile='09121111111'")
 assert len(set(pids.split(',')))==2,pids
 print('PASS shared mobile does not merge different patient names')
 assert php("$x=importEncrypt('synthetic-test-secret');if(str_contains($x,'synthetic-test-secret')||importDecrypt($x)!=='synthetic-test-secret')exit(2);echo 'ok';")=='ok'
 denied=php("$_SESSION=['uid'=>0];importNeed();echo 'BAD';")
 assert 'BAD' not in denied
 print('PASS encrypted credential roundtrip and administrative access guard')
 sql(f"USE {db};INSERT INTO staff(username,name,password_hash,role) VALUES('qa_admin','مدیر آزمایشی','invalid','admin')")
 admin_id=sql(f"SELECT id FROM {db}.staff WHERE username='qa_admin'")
 html=php("require '"+str(root/'app/ui.php')+"';require '"+str(root/'app/admin-views.php')+"';$_SESSION=['uid'=>"+admin_id+",'csrf'=>str_repeat('a',64)];importsView();")
 assert 'تعداد استخراج در هر ساعت' in html and 'فاصله بین واکشی‌ها' in html and 'تعداد کل استخراج' in html and 'لینک ورود به بقراط' in html
 assert 'شماره پرونده بعدی' in html and 'ثبت خودکار رکوردهای واکشی‌شده' in html
 assert 'synthetic-test-secret' not in html
 print('PASS Persian import settings render without revealing stored credentials')
 # Therapist schedules are enforced only after a schedule is defined.
 import datetime
 sql(f"USE {db};INSERT INTO staff(username,name,password_hash,role,active) VALUES('schedule_doc','درمانگر برنامه','invalid','therapist',1)")
 schedule_tid=sql(f"SELECT id FROM {db}.staff WHERE username='schedule_doc'")
 schedule_date='2099-01-03'
 weekday=(datetime.date.fromisoformat(schedule_date).weekday()+2)%7
 sql(f"USE {db};INSERT INTO therapist_schedules(therapist_id,weekday,start_time,end_time,effective_from,effective_to,active,created_by) VALUES({schedule_tid},{weekday},'09:00:00','12:00:00','2099-01-01',NULL,1,1)")
 inside=php(f"$x=therapistAvailabilityIssue('{schedule_date} 09:30:00','{schedule_date} 10:00:00',{schedule_tid});echo $x===null?'OK':$x;")
 outside=php(f"$x=therapistAvailabilityIssue('{schedule_date} 13:00:00','{schedule_date} 13:30:00',{schedule_tid});echo $x===null?'BAD':$x;")
 assert inside=='OK',inside
 assert 'خارج از برنامه حضور' in outside,outside
 sql(f"USE {db};INSERT INTO therapist_absences(therapist_id,starts_at,ends_at,reason,active,created_by) VALUES({schedule_tid},'{schedule_date} 09:40:00','{schedule_date} 10:10:00','test',1,1)")
 absent=php(f"$x=therapistAvailabilityIssue('{schedule_date} 09:45:00','{schedule_date} 10:00:00',{schedule_tid});echo $x===null?'BAD':$x;")
 assert 'غیبت ثبت‌شده' in absent,absent
 print('PASS therapist recurring schedule and absence enforcement')
 # Capability audit route policy is deliberately narrow: same-origin, safe query keys, no destructive paths.
 import importlib.util
 spec=importlib.util.spec_from_file_location('boghrat_audit',root/'importer'/'audit.py')
 auditmod=importlib.util.module_from_spec(spec);spec.loader.exec_module(auditmod)
 assert auditmod.safe_route('https://app.boghrat.com/dashboard/panel')
 assert auditmod.safe_route('https://app.boghrat.com/clinic/secretary/reception/?_ilc=123')
 assert auditmod.safe_route('https://app.boghrat.com/logout') is None
 assert auditmod.safe_route('https://app.boghrat.com/patients?id=123') is None
 assert auditmod.safe_route('https://evil.example/dashboard') is None
 print('PASS capability audit same-origin and destructive-route guards')
 # Make a separate run with a full quota to exercise storage ceiling without real files.
 sql(f"USE {db};INSERT INTO import_runs(account_key,status,hourly_limit,total_limit,storage_mb,created_by) VALUES('{key}','running',2,10,0,1)")
 second=int(sql(f'SELECT MAX(id) FROM {db}.import_runs'))
 assert bridge('save',run_id=second,record=r,next_page=2,next_row=0)['stop']
 assert sql(f'SELECT status FROM {db}.import_runs WHERE id={second}')=='space'
 print('PASS storage ceiling preserves existing records and cursor')
finally:
 sql('DROP DATABASE IF EXISTS '+db);shutil.rmtree(work)
 print('Removed isolated synthetic database and test configuration')
