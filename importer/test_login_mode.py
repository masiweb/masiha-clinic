#!/usr/bin/env python3
"""Offline regression tests for Boghrat SSO password login selection."""
import pathlib
import sys
import unittest
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parent))
from login_mode import password_form, PasswordModeError


class Element:
    def __init__(self, available=True, action=None):
        self.available = available
        self.action = action
        self.clicked = 0

    def count(self):
        return int(self.available)

    def is_visible(self):
        return bool(self.available)

    def click(self, **kwargs):
        if not self.available:
            raise AssertionError("clicked invisible element")
        self.clicked += 1
        if self.action:
            self.action()


class Page:
    def __init__(self, url="https://account.boghrat.com/auth/sso", ready=False, switch=True):
        self.url = url
        self.ready = ready
        self.switch = switch
        self.mode_button = Element(switch, lambda: setattr(self, "ready", True))
        self.username = Element()
        self.password = Element()

    def locator(self, selector):
        if selector == 'input[type="text"]:visible':
            return self.username
        if selector == 'input[type="password"]:visible':
            self.password.available = self.ready
            return self.password
        raise AssertionError(selector)

    def get_by_role(self, role, name=None, exact=None):
        if role != "button" or name != "ورود با رمز عبور" or not exact:
            raise AssertionError("unexpected UI control")
        return self.mode_button


class Tests(unittest.TestCase):
    def test_sso_toggles_to_password(self):
        p = Page()
        inputs = password_form(p, lambda page: None, timeout=.3)
        self.assertIsNotNone(inputs)
        self.assertEqual(p.mode_button.clicked, 1)

    def test_existing_password_form_no_extra_click(self):
        p = Page(ready=True)
        self.assertIsNotNone(password_form(p, lambda page: None, timeout=.3))
        self.assertEqual(p.mode_button.clicked, 0)

    def test_already_logged_in(self):
        p = Page(url="https://app.boghrat.com/dashboard/panel")
        self.assertIsNone(password_form(p, lambda page: None, timeout=.3))
        self.assertEqual(p.mode_button.clicked, 0)

    def test_missing_switch_is_safe_failure(self):
        p = Page(switch=False)
        with self.assertRaises(PasswordModeError) as error:
            password_form(p, lambda page: None, timeout=.15)
        self.assertEqual(error.exception.code, "login_form_changed")

    def test_unexpected_host_is_blocked(self):
        p = Page(url="https://example.net/auth/sso")
        with self.assertRaises(PasswordModeError) as error:
            password_form(p, lambda page: None, timeout=.3)
        self.assertEqual(error.exception.code, "unexpected_login_origin")

    def test_human_verification_is_not_bypassed(self):
        p = Page()
        def blocking(_page):
            raise RuntimeError("human_verification")
        with self.assertRaisesRegex(RuntimeError, "human_verification"):
            password_form(p, blocking, timeout=.3)
        self.assertEqual(p.mode_button.clicked, 0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
