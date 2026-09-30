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


def clean(value):
    return re.sub(
        r"\s+",
        " ",
        unicodedata.normalize("NFKC", latin(value))
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
            r"معرف:\s*(.+?)(?=\s+ثبت در بقراط:|\s+ثبت در مطب:|\s+کد ملی:|$)"
        ),
        "source_registered_jalali": digits(
            r"ثبت در بقراط:\s*([0-9۰-۹٠-٩/\-]+)"
        ),
        "clinic_registered_jalali": digits(
            r"ثبت در مطب:\s*([0-9۰-۹٠-٩/\-]+)"
        ),
        "national_id": digits(r"کد ملی:\s*([0-9۰-۹٠-٩]{10})"),
        "father_name": first(
            r"نام پدر:\s*(.+?)(?=\s+وضعیت تاهل:|\s+وضعیت تأهل:|\s+تاریخ تولد:|$)"
        ),
        "marital_status": first(
            r"وضعیت (?:تاهل|تأهل):\s*(.+?)(?=\s+تاریخ تولد:|\s+آدرس:|$)"
        ),
        "birth_jalali": digits(r"تاریخ تولد:\s*([0-9۰-۹٠-٩/\-]+)"),
        "address": first(
            r"آدرس:\s*(.+?)(?=\s+بیماری های خاص:|\s+بیماری‌های خاص:|\s+assignment\s+فرم|$)"
        ),
        "medical_conditions": first(
            r"بیماری(?:\s+های|‌های)\s+خاص:\s*(.+?)(?=\s+-برچسب|\s+assignment\s+فرم|$)"
        ),
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


def extract_history_summary(history):
    lines = [clean(line) for line in str(history).splitlines() if clean(line)]
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
            events.append(current)
        current = {}

    for index, line in enumerate(lines):
        for label, key in summary_labels.items():
            match = re.search(re.escape(label) + r":\s*([\d,]+)", line)
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

        match = re.search(r"توضیحات:\s*(.+)$", line)
        if match:
            current["notes"] = clean(match.group(1))

        match = re.search(r"خدمات ارائه شده:\s*(.+)$", line)
        if match:
            current["services"] = clean(match.group(1))

        match = re.search(r"کالاهای ثبت شده:\s*(.+)$", line)
        if match:
            current["goods"] = clean(match.group(1))

        match = re.search(r"بدهی:\s*(.+)$", line)
        if match:
            current["debt"] = clean(match.group(1))
            amount = money_number(match.group(1))
            if amount is not None:
                current["debt_toman"] = amount

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

        if re.fullmatch(r"[\d,]+", line) and index + 1 < len(lines):
            next_line = lines[index + 1]
            method_match = re.match(r"تومان\s*(.*)$", next_line)
            if method_match:
                amount = money_number(line)
                if amount is not None:
                    current.setdefault("payment_methods", []).append(
                        {
                            "amount_toman": amount,
                            "method": clean(method_match.group(1)) or "نامشخص",
                        }
                    )

    flush()

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

    return {
        "events": events[:500],
        "financial_lines": financial_lines[:500],
        "financial_summary": finance_summary,
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
