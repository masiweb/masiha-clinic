#!/usr/bin/env python3
import json,pathlib,re,sys

REPORT=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/var/lib/masiha-clinic/importer/boghrat-capability-audit.json')
data=json.loads(REPORT.read_text(encoding='utf-8'))
categories={
 'مالی':['مالی','پرداخت','پرداختی','بدهی','بستانکار','دریافتی','هزینه','درآمد','تراز','صندوق','فاکتور','مانده','تخفیف'],
 'مراجعین و پذیرش':['مراجع','بیمار','پذیرش','پرونده','اطلاعات شخصی'],
 'نوبت و حضور':['نوبت','تقویم','حضور','غیبت','وقت','شیفت'],
 'خدمات و تعرفه':['خدمت','خدمات','تعرفه','پورسانت','ویزیت'],
 'بیمه':['بیمه','کسورات','دیسکت','نسخه'],
 'کالا و موجودی':['کالا','موجودی','انبار','مصرفی','مواد'],
 'فرم و مدارک':['فرم','مدرک','گواهی','چاپ','آزمایش','گرافی','تصویر'],
 'گزارش‌ها':['گزارش','آمار','عملکرد'],
 'تنظیمات و کاربران':['تنظیمات','کاربر','همکار','پرسنل','دسترسی','سطح دسترسی','مطب','کلینیک'],
 'پیام و ارتباط':['پیامک','اطلاع رسانی','اطلاع‌رسانی','مشاوره','تلفن','تصویری','متنی']
}
items=[]
for page in data.get('pages',[]):
 route=page.get('route','')
 labels=[]
 for k in ('headings','tabs'):
  labels += [str(x) for x in page.get(k,[]) if x]
 labels += [str(x.get('text','')) for x in page.get('buttons',[]) if isinstance(x,dict)]
 labels += [str(x.get('label','')) for x in page.get('fields',[]) if isinstance(x,dict)]
 labels += [str(x.get('text','')) for x in page.get('navigation',[]) if isinstance(x,dict)]
 for table in page.get('tables',[]):
  labels += [str(x) for x in table]
 text=' | '.join([route]+labels)
 items.append((route,labels,text))

print('# خلاصه ممیزی امکانات بقراط')
print()
print(f"- صفحات بررسی‌شده: {data.get('summary',{}).get('pages',0)}")
print(f"- فیلدهای فرم دیده‌شده: {data.get('summary',{}).get('form_fields',0)}")
print(f"- جدول‌های دیده‌شده: {data.get('summary',{}).get('tables',0)}")
print()
for category,keywords in categories.items():
 matches=[]
 seen=set()
 for route,labels,text in items:
  if any(k in text for k in keywords):
   compact=[]
   for label in labels:
    label=re.sub(r'\s+',' ',label).strip()
    if label and len(label)<=120 and any(k in label for k in keywords):
     if label not in compact:compact.append(label)
   key=(route,tuple(compact[:12]))
   if key in seen:continue
   seen.add(key);matches.append((route,compact[:12]))
 print(f'## {category}')
 if not matches:
  print('- موردی در Audit پیدا نشد.')
 else:
  for route,labels in matches[:40]:
   suffix=(' — '+ '، '.join(labels)) if labels else ''
   print(f'- {route}{suffix}')
 print()
