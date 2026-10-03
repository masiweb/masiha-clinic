"""Pure DOM-text normalization. No network, login or application-state access."""
import hashlib
import re
import unicodedata
from urllib.parse import urljoin, urlparse


def latin(value):
    return str(value).translate(
        str.maketrans(
            "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩",
            "01234567890123456789",
        )
    )


def source_text(value):
    text=str(value)
    # Boghrat panel text can arrive with visible JSON-style escapes already
    # embedded in the DOM text. Decode only the harmless display escapes we
    # expect; do not run a general unicode_escape codec on patient content.
    return (
        text.replace("\\r\\n","\n")
            .replace("\\n","\n")
            .replace("\\r","\n")
            .replace("\\t","\t")
            .replace("\\/","/")
    )


def clean(value):
    return re.sub(
        r"\s+",
        " ",
        unicodedata.normalize("NFKC", latin(source_text(value)))
        .replace("ي", "ی")
        .replace("ك", "ک"),
    ).strip()


def allowed_link(href, base):
    url = urljoin(base, href)
    parsed = urlparse(url)
    if (
        parsed.scheme == "https"
        and parsed.hostname in ("app.boghrat.com", "account.boghrat.com")
        and not parsed.username
        and not parsed.password
        and parsed.port in (None, 443)
    ):
        return url
    return None


def extract_profile(personal):
    text = clean(personal)

    def first(pattern):
        match = re.search(pattern, text, re.I)
        return clean(match.group(1)) if match else ""

    def digits(pattern):
        return latin(first(pattern))

    return {
        "mobile": digits(r"موبایل:\s*([0-9۰-۹٠-٩]+)"),
        "phone_home": digits(r"تلفن منزل:\s*([0-9۰-۹٠-٩]+)"),
        "referral_source": first(
            r"معرف:\s*(.+?)(?=\s+ثبت در بقراط:|\s+ثبت در مطب:|\s+کد ملی:|\s+نام پدر:|\s+تاریخ تولد:|\s+آدرس:|$)"
        ),
        "source_registered_jalali": digits(
            r"ثبت در بقراط:\s*([0-9۰-۹٠-٩/\-]+)"
        ),
        "clinic_registered_jalali": digits(
            r"ثبت در مطب:\s*([0-9۰-۹٠-٩/\-]+)"
        ),
        "national_id": digits(r"کد ملی:\s*([0-9۰-۹٠-٩]{10})"),
        "father_name": first(
            r"نام پدر:\s*(.+?)(?=\s+وضعیت تاهل:|\s+وضعیت تأهل:|\s+تاریخ تولد:|\s+شغل:|\s+تحصیلات:|\s+قد:|\s+آدرس:|\s+بیماری(?:\s+های|‌های)\s+خاص:|\s+assignment\s+فرم|$)"
        ),
        "marital_status": first(
            r"وضعیت (?:تاهل|تأهل):\s*(.+?)(?=\s+تاریخ تولد:|\s+آدرس:|\s+بیماری(?:\s+های|‌های)\s+خاص:|\s+assignment\s+فرم|$)"
        ),
        "birth_jalali": digits(r"تاریخ تولد:\s*([0-9۰-۹٠-٩/\-]+)"),
        "address": first(
            r"آدرس:\s*(.+?)(?=\s+بیماری های خاص:|\s+بیماری‌های خاص:|\s+assignment\s+فرم|$)"
        ),
        "medical_conditions": clean(
            (re.search(r"بیماری(?:\s+های|‌های)\s+خاص:[ \t]*([^\n\r]*)", source_text(personal), re.I) or [None, ""])[1]
        ),
        "occupation": first(
            r"شغل:\s*(.+?)(?=\s+تحصیلات:|\s+قد:|\s+آدرس:|\s+بیماری(?:\s+های|‌های)\s+خاص:|$)"
        ),
        "education": first(
            r"تحصیلات:\s*(.+?)(?=\s+شغل:|\s+قد:|\s+آدرس:|\s+بیماری(?:\s+های|‌های)\s+خاص:|$)"
        ),
        "height_cm": money_number(first(r"قد:\s*([0-9۰-۹٠-٩]+)")),
    }


def money_number(value):
    text = clean(value)
    match = re.search(r"-?\d[\d,]*", text)
    if not match:
        return None
    try:
        return int(match.group(0).replace(",", ""))
    except ValueError:
        return None


