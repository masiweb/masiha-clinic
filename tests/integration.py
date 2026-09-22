"""Disposable records only; run as root on staging/test deployment."""
import requests,urllib3,json,pathlib,re,subprocess,uuid,sys,os
base=sys.argv[1] if len(sys.argv)>1 else 'https://127.0.0.1:8091'
if base.startswith('https://127.0.0.1:'):verify=False;urllib3.disable_warnings()
else:verify=True
c=json.loads(pathlib.Path(os.environ.get('MASIHA_TEST_CREDENTIALS','/etc/masiha-clinic/install.json')).read_text());s=requests.Session();s.verify=verify
marker='QA_'+uuid.uuid4().hex[:10];pid=None;eid=None;uid=None;slot=None;test_session=None
sql=lambda x:subprocess.check_output(['mariadb','-N','masiha_clinic','-e',x],text=True).strip()
def get(path):
 r=s.get(base+path,timeout=20);r.raise_for_status();assert '</html>' in r.text,(path,r.text[-300:]);return r

def post(path,data):
 h=get(path).text;data['csrf']=re.search(r'name="csrf" value="([^"]+)"',h)[1]
 if data['action']=='payment':data['payment_nonce']=re.search(r'name="payment_nonce" value="([^"]+)"',h)[1]
 r=s.post(base+path,data=data,timeout=20);r.raise_for_status();return r
