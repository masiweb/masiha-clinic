"""Run as root against staging. Uses disposable staff; never changes real passwords."""
import json,pathlib,secrets,subprocess,os,requests,re,urllib3,sys
base=sys.argv[1] if len(sys.argv)>1 else 'https://127.0.0.1:8091'
verify=not base.startswith('https://127.0.0.1:');urllib3.disable_warnings()
root=pathlib.Path(__file__).resolve().parent.parent
marker='V2QA_'+secrets.token_hex(5);pw=secrets.token_urlsafe(24);s=requests.Session();s.verify=verify
sql=lambda x:subprocess.check_output(['mariadb','-N','masiha_clinic','-e',x],text=True).strip()
php="require '"+str(root/ 'app/bootstrap.php')+"';$c=json_decode(stream_get_contents(STDIN),true);q('INSERT INTO staff(username,name,password_hash,role) VALUES(?,?,?,?)',[$c['user'],$c['user'],password_hash($c['pass'],PASSWORD_DEFAULT),'admin']);"
subprocess.run(['php','-r',php],input=json.dumps({'user':marker,'pass':pw}),text=True,check=True)
uid=int(sql(f"SELECT id FROM staff WHERE username='{marker}'"));cred=pathlib.Path('/tmp/'+marker+'.json');cred.write_text(json.dumps({'admin_user':marker,'admin_password':pw}));cred.chmod(0o600)
pids=[];staff=[];eids=[];sid=None;cat=None;pack=None;label=None

def get(path):
 r=s.get(base+path,timeout=20);assert r.status_code==200,(path,r.status_code,r.text[-180:]);return r

def post(path,data):
 h=get(path).text;data['csrf']=re.search(r'name="csrf" value="([^"]+)"',h)[1]
 if data['action']=='payment':data['payment_nonce']=re.search(r'name="payment_nonce" value="([^"]+)"',h)[1]
 r=s.post(base+path,data=data,timeout=20);assert r.status_code==200,(path,r.status_code,r.text[-180:]);return r
