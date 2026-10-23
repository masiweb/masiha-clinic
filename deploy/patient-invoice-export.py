#!/usr/bin/env python3
"""Generate patient invoice-style PDF/XLSX from scoped JSON fed through stdin.
No database access, no network requests and no patient data written to disk.
Dependencies in isolated /opt/masiha-clinic-report/.venv.
"""
import sys, json, io, html, datetime

def clean(value):
    return html.escape(str("" if value is None else value), quote=True)

def amount(value):
    try:
        return f"{int(round(float(value or 0))):,}"
    except (ValueError, TypeError):
        return "0"

def pdf_render(doc):
    from weasyprint import HTML
    meta=doc["meta"]
    sections=doc["sections"]
    sums=doc["summary"]
    totals=[("مانده ابتدای دوره","opening"),("هزینه دوره‌های درمان","billed"),
            ("پرداخت‌های مسیحا","paid"),("بدهکار ثبت‌شده بقراط","legacy_debit"),
            ("بستانکار ثبت‌شده بقراط","legacy_credit"),("تخفیف ثبت‌شده","discount"),
            ("اثر حذف تخفیف در این گزارش","adjust"),("تغییرات خالص بازه","change"),
            ("بدهی","debt"),("بستانکاری","credit"),("مانده نهایی حساب","balance")]
    style="""@page {size:A4 landscape;margin:13mm 12mm 14mm 12mm;
      @bottom-center { content:"گزارش محرمانه مراجع • صفحه " counter(page) " از " counter(pages);
       font-size:8pt;color:#6b7280;}}
    *{box-sizing:border-box}html{font-family:"DejaVu Sans",sans-serif;direction:rtl;}
    body{font-family:"DejaVu Sans",sans-serif;color:#273247;font-size:9px;margin:0;direction:rtl}
    .top{background:#752248;color:#fff;padding:14px 17px;border-radius:8px;display:flex;justify-content:space-between}
    h1{margin:0;font-size:18px;color:#fff}h2{font-size:11px;color:#752248;margin:15px 0 6px;border-bottom:1px solid #ead5df;padding-bottom:5px}
    .small{color:#6b7280;font-size:8px} .top .small{color:#f7dbe7}
    .info{display:flex;gap:8px;margin-top:10px}
    .info>div{flex:1;border:1px solid #dee3e9;background:#f8fafc;border-radius:6px;padding:7px 10px}
    .info b{display:block;font-size:10px;margin-top:4px;color:#273247}
    table{width:100%;border-collapse:collapse;table-layout:fixed;text-align:right;font-size:8px;direction:rtl}
    th{background:#f1e8ee;color:#6a2144;font-size:8px;padding:7px 5px;text-align:right}
    td{border-bottom:1px solid #e9edf3;padding:6px 5px;vertical-align:top;overflow-wrap:anywhere}
    tbody tr:nth-child(2n){background:#f9fbfd}thead{display:table-header-group}tr{break-inside:avoid;page-break-inside:avoid}
    .numbers{text-align:left;direction:ltr;white-space:nowrap;font-size:8px}
    .tag{display:inline-block;background:#eef5f3;color:#246f61;border-radius:4px;padding:3px 5px}
    .sum{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}
    .sum>div{width:18.7%;background:#f4f6fa;border:1px solid #e0e6eb;border-radius:6px;padding:7px}
    .sum b{display:block;font-size:10px;direction:ltr;text-align:right;margin-top:4px}
    .sum .emph{background:#e5f2ed;border-color:#a8d7c4;color:#11623e}
    .note{margin-top:11px;padding:9px;border:1px solid #d9dde6;background:#f7f9fc;border-radius:6px;font-size:8px;line-height:1.85}
    .sign{display:flex;justify-content:space-between;margin-top:18px;padding-top:10px;border-top:1px solid #e5e7eb;color:#6b7280}
    """
    out=[f'<html lang="fa" dir="rtl"><head><meta charset="UTF-8"/><style>{style}</style></head><body>',
        '<header class="top"><div><h1>صورتحساب و ریز خدمات درمانی مراجع</h1>',
        f'<span class="small">{clean(meta["clinic"])} • همه مبالغ به تومان</span></div>',
        f'<div><b>{clean(meta["issue_date"])}</b><div class="small">تاریخ صدور گزارش</div></div></header>',
        '<section class="info">',
        f'<div>نام مراجع<b>{clean(meta["patient"])}</b></div>',
        f'<div>شماره پرونده<b>{clean(meta["pid"])}</b></div>',
        f'<div>بازه گزارش<b>{clean(meta["from"])} تا {clean(meta["to"])}</b></div>',
        f'<div>مبنای گزارش<b>{clean(meta["mode"])}</b></div>',
        f'<div>تخفیف<b>{clean(meta["discount"])}</b></div>',
        '</section>',
        '<h2>ریز نوبت‌ها، خدمات و اقلام تحویلی</h2><table><thead><tr>']
    heads=["تاریخ","پزشک/درمانگر","وضعیت","خدمت و برنامه درمانی","کالا/تجهیزات","مبلغ خدمت","مبلغ کالا","تخفیف","پرداخت"]
    widths=[11,12,9,26,20,7,5,5,5]
    for h,w in zip(heads,widths):
        out.append(f'<th style="width:{w}%">{clean(h)}</th>')
    out.append('</tr></thead><tbody>')
    for x in sections["visits"]:
        vals=[x["date"],x["doctor"],x["status"],x["service"],x["goods"],x["service_amount"],x["goods_amount"],x["discount"],x["payment"]]
        out.append('<tr>')
        for i,v in enumerate(vals):
            out.append(f'<td class="{"numbers" if i>=5 else ""}">{amount(v) if i>=5 else clean(v)}</td>')
        out.append('</tr>')
    if not sections["visits"]:
        out.append('<tr><td colspan="9">مراجعه‌ای در این بازه ثبت نشده است.</td></tr>')
    out.append('</tbody></table><h2>ریز دریافت‌ها و پرداخت‌ها</h2><table><thead><tr><th style="width:18%">تاریخ</th><th style="width:22%">شماره دوره / نوبت</th><th>شرح</th><th style="width:20%">مبلغ (تومان)</th></tr></thead><tbody>')
    for x in sections["payments"]:
        out.append('<tr>'+''.join(f'<td class="{"numbers" if i==3 else ""}">{amount(v) if i==3 else clean(v)}</td>' for i,v in enumerate([x["date"],x["reference"],x["description"],x["amount"]]))+'</tr>')
    if not sections["payments"]:
        out.append('<tr><td colspan="4">پرداختی در این بازه ثبت نشده است.</td></tr>')
    out.append('</tbody></table><h2>جمع‌بندی حساب مراجع</h2><div class="sum">')
    for label,key in totals:
        klass="emph" if key in ("balance","debt","credit") else ""
        out.append(f'<div class="{klass}">{clean(label)}<b>{amount(sums.get(key,0))}</b></div>')
    out.append('</div>')
    out.append('<div class="note">توضیحات: تعرفه هر نوبت، اقلام تحویلی و ریز پرداخت‌های بقراط برای اطلاع نمایش داده شده‌اند و مجدداً به مبلغ صورتحساب افزوده نمی‌شوند. هزینه دوره درمان فقط یک‌بار در حساب منظور می‌شود. گزینه «عدم اعمال تخفیف» فقط محاسبه این نسخه از گزارش را تغییر می‌دهد؛ اسناد ثبت‌شده تغییری نمی‌کنند.</div>')
    out.append('<div class="sign"><span>این گزارش بر اساس اطلاعات ثبت‌شده در سامانه تهیه شده است.</span><span>محل مهر و امضای کلینیک: ........................................</span></div></body></html>')
    return HTML(string=''.join(out),base_url='/').write_pdf()