def split_services(value):
    text = clean(value)
    if not text:
        return []
    items = []
    # Boghrat separates service items with the Persian comma. English commas
    # are also thousands separators inside prices and must not split items.
    for part in re.split(r"\s*،\s*", text):
        part = clean(part)
        if not part:
            continue
        match = re.match(r"^(.*?)(?:\s*\((-?\d[\d,]*)\))?$", part)
        name = clean(match.group(1)) if match else part
        amount = money_number(match.group(2)) if match and match.group(2) else None
        if name:
            items.append({"name": name, "amount_toman": amount})
    return items[:100]


def split_goods(value):
    text = clean(value)
    if not text:
        return []
    items = []
    for part in re.split(r"\s*،\s*", text):
        part = clean(part)
        if not part:
            continue
        match = re.match(r"(\d+)\s*[×xX⨯]\s*(.+)$", part)
        if match:
            items.append({"quantity": int(match.group(1)), "name": clean(match.group(2))})
        else:
            items.append({"quantity": 1, "name": part})
    return items[:100]


def normalize_form_name(value):
    name=clean(value)
    for prefix in ("assignment ", "adjust ", "more_horiz "):
        if name.startswith(prefix):
            name=name[len(prefix):].strip()
    return name[:160] or "فرم"


def normalize_form_answer(value):
    value=clean(value)
    value=re.sub(r"\s*\(\s*-\s*\)\s*$","",value).strip()
    return "" if value in ("-","--","—","(-)") else value[:2000]


def extract_form_fields(forms):
    out=[]
    noise_prefixes=(
        "اطلاعات مراجعه کننده","close","person اطلاعات شخصی",
        "history سوابق ویزیت و پرداخت","assignment فرم","more_horiz",
        "print","فقط سوالات دارای جواب نمایش داده شود","adjust فرم",
    )
    for raw_name,content in (forms or {}).items():
        form_name=normalize_form_name(raw_name)
        lines=[clean(x) for x in source_text(content).splitlines() if clean(x)]
        submitted=""
        for line in lines:
            m=re.search(r"\(\s*ثبت:\s*(1[2345]\d{2}/\d{1,2}/\d{1,2})\s*\)",line)
            if m:
                submitted=m.group(1)
                break
        if submitted:
            out.append({"form_name":form_name,"field_name":"تاریخ ثبت فرم","field_value":submitted,"field_type":"date","is_meta":True})

        start_at=0
        for idx,line in enumerate(lines):
            if line.startswith("adjust فرم"):
                start_at=idx+1
                break
        lines=lines[start_at:]
        i=0
        order=0
        while i<len(lines):
            line=lines[i]
            if not line or line=="(-)" or any(line.startswith(p) for p in noise_prefixes) or re.fullmatch(r"\(\s*ثبت:.*\)",line):
                i+=1
                continue

            label=value=""
            consumed=1

            # Question mark is the strongest boundary for Persian questionnaire labels.
            if "؟" in line:
                head,tail=line.split("؟",1)
                label=clean(head)+" ؟"
                value=normalize_form_answer(tail)
                if not value and i+1<len(lines):
                    nxt=lines[i+1]
                    if nxt!="(-)" and not any(nxt.startswith(p) for p in noise_prefixes) and "؟" not in nxt and ":" not in nxt:
                        value=normalize_form_answer(nxt)
                        consumed=2
            elif ":" in line:
                head,tail=line.split(":",1)
                label=clean(head)
                value=normalize_form_answer(tail)
                if not value and i+1<len(lines):
                    nxt=lines[i+1]
                    if nxt!="(-)" and not any(nxt.startswith(p) for p in noise_prefixes) and "؟" not in nxt:
                        value=normalize_form_answer(nxt)
                        consumed=2

            if label and label not in ("ثبت","اطلاعات مراجعه کننده"):
                field_type="date" if "تاریخ" in label else "text"
                out.append({
                    "form_name":form_name,
                    "field_name":label[:160],
                    "field_value":value,
                    "field_type":field_type,
                    "is_meta":False,
                    "sort_order":order,
                })
                order+=1
            i+=consumed
    return out[:2000]


