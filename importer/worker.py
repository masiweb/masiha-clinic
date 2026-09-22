#!/usr/bin/env python3
"""User-operated server importer. Not an agent browser-control interface.
Read-only selectors observed in the clinic UI; no hidden APIs, stealth or CAPTCHA bypass.
Never log credentials, page text, URLs with session state, or patient data.
"""
import json,sys,subprocess,time,os,fcntl,re,pathlib,shutil,signal
from urllib.parse import urlparse
from parser import record_from_dom
BASE=pathlib.Path(__file__).resolve().parent
class Stop(Exception):
 def __init__(self,code,status='error'):self.code=code;self.status=status

def bridge(op,**kw):
 p=subprocess.run(['php',str(BASE/'bridge.php')],input=json.dumps({'op':op,**kw}),text=True,capture_output=True,timeout=30)
 if p.returncode:raise Stop('local_storage_error')
 return json.loads(p.stdout)

def visible_block(page):
 text=page.locator('body').inner_text(timeout=15000)
 if any(v in text.lower() for v in ['verify you are human','checking your browser','unusual traffic','automated requests','access denied','تأیید کنید ربات نیستید','تایید کنید ربات نیستید','دسترسی شما مسدود']):raise Stop('human_verification','blocked')
 # A login or OTP form after initial sign-in requires operator intervention.
 if page.locator('input[autocomplete="one-time-code"]:visible').count():raise Stop('verification_required','blocked')
 return text

def stable_text(loc):
 end=time.monotonic()+20;last=None;stable=0
 while time.monotonic()<end:
  current=loc.inner_text()
  if current==last and '{{' not in current:stable+=1
  else:stable=0
  if stable>=3 and len(current)>100:return current
  last=current;time.sleep(.5)
 raise Stop('content_not_ready')

def panel(page):
 page.get_by_role('button',name='person اطلاعات شخصی',exact=True).wait_for(state='visible',timeout=20000)
 result=page.locator('.mt-panel:visible').filter(has=page.get_by_role('button',name='person اطلاعات شخصی',exact=True))
 if result.count()!=1:raise Stop('profile_panel_changed')
 return result

def patient_rows(page):
 return page.locator('tr:visible').filter(has=page.locator('button[aria-label="info"]'))

def signature(page):
 return patient_rows(page).evaluate_all('(rows)=>rows.map(r=>r.innerText).join("\\n")')

def wait_rows(page,previous=None):
 end=time.monotonic()+20
 while time.monotonic()<end:
  visible_block(page)
  if patient_rows(page).count() and (previous is None or signature(page)!=previous):return
  time.sleep(.4)
 try:
  tr=page.locator('tr:visible').count()
  td=page.locator('td:visible').count()
  info=page.locator('button[aria-label="info"]:visible').count()
  buttons=page.locator('button:visible').count()
  role_rows=page.locator('[role="row"]:visible').count()
  mat_rows=page.locator('mat-row:visible').count()
  md_rows=page.locator('md-row:visible').count()
  table=page.locator('table:visible').count()
  print(f'Patient list diagnostic: tr={tr} td={td} info={info} buttons={buttons} role_rows={role_rows} mat_rows={mat_rows} md_rows={md_rows} table={table}',flush=True)
  labels=page.locator('button[aria-label]:visible').evaluate_all('(els)=>{const m={};for(const e of els){const v=e.getAttribute("aria-label")||"";if(v)m[v]=(m[v]||0)+1;}return m;}')
  print('Patient list button aria: '+json.dumps(labels,ensure_ascii=False,sort_keys=True),flush=True)
  shapes=page.locator('tr:visible').evaluate_all('(rows)=>rows.slice(0,12).map((r,i)=>({i,td:r.querySelectorAll("td").length,th:r.querySelectorAll("th").length,buttons:r.querySelectorAll("button").length,aria:[...r.querySelectorAll("button")].map(b=>b.getAttribute("aria-label")).filter(Boolean),links:r.querySelectorAll("a").length}))')
  print('Patient list row shapes: '+json.dumps(shapes,ensure_ascii=False),flush=True)
  parents=page.locator('td:visible').evaluate_all('''cells=>{
    const count={};
    for(const c of cells){
      const p=c.parentElement;
      const g=p&&p.parentElement;
      const key=[p&&p.tagName,p&&p.className,g&&g.tagName,g&&g.className].map(x=>(x||'').toString().slice(0,120)).join('|');
      count[key]=(count[key]||0)+1;
    }
    return Object.entries(count).sort((a,b)=>b[1]-a[1]).slice(0,20);
  }''')
  print('Patient td parent shapes: '+json.dumps(parents,ensure_ascii=False),flush=True)
  button_cells=page.locator('td:visible').filter(has=page.locator('button')).evaluate_all('''cells=>cells.slice(0,40).map((c,i)=>{
    const p=c.parentElement,g=p&&p.parentElement;
    const bs=[...c.querySelectorAll('button')];
    return {
      i,
      parentTag:p&&p.tagName,
      parentClass:(p&&p.className||'').toString().slice(0,160),
      grandTag:g&&g.tagName,
      grandClass:(g&&g.className||'').toString().slice(0,160),
      siblingTd:p?p.querySelectorAll(':scope > td').length:0,
      buttons:bs.map(b=>({
        aria:b.getAttribute('aria-label'),
        title:b.getAttribute('title'),
        cls:(b.className||'').toString().slice(0,140),
        icon:[...b.querySelectorAll('md-icon,mat-icon,i')].map(x=>(x.getAttribute('aria-label')||x.getAttribute('fonticon')||x.className||'').toString().slice(0,100))
      }))
    };
  })''')
  print('Patient button cell shapes: '+json.dumps(button_cells,ensure_ascii=False),flush=True)
 except Exception as e:
  print('Patient list diagnostic failed: '+type(e).__name__,flush=True)
 raise Stop('patient_list_timeout')

