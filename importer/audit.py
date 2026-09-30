#!/usr/bin/env python3
"""Read-only Boghrat capability inventory.

Uses only the authenticated web UI that the configured clinic account can see.
It does not call undocumented APIs, submit forms, download files, enumerate
patient records, or bypass CAPTCHA / OTP / access controls.

The report stores UI metadata only: routes, headings, tabs, button labels,
form-field names/types, table headers and navigation links. It deliberately
does not store page body text, input values, cookies, credentials or patient
record contents.
"""
import argparse
import json
import os
import pathlib
import re
import subprocess
import time
from collections import deque
from urllib.parse import urlparse, urlunparse, parse_qsl, urlencode

BASE = pathlib.Path(__file__).resolve().parent
APP_HOST = "app.boghrat.com"
ACCOUNT_HOST = "account.boghrat.com"
SAFE_QUERY_KEYS = {"_ilc", "tab", "type", "view", "section", "mode"}
BLOCKED_PATH_WORDS = {
    "logout", "signout", "delete", "remove", "destroy", "revoke",
    "deactivate", "disable-account", "terminate"
}


class AuditStop(Exception):
    pass


def bridge(op, **kw):
    p = subprocess.run(
        ["php", str(BASE / "bridge.php")],
        input=json.dumps({"op": op, **kw}),
        text=True,
        capture_output=True,
        timeout=30,
    )
    if p.returncode:
        raise AuditStop("local_storage_error")
    return json.loads(p.stdout)


def body_text(page):
    text = page.locator("body").inner_text(timeout=15000)
    low = text.lower()
    if any(v in low for v in [
        "verify you are human", "checking your browser", "unusual traffic",
        "automated requests", "access denied",
        "تأیید کنید ربات نیستید", "تایید کنید ربات نیستید",
        "دسترسی شما مسدود"
    ]):
        raise AuditStop("human_verification")
    if page.locator('input[autocomplete="one-time-code"]:visible').count():
        raise AuditStop("verification_required")
    return text


def login(page, connection):
    page.goto(connection["login_url"], wait_until="domcontentloaded", timeout=45000)
    end = time.monotonic() + 30
    user = password = None

    while time.monotonic() < end:
        host = urlparse(page.url).hostname
        if host == APP_HOST:
            break
        if host != ACCOUNT_HOST:
            raise AuditStop("unexpected_login_origin")
        body_text(page)
        user = page.locator('input[type="text"]:visible')
        password = page.locator('input[type="password"]:visible')
        if user.count() == 1 and password.count() == 1:
            break
        time.sleep(.5)

    if urlparse(page.url).hostname == ACCOUNT_HOST:
        if user is None or password is None or user.count() != 1 or password.count() != 1:
            raise AuditStop("login_form_changed")
        user.fill(connection["username"])
        password.fill(connection["password"])
        button = page.locator("button:visible").filter(has_text="ورود")
        if button.count() < 1:
            raise AuditStop("login_button_changed")
        button.first.click()

        end = time.monotonic() + 45
        while time.monotonic() < end:
            host = urlparse(page.url).hostname
            if host == APP_HOST:
                break
            if page.locator("ng-otp-input:visible").count():
                raise AuditStop("otp_required")
            body_text(page)
            time.sleep(.5)

    if urlparse(page.url).hostname != APP_HOST:
        raise AuditStop("login_not_completed")

    time.sleep(2)

    # Open the configured reception route once so the selected clinic context
    # is established and can be verified before exploring other visible routes.
    page.goto(connection["source_url"], wait_until="domcontentloaded", timeout=45000)
    end = time.monotonic() + 45
    while time.monotonic() < end:
        if urlparse(page.url).hostname != APP_HOST:
            raise AuditStop("unexpected_origin")
        body_text(page)
        clinic = page.locator('md-select[aria-label^="appClinic:"]')
        if clinic.count() == 1:
            try:
                clinic.first.wait_for(state="visible", timeout=3000)
                name = (clinic.first.get_attribute("aria-label") or "").removeprefix("appClinic:").strip()
                if name != connection["clinic_name"].strip():
                    raise AuditStop("clinic_mismatch")
                return
            except AuditStop:
                raise
            except Exception:
                pass
        time.sleep(.5)
    raise AuditStop("clinic_context_not_ready")