def extract_history_summary(history):
    lines = [clean(line) for line in source_text(history).splitlines() if clean(line)]
    events = []
    current = {}
    finance_summary = {}

    summary_labels = {
        "درآمد خدمات": "service_revenue_toman",
        "درآمد کالاها": "goods_revenue_toman",
        "پرداختی ها": "payments_toman",
        "پرداختی‌ها": "payments_toman",
        "بازگشت وجه": "refunds_toman",
        "تخفیف ها": "discounts_toman",
        "تخفیف‌ها": "discounts_toman",
        "مابه التفاوت": "difference_toman",
        "مابه‌التفاوت": "difference_toman",
    }

    def flush():
        nonlocal current
        if current and any(
            current.get(key)
            for key in (
                "appointment_code",
                "date_jalali",
                "status",
                "services",
                "goods",
                "notes",
                "practitioner",
                "debt",
                "credit_toman",
                "service_cost_toman",
                "payments_toman",
            )
        ):
            charge = current.get("service_cost_toman")
            if charge is not None:
                current["charge_total_toman"] = charge
                priced_services = current.get("service_items") or []
                if priced_services and all(item.get("amount_toman") is not None for item in priced_services):
                    service_total = sum(int(item["amount_toman"]) for item in priced_services)
                    current["service_items_total_toman"] = service_total
                    goods = current.get("goods_items") or []
                    if goods and charge >= service_total:
                        goods_total = charge - service_total
                        current["goods_cost_toman"] = goods_total
                        if len(goods) == 1:
                            goods[0]["amount_toman"] = goods_total
            current["discounts_toman"] = sum(
                int(item.get("amount_toman") or 0)
                for item in (current.get("discounts") or [])
            )
            events.append(current)
        current = {}

    for index, line in enumerate(lines):
        for label, key in summary_labels.items():
            match = re.search(re.escape(label) + r":\s*(-?[\d,]+)", line)
            if match:
                value = money_number(match.group(1))
                if value is not None:
                    finance_summary[key] = value

        match = re.search(
            r"(?:(?:شنبه|یکشنبه|دوشنبه|سه شنبه|سه‌شنبه|چهارشنبه|پنجشنبه|جمعه)\s*-\s*)?"
            r"(1[2345]\d{2}/\d{1,2}/\d{1,2})",
            line,
        )
        if match and "ساعت" not in line and "ثبت" not in line:
            if current.get("appointment_code") or current.get("date_jalali"):
                flush()
            current["date_jalali"] = match.group(1)

        match = re.search(r"ساعت\s*([0-2]?\d:[0-5]\d)", line)
        if match:
            current["time"] = match.group(1)

        match = re.search(r"کد نوبت:\s*(\d+)", line)
        if match:
            current["appointment_code"] = match.group(1)

        if "assignment_ind" in line:
            value = clean(line.split("assignment_ind", 1)[1])
            if value:
                current["practitioner"] = value

        match = re.search(r"بابت:\s*(.+)$", line)
        if match:
            current["reason"] = clean(match.group(1))

        match = re.search(r"به صورت:\s*(.+)$", line)
        if match:
            current["mode"] = clean(match.group(1))

        match = re.search(r"وضعیت:\s*(.+)$", line)
        if match:
            current["status"] = clean(match.group(1))

        match = re.search(r"زمان ثبت نوبت:\s*(1[2345]\d{2}/\d{1,2}/\d{1,2})\s+([0-2]?\d:[0-5]?\d)", line)
        if match:
            current["registered_at_jalali"] = match.group(1) + " " + match.group(2)

        match = re.search(r"نحوه ثبت نوبت:\s*(.+)$", line)
        if match:
            current["registration_method"] = clean(match.group(1))

        match = re.search(r"توضیحات:\s*(.+)$", line)
        if match:
            current["notes"] = clean(match.group(1))

        match = re.search(r"خدمات ارائه شده:\s*(.+)$", line)
        if match:
            current["services"] = clean(match.group(1))
            current["service_items"] = split_services(match.group(1))

        match = re.search(r"کالاهای ثبت شده:\s*(.+)$", line)
        if match:
            current["goods"] = clean(match.group(1))
            current["goods_items"] = split_goods(match.group(1))

        match = re.search(r"بدهی:\s*(.+)$", line)
        if match:
            current["debt"] = clean(match.group(1))
            amount = money_number(match.group(1))
            if amount is not None:
                # Boghrat renders patient debt as a negative balance. Store the
                # actual debt as a positive obligation and preserve the raw text.
                current["debt_toman"] = abs(amount)
            elif current["debt"] == "تسویه حساب":
                current["debt_toman"] = 0

        match = re.search(r"بستانکاری:\s*([\d,]+)", line)
        if match:
            amount = money_number(match.group(1))
            if amount is not None:
                current["credit_toman"] = amount

        match = re.search(r"هزینه خدمات:\s*([\d,]+)", line)
        if match:
            amount = money_number(match.group(1))
            if amount is not None:
                current["service_cost_toman"] = amount

        match = re.search(
            r"مجموع پرداختی:\s*([\d,]+).*?مجموع تسویه حساب:\s*([\d,]+)",
            line,
        )
        if match:
            paid = money_number(match.group(1))
            settled = money_number(match.group(2))
            if paid is not None:
                current["payments_toman"] = paid
            if settled is not None:
                current["settled_toman"] = settled

        amount_line = re.fullmatch(r"\(?\s*([\d,]+)\s*\)?", line)
        if amount_line and index + 1 < len(lines):
            next_line = lines[index + 1]
            method_match = re.match(r"تومان\s*(.*)$", next_line)
            if method_match:
                amount = money_number(amount_line.group(1))
                method = clean(method_match.group(1)) or "نامشخص"
                if amount is not None:
                    if method == "تخفیف":
                        current.setdefault("discounts", []).append(
                            {"amount_toman": amount, "method": "تخفیف"}
                        )
                    else:
                        current.setdefault("payment_methods", []).append(
                            {"amount_toman": amount, "method": method}
                        )

    flush()

    # Boghrat sometimes renders payment/credit details as a second block with
    # the same date but without an appointment code. Merge that block only
    # when there is exactly one coded encounter on the same date. This keeps
    # payments attached to the visit without guessing on multi-visit days.
    merged_events = []
    for event in events:
        if not event.get("appointment_code") and event.get("date_jalali"):
            candidates = [
                item for item in merged_events
                if item.get("date_jalali") == event.get("date_jalali")
                and item.get("appointment_code")
            ]
            if len(candidates) == 1:
                target = candidates[0]
                for key in ("payment_methods", "discounts"):
                    if event.get(key):
                        target.setdefault(key, []).extend(event[key])
                for key, value in event.items():
                    if key in ("payment_methods", "discounts", "date_jalali"):
                        continue
                    if value not in (None, "", [], {}):
                        target[key] = value
                target["discounts_toman"] = sum(
                    int(item.get("amount_toman") or 0)
                    for item in (target.get("discounts") or [])
                )
                continue
        merged_events.append(event)
    events = merged_events

    financial_keywords = (
        "پرداخت",
        "بدهی",
        "بستانکار",
        "دریافتی",
        "تخفیف",
        "بیمه",
        "کسورات",
        "هزینه",
        "فاکتور",
        "مانده",
        "درآمد",
        "بازگشت وجه",
        "مابه التفاوت",
        "مابه‌التفاوت",
        "کارتخوان",
        "انتقال به حساب",
    )
    ui_only = {
        "history سوابق ویزیت و پرداخت",
        "پرداختی ها",
        "پرداختی‌ها",
        "نمایش بدهی لحظه ای",
        "نمایش بدهی لحظه‌ای",
        "تومان",
        "ریال",
    }
    financial_lines = []
    for line in lines:
        if line in ui_only or line.startswith("adjust سوابق ویزیت و پرداخت"):
            continue
        if any(keyword in line for keyword in financial_keywords):
            clipped = line[:800]
            if clipped not in financial_lines:
                financial_lines.append(clipped)

    difference = finance_summary.get("difference_toman")
    if difference is not None:
        finance_summary["outstanding_toman"] = max(0, -difference)
        finance_summary["credit_balance_toman"] = max(0, difference)

    transactions = []
    for event_no, event in enumerate(events[:500]):
        common = {
            "event_no": event_no,
            "date_jalali": event.get("date_jalali", ""),
            "appointment_code": event.get("appointment_code", ""),
        }
        charge = event.get("service_cost_toman")
        if charge is not None:
            transactions.append({
                **common,
                "type": "service_charge",
                "amount_toman": charge,
                "method": "",
                "description": "جمع هزینه مراجعه",
                "is_snapshot": False,
            })
        for method in event.get("payment_methods") or []:
            amount = method.get("amount_toman")
            if amount is None:
                continue
            transactions.append({
                **common,
                "type": "payment",
                "amount_toman": amount,
                "method": clean(method.get("method", "")),
                "description": "پرداخت مراجعه‌کننده",
                "is_snapshot": False,
            })
        for discount in event.get("discounts") or []:
            amount = discount.get("amount_toman")
            if amount is None:
                continue
            transactions.append({
                **common,
                "type": "discount",
                "amount_toman": amount,
                "method": "تخفیف",
                "description": "تخفیف مراجعه",
                "is_snapshot": False,
            })
        if event.get("debt"):
            transactions.append({
                **common,
                "type": "debt_snapshot",
                "amount_toman": event.get("debt_toman", 0 if clean(event.get("debt")) == "تسویه حساب" else None),
                "method": "",
                "description": clean(event.get("debt")),
                "is_snapshot": True,
            })
        if event.get("credit_toman") is not None:
            transactions.append({
                **common,
                "type": "credit_snapshot",
                "amount_toman": event.get("credit_toman"),
                "method": "",
                "description": "بستانکاری",
                "is_snapshot": True,
            })

    for tx in transactions:
        if tx.get("type") != "payment" or tx.get("appointment_code") or not tx.get("date_jalali"):
            continue
        candidates = [
            (event_no, event)
            for event_no, event in enumerate(events[:500])
            if event.get("date_jalali") == tx.get("date_jalali")
            and event.get("appointment_code")
            and event.get("charge_total_toman") is not None
        ]
        amount = tx.get("amount_toman")
        if candidates and amount is not None:
            total = sum(int(event.get("charge_total_toman") or 0) for _, event in candidates)
            if total == int(amount):
                tx["allocations"] = [
                    {
                        "event_no": event_no,
                        "appointment_code": event.get("appointment_code", ""),
                        "amount_toman": int(event.get("charge_total_toman") or 0),
                    }
                    for event_no, event in candidates
                ]

    return {
        "events": events[:500],
        "financial_lines": financial_lines[:500],
        "financial_summary": finance_summary,
        "transactions": transactions[:2000],
    }


