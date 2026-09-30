"""Pure DOM-text normalization. No network, login or application-state access."""
import hashlib,re,unicodedata
from urllib.parse import urljoin,urlparse

def latin(value):
 return str(value).translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','01234567890123456789'))
def clean(value):
 return re.sub(r'\s+',' ',unicodedata.normalize('NFKC',latin(value)).replace('ي','ی').replace('ك','ک')).strip()
def allowed_link(href,base):
 u=urljoin(base,href);p=urlparse(u)
 return u if p.scheme=='https' and p.hostname in ('app.boghrat.com','account.boghrat.com') and not p.username and not p.password and p.port in (None,443) else None

def extract_profile(personal):
 text=clean(personal)
 def first(pattern):
  m=re.search(pattern,text,re.I)
  return clean(m.group(1)) if m else ''
 def digits(pattern):
  return latin(first(pattern))
 return {
  'mobile':digits(r'موبایل:\s*([0-9۰-۹٠-٩]+)'),
  'phone_home':digits(r'تلفن منزل:\s*([0-9۰-۹٠-٩]+)'),
  'referral_source':first(r'معرف:\s*(.+?)(?=\s+ثبت در بقراط:|\s+ثبت در مطب:|\s+کد ملی:|$)'),
  'source_registered_jalali':digits(r'ثبت در بقراط:\s*([0-9۰-۹٠-٩/\-]+)'),
  'clinic_registered_jalali':digits(r'ثبت در مطب:\s*([0-9۰-۹٠-٩/\-]+)'),
  'national_id':digits(r'کد ملی:\s*([0-9۰-۹٠-٩]{10})'),
  'father_name':first(r'نام پدر:\s*(.+?)(?=\s+وضعیت تاهل:|\s+وضعیت تأهل:|\s+تاریخ تولد:|$)'),
  'marital_status':first(r'وضعیت (?:تاهل|تأهل):\s*(.+?)(?=\s+تاریخ تولد:|\s+آدرس:|$)'),
  'birth_jalali':digits(r'تاریخ تولد:\s*([0-9۰-۹٠-٩/\-]+)'),
  'address':first(r'آدرس:\s*(.+?)(?=\s+بیماری های خاص:|\s+بیماری‌های خاص:|\s+assignment\s+فرم|$)'),
  'medical_conditions':first(r'بیماری(?:\s+های|‌های)\s+خاص:\s*(.+?)(?=\s+-برچسب|\s+assignment\s+فرم|$)')
 }