def safe_route(url):
    try:
        u = urlparse(url)
    except Exception:
        return None
    if u.scheme != "https" or u.hostname != APP_HOST or u.username or u.password:
        return None
    path = u.path or "/"
    low = path.lower()
    if any(word in low for word in BLOCKED_PATH_WORDS):
        return None

    query = []
    for k, v in parse_qsl(u.query, keep_blank_values=True):
        if k not in SAFE_QUERY_KEYS:
            return None
        query.append((k, v[:160]))
    return urlunparse(("https", APP_HOST, path, "", urlencode(query), ""))


def canonical_route(url):
    u = urlparse(url)
    query = [(k, v) for k, v in parse_qsl(u.query) if k in SAFE_QUERY_KEYS]
    return u.path + (("?" + urlencode(query)) if query else "")


def visible_strings(locator, limit=120):
    try:
        values = locator.evaluate_all(
            r"""els => els.filter(e => e.getClientRects().length)
              .map(e => (e.innerText || e.textContent || '').replace(/\s+/g,' ').trim())
              .filter(Boolean)"""
        )
    except Exception:
        return []
    out = []
    for v in values:
        v = str(v)[:300]
        if v not in out:
            out.append(v)
        if len(out) >= limit:
            break
    return out


def expand_navigation(page):
    # Only expand controls that explicitly live inside navigation containers.
    selectors = [
        'nav button[aria-expanded="false"]:visible',
        '[role="navigation"] button[aria-expanded="false"]:visible',
        'md-sidenav button[aria-expanded="false"]:visible',
    ]
    for selector in selectors:
        loc = page.locator(selector)
        for i in range(min(loc.count(), 30)):
            try:
                loc.nth(i).click(timeout=1500)
                page.wait_for_timeout(150)
            except Exception:
                pass


def extract_fields(page):
    try:
        return page.locator("input:visible, select:visible, textarea:visible").evaluate_all(
            r"""els => els.slice(0,250).map(e => {
              const id=e.id || '';
              const label=id ? document.querySelector('label[for="'+CSS.escape(id)+'"]') : null;
              return {
                tag:e.tagName.toLowerCase(),
                type:(e.getAttribute('type') || '').toLowerCase(),
                name:(e.getAttribute('name') || '').slice(0,120),
                label:((e.getAttribute('aria-label') || (label&&label.innerText) || e.getAttribute('placeholder') || '')).replace(/\s+/g,' ').trim().slice(0,240),
                required:e.required || e.getAttribute('aria-required')==='true',
                disabled:e.disabled,
                options:e.tagName==='SELECT' ? e.options.length : undefined
              };
            })"""
        )
    except Exception:
        return []


def extract_buttons(page):
    try:
        return page.locator("button:visible, [role=button]:visible").evaluate_all(
            r"""els => {
              const out=[];
              for (const e of els.slice(0,300)) {
                const text=(e.innerText || e.getAttribute('aria-label') || e.getAttribute('title') || '').replace(/\s+/g,' ').trim().slice(0,240);
                if(!text) continue;
                const item={text,disabled:!!e.disabled,expanded:e.getAttribute('aria-expanded')};
                if(!out.some(x=>x.text===item.text)) out.push(item);
              }
              return out;
            }"""
        )
    except Exception:
        return []


def extract_links(page):
    try:
        links = page.locator("a[href]:visible").evaluate_all(
            r"""els => els.slice(0,600).map(e => ({
              href:e.href,
              text:(e.innerText || e.getAttribute('aria-label') || e.getAttribute('title') || '').replace(/\s+/g,' ').trim().slice(0,240)
            }))"""
        )
    except Exception:
        return []
    out = []
    for item in links:
        href = safe_route(item.get("href", ""))
        if not href:
            continue
        route = canonical_route(href)
        rec = {"text": item.get("text", "")[:240], "route": route}
        if rec not in out:
            out.append(rec)
    return out[:300]