def record_from_dom(row, personal, history, links, page, index, base, forms=None):
    if len(row) < 5:
        raise ValueError("columns_changed")

    name = clean(row[1])
    national = clean(row[3])
    national = national if re.fullmatch(r"\d{10}", national) else ""

    mobile_match = re.search(r"(?<!\d)09\d{9}(?!\d)", clean(row[4]))
    mobile = mobile_match.group(0) if mobile_match else ""

    if not name or not personal.strip() or "اطلاعات شخصی" not in personal:
        raise ValueError("empty_personal")

    if national:
        identity = "national:" + national
    elif mobile:
        identity = "contact:" + name + "|" + mobile
    else:
        identity = "review:" + name + "|" + clean(row[2]) + "|" + clean(row[4])

    safe = []
    for link in links:
        url = allowed_link(link.get("url", ""), base)
        if url and not any(item["url"] == url for item in safe):
            safe.append(
                {
                    "text": clean(link.get("text", ""))[:250],
                    "url": url,
                }
            )

    denied = any(
        marker in history
        for marker in ("عدم دسترسی", "اجازه دسترسی", "اشتراک شما")
    )

    profile = extract_profile(personal)
    profile["mobile"] = profile["mobile"] or mobile
    profile["national_id"] = profile["national_id"] or national

    return {
        "source_key": hashlib.sha256(identity.encode()).hexdigest(),
        "name": name,
        "national_id": national,
        "mobile": mobile,
        "profile": profile,
        "history_summary": extract_history_summary(history),
        "forms": forms or {},
        "form_fields": extract_form_fields(forms or {}),
        "confidence": "national" if national else ("contact" if mobile else "review"),
        "page": page,
        "row": index,
        "list_cells": row[:5],
        "personal": personal,
        "history": history,
        "links": safe,
        "complete": bool(history.strip()) and not denied,
        "limitations": [
            "linked_destinations_not_downloaded",
            "financial_values_not_posted_to_ledger",
        ],
        "source_url": base,
    }