def extract_history_summary(history):
 lines=[clean(x) for x in str(history).splitlines() if clean(x)]
 events=[];current={}
 def flush():
  nonlocal current
  if current and any(current.get(k) for k in ('appointment_code','date_jalali','status','services','goods','notes','practitioner')):
   events.append(current)
  current={}
 for line in lines:
  m=re.search(r'(?:(?:شنبه|یکشنبه|دوشنبه|سه شنبه|سه‌شنبه|چهارشنبه|پنجشنبه|جمعه)\s*-\s*)?(1[2345]\d{2}/\d{1,2}/\d{1,2})',line)
  if m and ('ساعت' not in line) and ('ثبت' not in line):
   if current.get('appointment_code') or current.get('date_jalali'):flush()
   current['date_jalali']=m.group(1)
  m=re.search(r'ساعت\s*([0-2]?\d:[0-5]\d)',line)
  if m:current['time']=m.group(1)
  m=re.search(r'کد نوبت:\s*(\d+)',line)
  if m:current['appointment_code']=m.group(1)
  if 'assignment_ind' in line:
   value=clean(line.split('assignment_ind',1)[1])
   if value:current['practitioner']=value
  m=re.search(r'بابت:\s*(.+) if len(row)<5:raise ValueError('columns_changed')
 name=clean(row[1]);national=clean(row[3]);national=national if re.fullmatch(r'\d{10}',national) else ''
 mobile_match=re.search(r'(?<!\d)09\d{9}(?!\d)',clean(row[4]));mobile=mobile_match.group(0) if mobile_match else ''
 if not name or not personal.strip() or 'اطلاعات شخصی' not in personal:raise ValueError('empty_personal')
 # A government identifier is preferable. Shared phones are never sufficient alone.
 identity=('national:'+national) if national else ('contact:'+name+'|'+mobile if mobile else 'review:'+name+'|'+clean(row[2])+'|'+clean(row[4]))
 safe=[]
 for link in links:
  url=allowed_link(link.get('url',''),base)
  if url and not any(x['url']==url for x in safe):safe.append({'text':clean(link.get('text',''))[:250],'url':url})
 denied=any(x in history for x in ['عدم دسترسی','اجازه دسترسی','اشتراک شما'])
 profile=extract_profile(personal);profile['mobile']=profile['mobile'] or mobile;profile['national_id']=profile['national_id'] or national
 return {'source_key':hashlib.sha256(identity.encode()).hexdigest(),'name':name,'national_id':national,'mobile':mobile,'profile':profile,'history_summary':extract_history_summary(history),'forms':forms or {},'confidence':'national' if national else ('contact' if mobile else 'review'),'page':page,'row':index,'list_cells':row[:5],'personal':personal,'history':history,'links':safe,'complete':bool(history.strip()) and not denied,'limitations':['linked_destinations_not_downloaded','financial_values_not_posted_to_ledger'],'source_url':base}
,line)
  if m:current['reason']=clean(m.group(1))
  m=re.search(r'به صورت:\s*(.+) if len(row)<5:raise ValueError('columns_changed')
 name=clean(row[1]);national=clean(row[3]);national=national if re.fullmatch(r'\d{10}',national) else ''
 mobile_match=re.search(r'(?<!\d)09\d{9}(?!\d)',clean(row[4]));mobile=mobile_match.group(0) if mobile_match else ''
 if not name or not personal.strip() or 'اطلاعات شخصی' not in personal:raise ValueError('empty_personal')
 # A government identifier is preferable. Shared phones are never sufficient alone.
 identity=('national:'+national) if national else ('contact:'+name+'|'+mobile if mobile else 'review:'+name+'|'+clean(row[2])+'|'+clean(row[4]))
 safe=[]
 for link in links:
  url=allowed_link(link.get('url',''),base)
  if url and not any(x['url']==url for x in safe):safe.append({'text':clean(link.get('text',''))[:250],'url':url})
 denied=any(x in history for x in ['عدم دسترسی','اجازه دسترسی','اشتراک شما'])
 profile=extract_profile(personal);profile['mobile']=profile['mobile'] or mobile;profile['national_id']=profile['national_id'] or national
 return {'source_key':hashlib.sha256(identity.encode()).hexdigest(),'name':name,'national_id':national,'mobile':mobile,'profile':profile,'confidence':'national' if national else ('contact' if mobile else 'review'),'page':page,'row':index,'list_cells':row[:5],'personal':personal,'history':history,'links':safe,'complete':bool(history.strip()) and not denied,'limitations':['linked_destinations_not_downloaded','financial_values_not_posted_to_ledger'],'source_url':base}
,line)
  if m:current['mode']=clean(m.group(1))
  m=re.search(r'وضعیت:\s*(.+) if len(row)<5:raise ValueError('columns_changed')
 name=clean(row[1]);national=clean(row[3]);national=national if re.fullmatch(r'\d{10}',national) else ''
 mobile_match=re.search(r'(?<!\d)09\d{9}(?!\d)',clean(row[4]));mobile=mobile_match.group(0) if mobile_match else ''
 if not name or not personal.strip() or 'اطلاعات شخصی' not in personal:raise ValueError('empty_personal')
 # A government identifier is preferable. Shared phones are never sufficient alone.
 identity=('national:'+national) if national else ('contact:'+name+'|'+mobile if mobile else 'review:'+name+'|'+clean(row[2])+'|'+clean(row[4]))
 safe=[]
 for link in links:
  url=allowed_link(link.get('url',''),base)
  if url and not any(x['url']==url for x in safe):safe.append({'text':clean(link.get('text',''))[:250],'url':url})
 denied=any(x in history for x in ['عدم دسترسی','اجازه دسترسی','اشتراک شما'])
 profile=extract_profile(personal);profile['mobile']=profile['mobile'] or mobile;profile['national_id']=profile['national_id'] or national
 return {'source_key':hashlib.sha256(identity.encode()).hexdigest(),'name':name,'national_id':national,'mobile':mobile,'profile':profile,'confidence':'national' if national else ('contact' if mobile else 'review'),'page':page,'row':index,'list_cells':row[:5],'personal':personal,'history':history,'links':safe,'complete':bool(history.strip()) and not denied,'limitations':['linked_destinations_not_downloaded','financial_values_not_posted_to_ledger'],'source_url':base}
,line)
  if m:current['status']=clean(m.group(1))
  m=re.search(r'توضیحات:\s*(.+) if len(row)<5:raise ValueError('columns_changed')
 name=clean(row[1]);national=clean(row[3]);national=national if re.fullmatch(r'\d{10}',national) else ''
 mobile_match=re.search(r'(?<!\d)09\d{9}(?!\d)',clean(row[4]));mobile=mobile_match.group(0) if mobile_match else ''
 if not name or not personal.strip() or 'اطلاعات شخصی' not in personal:raise ValueError('empty_personal')
 # A government identifier is preferable. Shared phones are never sufficient alone.
 identity=('national:'+national) if national else ('contact:'+name+'|'+mobile if mobile else 'review:'+name+'|'+clean(row[2])+'|'+clean(row[4]))
 safe=[]
 for link in links:
  url=allowed_link(link.get('url',''),base)
  if url and not any(x['url']==url for x in safe):safe.append({'text':clean(link.get('text',''))[:250],'url':url})
 denied=any(x in history for x in ['عدم دسترسی','اجازه دسترسی','اشتراک شما'])
 profile=extract_profile(personal);profile['mobile']=profile['mobile'] or mobile;profile['national_id']=profile['national_id'] or national
 return {'source_key':hashlib.sha256(identity.encode()).hexdigest(),'name':name,'national_id':national,'mobile':mobile,'profile':profile,'confidence':'national' if national else ('contact' if mobile else 'review'),'page':page,'row':index,'list_cells':row[:5],'personal':personal,'history':history,'links':safe,'complete':bool(history.strip()) and not denied,'limitations':['linked_destinations_not_downloaded','financial_values_not_posted_to_ledger'],'source_url':base}
,line)
  if m:current['notes']=clean(m.group(1))
  m=re.search(r'خدمات ارائه شده:\s*(.+) if len(row)<5:raise ValueError('columns_changed')
 name=clean(row[1]);national=clean(row[3]);national=national if re.fullmatch(r'\d{10}',national) else ''
 mobile_match=re.search(r'(?<!\d)09\d{9}(?!\d)',clean(row[4]));mobile=mobile_match.group(0) if mobile_match else ''
 if not name or not personal.strip() or 'اطلاعات شخصی' not in personal:raise ValueError('empty_personal')
 # A government identifier is preferable. Shared phones are never sufficient alone.
 identity=('national:'+national) if national else ('contact:'+name+'|'+mobile if mobile else 'review:'+name+'|'+clean(row[2])+'|'+clean(row[4]))
 safe=[]
 for link in links:
  url=allowed_link(link.get('url',''),base)
  if url and not any(x['url']==url for x in safe):safe.append({'text':clean(link.get('text',''))[:250],'url':url})
 denied=any(x in history for x in ['عدم دسترسی','اجازه دسترسی','اشتراک شما'])
 profile=extract_profile(personal);profile['mobile']=profile['mobile'] or mobile;profile['national_id']=profile['national_id'] or national
 return {'source_key':hashlib.sha256(identity.encode()).hexdigest(),'name':name,'national_id':national,'mobile':mobile,'profile':profile,'confidence':'national' if national else ('contact' if mobile else 'review'),'page':page,'row':index,'list_cells':row[:5],'personal':personal,'history':history,'links':safe,'complete':bool(history.strip()) and not denied,'limitations':['linked_destinations_not_downloaded','financial_values_not_posted_to_ledger'],'source_url':base}
,line)
  if m:current['services']=clean(m.group(1))
  m=re.search(r'کالاهای ثبت شده:\s*(.+) if len(row)<5:raise ValueError('columns_changed')
 name=clean(row[1]);national=clean(row[3]);national=national if re.fullmatch(r'\d{10}',national) else ''
 mobile_match=re.search(r'(?<!\d)09\d{9}(?!\d)',clean(row[4]));mobile=mobile_match.group(0) if mobile_match else ''
 if not name or not personal.strip() or 'اطلاعات شخصی' not in personal:raise ValueError('empty_personal')
 # A government identifier is preferable. Shared phones are never sufficient alone.
 identity=('national:'+national) if national else ('contact:'+name+'|'+mobile if mobile else 'review:'+name+'|'+clean(row[2])+'|'+clean(row[4]))
 safe=[]
 for link in links:
  url=allowed_link(link.get('url',''),base)
  if url and not any(x['url']==url for x in safe):safe.append({'text':clean(link.get('text',''))[:250],'url':url})
 denied=any(x in history for x in ['عدم دسترسی','اجازه دسترسی','اشتراک شما'])
 profile=extract_profile(personal);profile['mobile']=profile['mobile'] or mobile;profile['national_id']=profile['national_id'] or national
 return {'source_key':hashlib.sha256(identity.encode()).hexdigest(),'name':name,'national_id':national,'mobile':mobile,'profile':profile,'confidence':'national' if national else ('contact' if mobile else 'review'),'page':page,'row':index,'list_cells':row[:5],'personal':personal,'history':history,'links':safe,'complete':bool(history.strip()) and not denied,'limitations':['linked_destinations_not_downloaded','financial_values_not_posted_to_ledger'],'source_url':base}
,line)
  if m:current['goods']=clean(m.group(1))
 flush()
 financial_keywords=('پرداخت','پرداختی','بدهی','بستانکار','دریافتی','مبلغ','تومان','ریال','تخفیف','بیمه','کسورات','هزینه','فاکتور','مانده')
 financial_lines=[]
 for line in lines:
  if any(k in line for k in financial_keywords):
   if line not in financial_lines:financial_lines.append(line[:800])
 return {'events':events[:500],'financial_lines':financial_lines[:500]}