def navigation_candidates(page):
    selectors = (
        'nav a:visible, nav button:visible, '
        '[role="navigation"] a:visible, [role="navigation"] button:visible, '
        'md-sidenav a:visible, md-sidenav button:visible'
    )
    try:
        items = page.locator(selectors).evaluate_all(
            r"""els => els.slice(0,160).map((e,i) => ({
              index:i,
              text:(e.innerText || e.getAttribute('aria-label') || e.getAttribute('title') || '').replace(/\s+/g,' ').trim().slice(0,240),
              href:e.href || e.getAttribute('href') || e.getAttribute('ng-href') || '',
              disabled:!!e.disabled,
              type:(e.getAttribute('type') || '').toLowerCase(),
              tag:e.tagName.toLowerCase()
            }))"""
        )
    except Exception:
        return []
    out=[]
    for item in items:
        text=(item.get("text") or "").strip()
        low=text.lower()
        if not text or item.get("disabled") or item.get("type") == "submit":
            continue
        if any(x in low for x in ("خروج","حذف","ابطال","غیرفعال","delete","remove","logout","sign out","revoke")):
            continue
        href=safe_route(item.get("href","")) if item.get("href") else None
        out.append({"index":item["index"],"text":text[:240],"route":canonical_route(href) if href else None})
    return out[:120]


def discover_navigation_routes(page, base_url):
    found=[]
    candidates=navigation_candidates(page)
    selector=(
        'nav a:visible, nav button:visible, '
        '[role="navigation"] a:visible, [role="navigation"] button:visible, '
        'md-sidenav a:visible, md-sidenav button:visible'
    )
    for item in candidates:
        if item.get("route"):
            found.append({"text":item["text"],"route":item["route"]})
            continue
        try:
            page.goto(base_url,wait_until="domcontentloaded",timeout=45000)
            page.wait_for_timeout(500)
            expand_navigation(page)
            loc=page.locator(selector)
            if item["index"]>=loc.count():
                continue
            before=canonical_route(page.url)
            loc.nth(item["index"]).click(timeout=2000)
            page.wait_for_timeout(800)
            if urlparse(page.url).hostname!=APP_HOST:
                continue
            after=safe_route(page.url)
            if after and canonical_route(after)!=before:
                found.append({"text":item["text"],"route":canonical_route(after)})
        except AuditStop:
            raise
        except Exception:
            continue
    unique=[]
    for item in found:
        if item not in unique:
            unique.append(item)
    return unique[:120]



def extract_page(page):
    body_text(page)
    expand_navigation(page)
    page.wait_for_timeout(250)
    links = extract_links(page)
    headings = visible_strings(page.locator("h1:visible,h2:visible,h3:visible,h4:visible"), 120)
    tabs = visible_strings(page.locator('[role="tab"]:visible, md-tab-item:visible'), 100)
    tables = []
    for i in range(min(page.locator("table:visible").count(), 40)):
        try:
            headers = visible_strings(page.locator("table:visible").nth(i).locator("th:visible"), 80)
            if headers:
                tables.append(headers)
        except Exception:
            pass

    route=canonical_route(page.url)
    nav_controls=navigation_candidates(page)
    return {
        "route": route,
        "title": (page.title() or "")[:300],
        "headings": headings,
        "tabs": tabs,
        "buttons": extract_buttons(page),
        "fields": extract_fields(page),
        "tables": tables,
        "links": links,
        "navigation": nav_controls,
    }