try:
 env=os.environ.copy();env['MASIHA_TEST_CREDENTIALS']=str(cred)
 subprocess.run(['python3',str(root/'tests/integration.py'),base],env=env,check=True)
 post('/login',dict(action='login',username=marker,password=pw))
 for path in ['/','/services','/services?tab=categories','/services?tab=packages','/services?tab=shares','/labels','/forms','/appearance','/api/patients?q=zz','/api/booking?therapist=0','/reports?export=csv','/theme.css']:
  get(path)
 print('PASS new pages, live search APIs and CSV report render')
 post('/services?tab=categories',dict(action='category_save',id=0,name=marker,active='on'));cat=int(sql(f"SELECT id FROM service_categories WHERE name='{marker}'"))
 post('/services',dict(action='service_save',id=0,name=marker,price=200000,duration=45,category_id=cat,active='on'));sid=int(sql(f"SELECT id FROM services WHERE name='{marker}'"))
 post('/services?tab=shares',dict(action='share_save',service_id=sid,staff_id=uid,share_type='percent',share_value='25'))
 post('/services?tab=packages',{'action':'package_save','id':0,'name':marker,'active':'on','auto_price':'on',f'qty[{sid}]':3,'price':1});pack=int(sql(f"SELECT id FROM packages WHERE name='{marker}'"));assert sql(f'SELECT price FROM packages WHERE id={pack}')=='600000'
 post('/services',dict(action='service_save',id=sid,name=marker,price=250000,duration=45,category_id=cat,active='on'));assert sql(f'SELECT price FROM packages WHERE id={pack}')=='750000'
 print('PASS category, service, assignment and automatic package repricing')
 post('/labels',dict(action='label_save',id=0,name=marker,color='#123456',active='on'));label=int(sql(f"SELECT id FROM labels WHERE name='{marker}'"))
 for n in range(2):
  r=post('/patients/new',dict(action='patient',pid=0,fname=marker,lname=str(n),phone_cell=('09123456789' if n==0 else ''),national_id='',DOB='',sex='',email='',address='',emergency_name='',emergency_phone='',notes=''))
  pid=int(re.search(r'id=(\d+)',r.url)[1]);pids.append(pid)
 p=pids[0]
 post('/appointment/new',dict(action='book_v2',pid=p,therapist_id=uid,service_id=sid,episode_id=0,clinic_id=1,label_id=label,duration=45,date='2031-04-01',time='10:00',room='',equipment='',notes=marker))
 eids=[int(x) for x in sql(f'SELECT id FROM physio_episodes WHERE pid={p}').splitlines()];eid=eids[0];session=int(sql(f'SELECT id FROM physio_sessions WHERE episode_id={eid}'))
 assert sql(f'SELECT CONCAT(price_snapshot,\',\',share_snapshot,\',\',label_id) FROM physio_sessions WHERE id={session}')==f'250000,62500.00,{label}'
 r=post('/appointment/new',dict(action='book_v2',pid=p,therapist_id=uid,service_id=sid,episode_id=0,clinic_id=1,label_id=label,duration=45,date='2031-04-01',time='10:00',room='',equipment='',notes=marker));assert 'تداخل' in r.text
 print('PASS booking creates episode, snapshots share, label and rejects overlap')
 post('/finance',dict(action='payment',episode_id=eid,amount_toman=200000,reference=marker));pay=int(sql(f'SELECT id FROM physio_payments WHERE episode_id={eid}'))
 post('/finance?edit='+str(pay),dict(action='payment_edit',id=pay,amount_toman=150000,reason=marker));assert sql(f'SELECT amount_toman FROM physio_payments WHERE id={pay}')=='150000'
 post('/finance?edit='+str(pay),dict(action='payment_void',id=pay,reason=marker));assert sql(f'SELECT voided FROM physio_payments WHERE id={pay}')=='1';assert sql(f'SELECT COUNT(*) FROM financial_changes WHERE payment_id={pay}')=='2'
 post('/finance',dict(action='referral_save',episode_id=eid,recipient=marker,kind='percent',value=10))
 print('PASS financial edit, void, history and referral share')
 post('/forms',dict(action='form_save',id=0,pid=p,title=marker,kind='general',body='آزمون فرم'))
 name=marker+'_restricted';post('/staff',dict(action='staff',id=0,name=name,username=name,password=pw,role='therapist',active='on'));rid=int(sql(f"SELECT id FROM staff WHERE username='{name}'"));staff.append(rid)
 post('/staff?id='+str(rid),{'action':'permissions','id':rid,'scope[profile]':'related','scope[contact]':'none','scope[forms_visit]':'own','scope[forms_general]':'own','perm[appointments]':'on','perm[patients_edit]':'on'})
 sql(f'UPDATE patients SET providerID={rid} WHERE pid={p}')
 admin=s;s=requests.Session();s.verify=verify;post('/login',dict(action='login',username=name,password=pw))
 assert s.get(base+'/finance').status_code==403;assert s.get(base+'/reports').status_code==403
 assert s.get(base+'/patient?id='+str(pids[1])).status_code==403
 h=get('/patient?id='+str(p)).text;assert '09123456789' not in h and '۰۹۱۲۳۴۵۶۷۸۹' not in h
 rows=get('/api/patients?q='+marker).json();assert len(rows)==1 and rows[0]['phone'] is None
 assert get('/api/patients?q=09123456789').json()==[]
 assert s.get(base+'/forms?id='+sql(f"SELECT id FROM clinic_forms WHERE title='{marker}'")).status_code==403
 assert s.get(base+'/episode?id='+str(eid)).status_code==403
 assert s.get(base+'/patients/edit?id='+str(p)).status_code==403
 token=re.search(r'name="csrf" value="([^"]+)"',get('/').text)[1]
 for action in ['payment','payment_edit','staff','service_save']:
  r=s.post(base+'/',data={'action':action,'csrf':token,'id':rid});assert r.status_code==403,(action,r.status_code)
 s=admin
 print('PASS related-patient scope, hidden contact, owned forms and forged POST rejection')
 print('PASS admin-v2 integration complete')
finally:
 for eid in eids:
  sql(f'DELETE c FROM financial_changes c JOIN physio_payments x ON x.id=c.payment_id WHERE x.episode_id={eid};DELETE FROM referral_fees WHERE episode_id={eid};DELETE FROM physio_payments WHERE episode_id={eid};DELETE FROM physio_sessions WHERE episode_id={eid};DELETE FROM physio_episodes WHERE id={eid}')
 for pid in pids:sql(f'DELETE FROM clinic_forms WHERE pid={pid};DELETE FROM patients WHERE pid={pid}')
 if pack:sql(f'DELETE FROM package_items WHERE package_id={pack};DELETE FROM packages WHERE id={pack}')
 if sid:sql(f'DELETE FROM service_staff WHERE service_id={sid};DELETE FROM services WHERE id={sid}')
 if cat:sql(f'DELETE FROM service_categories WHERE id={cat}')
 if label:sql(f'DELETE FROM labels WHERE id={label}')
 for i in staff+[uid]:sql(f'DELETE FROM staff_permissions WHERE staff_id={i};DELETE FROM staff WHERE id={i}')
 cred.unlink(missing_ok=True)
 print('Removed synthetic v2 records and credentials')