def record_from_dom(row,personal,history,links,page,index,base,forms=None):
 if len(row)<5:raise ValueError('columns_changed')
 name=clean(row[1]);national=clean(row[3]);national=national if re.fullmatch(r'\d{10}',national) else ''
 mobile_match=re.search(r'(?<!\d)09\d{9}(?!\d)',clean(row[4]));mobile=mobile_match.group(0) if mobile_match else ''
 if not name or not personal.strip() or 'اطلاعات شخصی' not in personal:raise ValueError('empty_personal')
 # A government identifier is preferable. Shared phones are never sufficient alone.
 identity=('national:'+national) if national else ('contact:'+name+'|'+mobile if mobile else 'review:'+name+'|'+clean(row[2])+'|'+clean(row[4]))
 safe=[]
 for link in links:
  url=allowed_link(link.get('url',''),base)
  if url and not any(x['url']==url for x in safe):safe.append({'text':clean(link.get('text',''))[:250],'url':url})
 denied=any(x in history for x in ['عدم دسترسی','اجازه دسترسی','اشتراک شما'])
 profile=extract_profile(personal);profile['mobile']=profile['mobile'] or mobile;profile['national_id']=profile['national_id'] or national
 return {'source_key':hashlib.sha256(identity.encode()).hexdigest(),'name':name,'national_id':national,'mobile':mobile,'profile':profile,'confidence':'national' if national else ('contact' if mobile else 'review'),'page':page,'row':index,'list_cells':row[:5],'personal':personal,'history':history,'links':safe,'complete':bool(history.strip()) and not denied,'limitations':['linked_destinations_not_downloaded','financial_values_not_posted_to_ledger'],'source_url':base}