def page_number(page):
 n=page.locator('li[role="button"].active:visible')
 if n.count()!=1:raise Stop('pagination_changed')
 try:return int(n.inner_text().translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹','0123456789')))
 except ValueError:raise Stop('pagination_changed')

def turn_page(page,wanted):
 # Only visible pagination controls are used; no guessed endpoint or id enumeration.
 current=page_number(page)
 while current<wanted:
  state=bridge('state',run_id=RUN)['run']
  if state['status']!='running':raise Stop('operator_pause','paused')
  visible=page.locator('li[role="button"]:visible').filter(has_text=re.compile('^'+str(wanted)+'$'))
  previous=signature(page)
  if visible.count()==1:visible.click()
  else:
   nxt=page.locator('li[role="button"]:visible').filter(has_text='keyboard_arrow_left')
   if nxt.count()!=1 or 'disable' in (nxt.get_attribute('class') or ''):raise Stop('end_of_list','complete')
   nxt.click()
  wait_rows(page,previous);new=page_number(page)
  if new<=current:raise Stop('pagination_did_not_advance')
  current=new
  time.sleep(1)
 if current!=wanted:raise Stop('pagination_mismatch')

def sign_in(page,c):
 # Login-first flow: authenticate on account.boghrat.com before opening reception.
 print('Boghrat login-first: opening login',flush=True)

 page.goto(c['login_url'],wait_until='domcontentloaded',timeout=45000)

 # Angular login form may need a moment to render.
 login_ready_end=time.monotonic()+20
 user=None
 password=None

 while time.monotonic()<login_ready_end:
  host=urlparse(page.url).hostname
  path=urlparse(page.url).path

  print(f'Boghrat login-first route: {host}{path}',flush=True)

  # Existing valid session may immediately redirect to app.
  if host=='app.boghrat.com':
   break

  if host!='account.boghrat.com':
   raise Stop('unexpected_login_origin','blocked')

  visible_block(page)

  user=page.locator('input[type="text"]:visible')
  password=page.locator('input[type="password"]:visible')

  if user.count()==1 and password.count()==1:
   break

  time.sleep(.5)

 host=urlparse(page.url).hostname

 if host=='account.boghrat.com':
  if user is None or password is None or user.count()!=1 or password.count()!=1:
   raise Stop('login_form_changed','blocked')

  print('Boghrat login-first: form_ready',flush=True)

  user.fill(c['username'])
  password.fill(c['password'])

  login_button=page.locator('button:visible').filter(has_text='ورود')

  if login_button.count()<1:
   raise Stop('login_button_changed','blocked')

  login_button.first.click()

  print('Boghrat login-first: login_clicked',flush=True)

  login_end=time.monotonic()+40
  last_route=None

  while time.monotonic()<login_end:
   host=urlparse(page.url).hostname
   path=urlparse(page.url).path
   route=f'{host}{path}'

   if route!=last_route:
    print(f'Boghrat login-first after click: {route}',flush=True)
    last_route=route

   if host=='app.boghrat.com':
    break

   if page.locator('ng-otp-input:visible').count():
    raise Stop('otp_required','blocked')

   # Detect an explicit human-verification page, but do not bypass it.
   visible_block(page)

   time.sleep(.5)

 if urlparse(page.url).hostname!='app.boghrat.com':
  raise Stop('login_not_completed','blocked')

 # Give the account frontend a moment to persist shared-domain auth cookies.
 time.sleep(2)

 try:
  names={
   x.get('name','')
   for x in page.context.cookies()
   if x.get('domain','').endswith('boghrat.com')
  }

  print(
   f'Boghrat auth cookies: '
   f'access={"boghrat-access-token" in names} '
   f'refresh={"boghrat-refresh-token" in names}',
   flush=True
  )
 except Exception:
  print('Boghrat auth cookies: check_failed',flush=True)

 print('Boghrat login-first: opening reception',flush=True)

 page.goto(
  c['source_url'],
  wait_until='domcontentloaded',
  timeout=45000
 )

 reception_end=time.monotonic()+40
 last_route=None

 while time.monotonic()<reception_end:
  host=urlparse(page.url).hostname
  path=urlparse(page.url).path
  route=f'{host}{path}'

  if route!=last_route:
   print(f'Boghrat reception route: {route}',flush=True)
   last_route=route

  if host=='account.boghrat.com':
   raise Stop('session_lost_after_login','blocked')

  if host!='app.boghrat.com':
   raise Stop('unexpected_origin','blocked')

  visible_block(page)

  clinic=page.locator('md-select[aria-label^="appClinic:"]')

  ready=(
   path.startswith('/clinic/secretary/reception')
   and clinic.count()==1
  )

  if ready:
   try:
    clinic.first.wait_for(state='visible',timeout=3000)
   except Exception:
    time.sleep(.5)
    continue

   clinic_name=(
    clinic.get_attribute('aria-label') or ''
   ).removeprefix('appClinic:').strip()

   if clinic_name!=c['clinic_name'].strip():
    raise Stop('clinic_mismatch','blocked')

   print('Boghrat reception: clinic_ready',flush=True)

   all_patients=page.get_by_role(
    'button',
    name='supervisor_account تمامی مراجعین',
    exact=True
   )

   all_patients.wait_for(state='visible',timeout=10000)
   all_patients.click()

   wait_rows(page)

   print(
    f'Boghrat reception: rows_ready count={patient_rows(page).count()}',
    flush=True
   )

   return

  time.sleep(.5)

 raise Stop('reception_not_ready','blocked')


RUN=0

def main():
 global RUN
 cfg=bridge('config')
 if not cfg.get('active'):return
 r=cfg['run'];RUN=int(r['id']);c=cfg['connection'];storage=pathlib.Path(cfg['storage']);private=storage/'importer';private.mkdir(mode=0o700,exist_ok=True)
 lock=open(private/'worker.lock','w')
 try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
 except BlockingIOError:return
 if shutil.disk_usage(storage).free<536870912:raise Stop('disk_space','space')
 for key,host in [('login_url','account.boghrat.com'),('source_url','app.boghrat.com')]:
  u=urlparse(c[key])
  if u.scheme!='https' or u.hostname!=host or u.username or u.password or u.port not in (None,443):raise Stop('invalid_origin','blocked')
 # Session profile is private, local to the account and never included in source archives.
 profile=private/('profile-'+r['account_key']);profile.mkdir(mode=0o700,exist_ok=True)
 from playwright.sync_api import sync_playwright
 with sync_playwright() as pw:
  context=pw.chromium.launch_persistent_context(str(profile),headless=False,executable_path=pw.chromium.executable_path,accept_downloads=False,chromium_sandbox=False,args=['--disable-dev-shm-usage','--disable-crash-reporter','--disable-breakpad','--disk-cache-size=16777216'])
  try:
   page=context.pages[0] if context.pages else context.new_page();page.set_default_timeout(15000)
   # No popup actions, downloads, notifications or permissions are granted.
   sign_in(page,c);del c['password'];turn_page(page,int(r['page_no']))
   while True:
    r=bridge('state',run_id=RUN)['run']
    if r['status']!='running':return
    if shutil.disk_usage(storage).free<536870912:raise Stop('disk_space','space')
    quota=bridge('reserve',run_id=RUN)
    if not quota.get('ok'):
     if quota.get('stop'):return
     time.sleep(min(15,max(1,int(quota.get('wait',15)))));continue
    if urlparse(page.url).hostname!='app.boghrat.com':raise Stop('session_expired','blocked')
    visible_block(page);rows=patient_rows(page);index=int(r['row_no']);number=int(r['page_no'])
    if index==rows.count() and rows.count()>0:
     turn_page(page,number+1);bridge('cursor',run_id=RUN,page=number+1,row=0);continue
    if index>=rows.count():raise Stop('list_changed_resume_review')
    row=rows.nth(index);cells=row.locator('td').all_inner_texts()
    if len(cells)!=6:raise Stop('columns_changed')
    row.locator('button[aria-label="info"]').click();p=panel(page)
    p.get_by_role('button',name='person اطلاعات شخصی',exact=True).click();personal=stable_text(p)
    links=p.locator('a[href]').evaluate_all('(es)=>es.filter(e=>e.getClientRects().length).map(e=>({url:e.href,text:e.innerText}))')
    p.get_by_role('button',name='history سوابق ویزیت و پرداخت',exact=True).click();stable_text(p);visible_block(page)
    details=p.get_by_role('checkbox',name='نمایش جزئیات',exact=True)
    if details.count()==1 and details.is_visible() and details.get_attribute('aria-checked')!='true':details.click();page.wait_for_timeout(500)
    # Read all rendered history, including content below the modal scroll viewport.
    history=stable_text(p);links+=p.locator('a[href]').evaluate_all('(es)=>es.filter(e=>e.getClientRects().length).map(e=>({url:e.href,text:e.innerText}))')
    record=record_from_dom(cells,personal,history,links,number,index,c['source_url'])
    # Nested history pagination is deliberately reported partial until reviewed.
    if p.locator('li[role="button"]').count():record['complete']=False;record['limitations'].append('history_pagination_requires_review')
    result=bridge('save',run_id=RUN,record=record,next_page=number,next_row=index+1)
    if not result.get('ok'):return
    p.locator('.mt-panel-close').click()
    # Advancing a page is a separate checkpoint, safe to repeat after a crash.
    if index+1>=patient_rows(page).count():
     nxt=page.locator('li[role="button"]:visible').filter(has_text='keyboard_arrow_left')
     if nxt.count()!=1 or 'disable' in (nxt.get_attribute('class') or ''):raise Stop('end_of_list','complete')
     turn_page(page,number+1);bridge('cursor',run_id=RUN,page=number+1,row=0)
  finally:context.close()
if __name__=='__main__':
 os.umask(0o077)
 try:main()
 except Stop as e:
  if RUN and e.status!='paused':bridge('stop',run_id=RUN,status=e.status,code=e.code)
  print('Importer stopped: '+e.code)
 except Exception as err:
  import traceback
  frames=[f for f in traceback.extract_tb(err.__traceback__) if f.filename.endswith('/worker.py') or f.filename.endswith('/parser.py')]
  where=(';'.join(f.name+':'+str(f.lineno) for f in frames))[:160] or 'unknown'
  print('Importer diagnostic: type='+type(err).__name__+' location='+where,flush=True)
  if RUN:
   try:bridge('stop',run_id=RUN,status='error',code='runtime_error')
   except Exception:pass
  print('Importer stopped: runtime_error');sys.exit(1)