try:
 r=post('/login',dict(action='login',username=c['admin_user'],password=c['admin_password']));assert 'workspace' in r.text;print('PASS staff login')
 for path in ['/','/patients','/patients/new','/episodes','/episodes/new','/appointments','/appointment/new','/finance','/reports','/settings','/staff','/resources']:
  h=get(path).text;assert 'OpenEMR' not in h;assert 'lang="fa" dir="rtl"' in h;assert 'نمایش صفحه با خطا' not in h,path
 print('PASS all 12 independent admin screens render in Persian RTL')
 h=get('/appointments?date=2026-03-21').text;assert 'فروردین' in h and '۱۴۰۵' in h;print('PASS Jalali New Year calendar')
 r=post('/patients/new',dict(action='patient',pid=0,fname=marker,lname='آزمون',phone_cell='',national_id='',DOB='1990-03-21',sex='female',email='',address='',emergency_name='',emergency_phone='',notes='',allow_portal='on'))
 assert '/patient?id=' in r.url,r.text[-500:];pid=int(re.search(r'id=(\d+)',r.url)[1]);assert sql(f'SELECT DOB FROM patients WHERE pid={pid}')=='1990-03-21';print('PASS patient creation with optional email and Gregorian DOB')
 r=post('/episodes/new',dict(action='episode',pid=pid,diagnosis=marker,body_region='زانو',referral='',assessment='آزمون',goals='',precautions='',exercises='',planned_sessions=3,fee_toman=100000))
 assert '/episode?id=' in r.url;e=int(re.search(r'id=(\d+)',r.url)[1]);eid=e
 tid=int(sql("SELECT id FROM staff WHERE username='"+c['admin_user']+"'"))
 sql(f"INSERT INTO resources(name,kind) VALUES('{marker}','room'),('{marker}','equipment')")
 a=dict(action='schedule',episode_id=eid,therapist_id=tid,date='2030-03-21',time='10:00',duration=30,room=marker,equipment=marker,treatment='آزمون')
 post('/appointment/new',a.copy());assert sql(f'SELECT COUNT(*) FROM physio_sessions WHERE episode_id={eid}')=='1'
 r=post('/appointment/new',a.copy());assert 'تداخل' in r.text and sql(f'SELECT COUNT(*) FROM physio_sessions WHERE episode_id={eid}')=='1';print('PASS appointment conflict and Gregorian scheduling')
 sid=int(sql(f'SELECT id FROM physio_sessions WHERE episode_id={eid}'))
 post('/session?id='+str(sid),dict(action='session',session_id=sid,status='done',pain_before=7,pain_after=3,rom='120',notes='آزمون'));assert sql(f'SELECT status FROM physio_sessions WHERE id={sid}')=='done';print('PASS clinical session result')
 post('/finance',dict(action='payment',episode_id=eid,amount_toman=100000,reference=marker));assert sql(f'SELECT SUM(amount_toman) FROM physio_payments WHERE episode_id={eid}')=='100000'
 r=post('/finance',dict(action='payment',episode_id=eid,amount_toman=-100001,reference=marker));assert 'بیشتر از دریافتی' in r.text;print('PASS payment and excess refund rejection')
 r=s.post(base+'/patients/new',data={'action':'patient','csrf':'invalid'},timeout=20);assert r.status_code==403;print('PASS CSRF rejection')
 # Upload is stored outside webroot and checked by ownership.
 h=get('/patient?id='+str(pid)).text;token=re.search(r'name="csrf" value="([^"]+)"',h)[1]
 r=s.post(base+'/patient?id='+str(pid),data={'action':'document','csrf':token,'pid':pid,'title':marker},files={'file':('note.pdf',b'%PDF-1.4\nQA test\n%%EOF','application/pdf')},timeout=20)
 assert sql(f'SELECT COUNT(*) FROM physio_patient_documents WHERE pid={pid}')=='1'
 doc=int(sql(f'SELECT id FROM physio_patient_documents WHERE pid={pid}'));anon=requests.get(base+'/document?id='+str(doc),verify=verify,timeout=20);assert anon.status_code==403;print('PASS document upload and anonymous download rejection')
 # Finance role cannot read clinical records or settings.
 post('/staff',dict(action='staff',id=0,name=marker,username=marker,password=uuid.uuid4().hex,role='finance',active='on'));uid=int(sql(f"SELECT id FROM staff WHERE username='{marker}'"));passwd=uuid.uuid4().hex
 # Set a generated test password via the existing admin form.
 post('/staff?id='+str(uid),dict(action='staff',id=uid,name=marker,username=marker,password=passwd,role='finance',active='on'))
 staffsession=s;s=requests.Session();s.verify=verify;post('/login',dict(action='login',username=marker,password=passwd))
 assert s.get(base+'/patients',timeout=20).status_code==403;assert s.get(base+'/settings',timeout=20).status_code==403;get('/finance');s=staffsession;print('PASS finance role restrictions')
 for path in ['/app/bootstrap.php','/deploy/seed.php','/.git/config','/sites/default/sqlconf.php']:
  r=requests.get(base+path,verify=verify,timeout=20);assert r.status_code in (403,404) or '/login' in r.url
 print('PASS private paths')

 post('/availability',dict(action='slot',therapist_id=tid,date='2030-03-22',time='10:00',duration=30,room=marker,equipment=marker))
 slot=int(sql(f"SELECT id FROM physio_slots WHERE room='{marker}'"))
 # Test-only patient session, created as a fixture outside all production request paths.
 test_session='test'+uuid.uuid4().hex
 php="session_name('masiha_session');session_id('"+test_session+"');session_start();$_SESSION=['pid'=>"+str(pid)+",'csrf'=>bin2hex(random_bytes(32)),'last_seen'=>time()];session_write_close();"
 subprocess.run(['runuser','-u','www-data','--','php','-r',php],check=True)
 staffsession=s;s=requests.Session();s.verify=verify;s.cookies.set('masiha_session',test_session)
 h=get('/patient/booking').text;assert f'value="{slot}"' in h
 post('/patient/booking',dict(action='book',slot_id=slot,episode_id=eid));assert sql(f'SELECT COUNT(*) FROM physio_sessions WHERE episode_id={eid}')=='2'
 r=post('/patient/booking',dict(action='book',slot_id=slot,episode_id=eid));assert 'دیگر آزاد نیست' in r.text;assert sql(f'SELECT COUNT(*) FROM physio_sessions WHERE episode_id={eid}')=='2'
 psid=int(sql(f"SELECT id FROM physio_sessions WHERE episode_id={eid} AND status='scheduled'"))
 post('/patient/booking',dict(action='cancel',session_id=psid));assert sql(f'SELECT status FROM physio_sessions WHERE id={psid}')=='cancelled'
 assert s.get(base+'/settings',timeout=20).url.endswith('/login');s=staffsession
 print('PASS published patient booking, double-book rejection, cancellation and admin isolation')

finally:
 if test_session:
  subprocess.run(['runuser','-u','www-data','--','php','-r',"session_name('masiha_session');session_id('"+test_session+"');session_start();session_destroy();"],check=True)
 if slot:sql(f'DELETE FROM physio_slots WHERE id={slot}')
 sql(f"DELETE FROM resources WHERE name='{marker}'")
 if pid:
  names=sql(f'SELECT storage_name FROM physio_patient_documents WHERE pid={pid}').splitlines()
  for name in names:
   f=pathlib.Path('/var/lib/masiha-clinic/documents')/name
   if f.is_file():f.unlink()
  sql(f'DELETE FROM physio_patient_documents WHERE pid={pid}')
 if eid:sql(f'DELETE FROM physio_payments WHERE episode_id={eid};DELETE FROM physio_sessions WHERE episode_id={eid};DELETE FROM physio_episodes WHERE id={eid}')
 if pid:sql(f"DELETE FROM patients WHERE pid={pid} AND fname='{marker}'")
 if uid:sql(f"DELETE FROM staff WHERE id={uid} AND username='{marker}'")
 print('Synthetic records removed')