def xlsx_render(doc):
    import xlsxwriter
    mem=io.BytesIO()
    book=xlsxwriter.Workbook(mem,{'in_memory':True,'constant_memory':False})
    book.set_properties({'title':'صورتحساب درمان و حساب مراجع','comments':'گزارش محرمانه درمانی'})
    rtl=lambda x: x
    primary='#792748';navy='#23354C';mint='#DDF2E9';light='#F4F6FA';edge='#E5E8EF'
    title=book.add_format({'bold':True,'font_name':'Arial','font_size':19,'font_color':'#FFFFFF','bg_color':primary,'align':'center','valign':'vcenter'})
    head=book.add_format({'bold':True,'font_name':'Arial','font_size':10,'font_color':primary,'bg_color':'#F4E7ED','border':1,'border_color':edge,'align':'right','valign':'vcenter','text_wrap':True})
    value=book.add_format({'font_name':'Arial','font_size':10,'bg_color':'#FFFFFF','align':'right','valign':'vcenter','text_wrap':True,'bottom':1,'bottom_color':edge})
    alt=book.add_format({'font_name':'Arial','font_size':10,'bg_color':'#FAFBFE','align':'right','valign':'vcenter','text_wrap':True,'bottom':1,'bottom_color':edge})
    fmt_money=book.add_format({'font_name':'Arial','font_size':10,'bg_color':'#FFFFFF','align':'right','num_format':'#,##0;[Red](#,##0);–','bottom':1,'bottom_color':edge})
    fmt_money_alt=book.add_format({'font_name':'Arial','font_size':10,'bg_color':'#FAFBFE','align':'right','num_format':'#,##0;[Red](#,##0);–','bottom':1,'bottom_color':edge})
    section=book.add_format({'font_name':'Arial','bold':True,'font_size':12,'font_color':'#FFFFFF','bg_color':navy,'align':'right','valign':'vcenter'})
    key=book.add_format({'font_name':'Arial','font_size':10,'font_color':'#5C6470','bg_color':light,'align':'right','valign':'vcenter'})
    val=book.add_format({'font_name':'Arial','font_size':11,'bold':True,'font_color':navy,'bg_color':light,'align':'right','valign':'vcenter'})
    sum_key=book.add_format({'font_name':'Arial','font_size':10,'bold':True,'font_color':navy,'bg_color':mint,'border':1,'border_color':'#AFD8C7','align':'right'})
    sum_val=book.add_format({'font_name':'Arial','font_size':11,'bold':True,'font_color':'#18583D','bg_color':mint,'border':1,'border_color':'#AFD8C7','num_format':'#,##0;[Red](#,##0);–','align':'right'})
    info=book.add_format({'font_name':'Arial','font_size':10,'font_color':'#596477','text_wrap':True,'valign':'vcenter','align':'right'})
    w=book.add_worksheet('صورتحساب')
    w.right_to_left()
    for col,width in enumerate([17,22,17,45,42,19,18,17,17]):
        w.set_column(col,col,width)
    w.set_landscape();w.fit_to_pages(1,0);w.set_paper(9);w.repeat_rows(10);w.freeze_panes(11,0)
    w.set_header('&Cگزارش محرمانه خدمات درمانی')
    w.set_footer('&Rصفحه &P از &N')
    w.set_row(0,27);w.set_row(1,27)
    w.merge_range('A1:I2','صورتحساب و ریز خدمات درمانی مراجع',title)
    meta=doc['meta']
    w.merge_range('A3:I3',str(meta['clinic'])+' | تاریخ تهیه: '+str(meta['issue_date']),info)
    w.merge_range('A5:C5','مراجع: '+str(meta['patient']),val)
    w.merge_range('D5:F5','شماره پرونده: '+str(meta['pid']),val)
    w.merge_range('G5:I5','دوره: '+str(meta['from'])+' تا '+str(meta['to']),val)
    w.merge_range('A6:F6','مبنای گزارش: '+str(meta['mode']),key)
    w.merge_range('G6:I6','تخفیف: '+str(meta['discount']),key)
    w.merge_range('A9:I9','ریز نوبت‌ها، خدمات و تجهیزات تحویلی',section)
    heads=['تاریخ','پزشک / درمانگر','وضعیت','شرح خدمات / برنامه درمانی','کالا و تجهیزات','مبلغ خدمات / جلسه','مبلغ کالا','تخفیف','پرداخت']
    for col,h in enumerate(heads):w.write(10,col,h,head)
    row=11
    for i,x in enumerate(doc['sections']['visits']):
        w.set_row(row,34)
        cols=[x['date'],x['doctor'],x['status'],x['service'],x['goods'],x['service_amount'],x['goods_amount'],x['discount'],x['payment']]
        for col,v in enumerate(cols):
            f=(fmt_money if i%2==0 else fmt_money_alt) if col>=5 else (value if i%2==0 else alt)
            if col>=5:w.write_number(row,col,int(v or 0),f)
            else:w.write_string(row,col,str(v if v is not None else ''),f)
        row+=1
    if row==11:
        w.merge_range(row,0,row,8,'نوبتی در بازه انتخابی یافت نشد.',info);row+=1
    row+=2
    w.merge_range(row,0,row,8,'جمع‌بندی حساب (تومان)',section);row+=1
    for label,key_name in [('مانده ابتدای دوره','opening'),('هزینه دوره‌ها','billed'),('پرداخت داخلی','paid'),('بدهکار بقراط','legacy_debit'),('بستانکار بقراط','legacy_credit'),('تخفیف ثبت‌شده','discount'),('اثر حذف تخفیف','adjust'),('تغییر خالص دوره','change'),('بدهی','debt'),('بستانکاری','credit'),('مانده نهایی حساب','balance')]:
        w.merge_range(row,0,row,5,label,sum_key)
        w.merge_range(row,6,row,8,int(doc['summary'].get(key_name,0)),sum_val)
        row+=1
    row+=2
    w.merge_range(row,0,row+1,8,'نکته: جمع صورتحساب از حساب دوره‌ها و دفتر مالی محاسبه شده است. مبالغ جلسه، کالا و سوابق پرداخت بقراط صرفاً برای اطلاع نمایش داده می‌شوند و مجدداً در مانده حساب منظور نمی‌شوند. تغییر وضعیت تخفیف فقط محاسبه نمایشی این گزارش را عوض می‌کند.',info)
    w.print_area(0,0,row+1,8)
    payments=book.add_worksheet('ریز پرداخت‌ها')
    payments.right_to_left();payments.set_landscape();payments.fit_to_pages(1,0)
    for i,width in enumerate([20,24,65,23]):payments.set_column(i,i,width)
    payments.merge_range(0,0,1,3,'ریز دریافت‌ها و پرداخت‌های مراجع',title)
    for col,h in enumerate(['تاریخ','شماره دوره / نوبت','شرح','مبلغ (تومان)']):payments.write(3,col,h,head)
    row=4
    for i,pay in enumerate(doc['sections']['payments']):
        items=[pay['date'],pay['reference'],pay['description'],pay['amount']]
        for col,item in enumerate(items):
            f=(fmt_money if i%2==0 else fmt_money_alt) if col==3 else (value if i%2==0 else alt)
            if col==3:payments.write_number(row,col,int(item or 0),f)
            else:payments.write_string(row,col,str(item if item is not None else ''),f)
        row+=1
    if row==4:payments.merge_range(row,0,row,3,'پرداختی در بازه انتخابی ثبت نشده است.',info)
    payments.freeze_panes(4,0)
    book.close();return mem.getvalue()

def main():
    if len(sys.argv)!=2 or sys.argv[1] not in ('pdf','xlsx'):
        raise SystemExit('invalid format')
    raw=sys.stdin.buffer.read(8_000_001)
    if len(raw)>8_000_000:raise SystemExit('document too large')
    doc=json.loads(raw)
    if not isinstance(doc,dict) or not all(k in doc for k in ('meta','sections','summary')):
        raise SystemExit('invalid document')
    result=pdf_render(doc) if sys.argv[1]=='pdf' else xlsx_render(doc)
    sys.stdout.buffer.write(result)

if __name__=='__main__':main()