def summarize(pages):
    features = []
    seen = set()
    for p in pages:
        for text in p.get("headings", []) + p.get("tabs", []) + [b["text"] for b in p.get("buttons", [])]:
            key = re.sub(r"\s+", " ", text).strip()
            if 2 <= len(key) <= 120 and key not in seen:
                seen.add(key)
                features.append(key)
    return {
        "pages": len(pages),
        "routes": [p["route"] for p in pages],
        "feature_labels": features[:500],
        "form_fields": sum(len(p.get("fields", [])) for p in pages),
        "tables": sum(len(p.get("tables", [])) for p in pages),
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--max-pages", type=int, default=200)
    parser.add_argument("--delay", type=float, default=1.5)
    args = parser.parse_args()
    args.max_pages = max(1, min(args.max_pages, 500))
    args.delay = max(.5, min(args.delay, 10))

    cfg = bridge("audit_config")
    if not cfg.get("active"):
        raise SystemExit("Boghrat credentials/clinic settings are not configured.")

    connection = cfg["connection"]
    storage = pathlib.Path(cfg["storage"]) / "importer"
    storage.mkdir(mode=0o700, parents=True, exist_ok=True)
    report_path = storage / "boghrat-capability-audit.json"

    from playwright.sync_api import sync_playwright

    pages = []
    visited = set()
    queued = set()
    queue = deque()

    with sync_playwright() as pw:
        profile = storage / ("audit-profile-" + str(abs(hash(connection["username"])))[:12])
        profile.mkdir(mode=0o700, exist_ok=True)
        context = pw.chromium.launch_persistent_context(
            str(profile),
            headless=False,
            executable_path=pw.chromium.executable_path,
            accept_downloads=False,
            chromium_sandbox=False,
            args=[
                "--disable-dev-shm-usage", "--disable-crash-reporter",
                "--disable-breakpad", "--disk-cache-size=16777216"
            ],
        )
        try:
            page = context.pages[0] if context.pages else context.new_page()
            page.set_default_timeout(15000)
            login(page, connection)
            del connection["password"]

            # Audit the configured reception page first, then all safe visible
            # same-origin routes discovered from navigation and page links.
            start = safe_route(page.url)
            if start:
                queue.append(start)
                queued.add(canonical_route(start))

            # Dashboard is another safe starting point already used by Boghrat.
            dashboard = "https://app.boghrat.com/dashboard/panel"
            if canonical_route(dashboard) not in queued:
                queue.append(dashboard)
                queued.add(canonical_route(dashboard))

            while queue and len(pages) < args.max_pages:
                url = queue.popleft()
                route = canonical_route(url)
                if route in visited:
                    continue
                try:
                    page.goto(url, wait_until="domcontentloaded", timeout=45000)
                    page.wait_for_timeout(900)
                    if urlparse(page.url).hostname != APP_HOST:
                        continue
                    item = extract_page(page)
                except AuditStop:
                    raise
                except Exception as exc:
                    pages.append({
                        "route": route,
                        "error": type(exc).__name__,
                    })
                    visited.add(route)
                    continue

                visited.add(item["route"])
                pages.append(item)
                print(f"AUDIT {len(pages):03d} {item['route']}", flush=True)

                discovered=list(item.get("links", []))
                # Angular menus may navigate from buttons instead of href links.
                # Only controls inside navigation containers are clicked; forms and
                # page action buttons are never submitted or activated here.
                try:
                    discovered += discover_navigation_routes(page, "https://" + APP_HOST + item["route"])
                except AuditStop:
                    raise

                for link in discovered:
                    href = "https://" + APP_HOST + link["route"]
                    safe = safe_route(href)
                    if not safe:
                        continue
                    key = canonical_route(safe)
                    if key not in visited and key not in queued:
                        queue.append(safe)
                        queued.add(key)

                time.sleep(args.delay)
        finally:
            context.close()

    report = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "clinic": connection.get("clinic_name", ""),
        "policy": {
            "same_origin_only": True,
            "forms_submitted": False,
            "files_downloaded": False,
            "undocumented_api_calls": False,
            "patient_body_text_stored": False,
        },
        "summary": summarize(pages),
        "pages": pages,
    }
    tmp = report_path.with_suffix(".tmp")
    tmp.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    os.chmod(tmp, 0o600)
    tmp.replace(report_path)

    summary = report["summary"]
    print(
        f"AUDIT_COMPLETE pages={summary['pages']} "
        f"fields={summary['form_fields']} tables={summary['tables']} "
        f"report={report_path}",
        flush=True,
    )


if __name__ == "__main__":
    try:
        main()
    except AuditStop as exc:
        print("AUDIT_STOPPED " + str(exc), flush=True)
        raise SystemExit(2)
