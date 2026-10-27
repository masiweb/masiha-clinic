"""Visible Boghrat SSO password-mode selection shared by importer and UI auditor.

Never requests OTP, bypasses verification, logs credentials, or uses private APIs.
The account host's default /auth/sso page now displays a mobile/OTP flow.
"""
import time
from urllib.parse import urlparse

ACCOUNT_HOST = "account.boghrat.com"
APP_HOST = "app.boghrat.com"


class PasswordModeError(Exception):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def password_form(page, check_page, timeout=25):
    """Return the two visible password-mode inputs or None if already in app."""
    deadline = time.monotonic() + timeout
    switched = False
    while time.monotonic() < deadline:
        host = urlparse(page.url).hostname
        if host == APP_HOST:
            return None
        if host != ACCOUNT_HOST:
            raise PasswordModeError("unexpected_login_origin")

        # Caller supplies its existing human/OTP-block detection.
        check_page(page)

        username = page.locator('input[type="text"]:visible')
        password = page.locator('input[type="password"]:visible')
        if username.count() == 1 and password.count() == 1:
            return username, password

        if not switched:
            switch = page.get_by_role("button", name="ورود با رمز عبور", exact=True)
            count = switch.count()
            if count > 1:
                raise PasswordModeError("password_switch_ambiguous")
            if count == 1 and switch.is_visible():
                switch.click(timeout=10000)
                switched = True
                # Check the newly rendered form immediately, without burning the wait budget.
                continue
        time.sleep(.35)

    raise PasswordModeError(
        "password_form_not_available" if switched else "login_form_changed"
    )
