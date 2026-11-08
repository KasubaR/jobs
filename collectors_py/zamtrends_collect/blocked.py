"""Telling "the page" from "a wall in front of the page".

The worker never tries to get past a wall. It recognises one, stops, and says so. These checks
look for things that only a wall says; they are deliberately specific, so an ordinary page that
merely mentions a word like "captcha" is not mistaken for one.
"""

from __future__ import annotations

import re
from urllib.parse import urlsplit

from .errors import Blocked, CollectError

# Statuses that mean "you may not": login needed, forbidden, proxy auth, too many requests, legal block.
BLOCKING_STATUSES = {401, 403, 407, 429, 451}

# Phrases and markup that only challenge / block / paywall pages contain.
WALL_MARKERS = [
    "g-recaptcha", "h-captcha", "hcaptcha.com/1/api", "cf-turnstile", "cf-challenge", "cf_chl_", "/cdn-cgi/challenge-platform",
    "checking your browser before accessing", "verify you are human", "verify you are a human", "are you a robot",
    "unusual traffic from your computer", "px-captcha", "datadome", "incapsula incident", "request unsuccessful. incapsula",
    "pardon our interruption", "attention required! | cloudflare", "access to this page has been denied",
    "your request has been blocked", "automated access", "bot detection",
    "subscribe to continue reading", "subscription required to read", "this content is for subscribers only",
]

LOGIN_PATH = re.compile(r"/(log-?in|sign-?in|signin|auth|account/login|users/sign_in)(/|$|\?)", re.I)

HEAD_BYTES = 30_000  # walls announce themselves near the top; scanning only this keeps false alarms down


def looks_like_login(url: str) -> bool:
    return LOGIN_PATH.search(urlsplit(url).path + ("?" + urlsplit(url).query if urlsplit(url).query else "")) is not None


def check(status: int, requested_url: str, final_url: str, text: str) -> None:
    """Raise Blocked if the response is a wall, CollectError if it is simply an error, else return."""
    if status in BLOCKING_STATUSES:
        raise Blocked(f"HTTP {status}: the site refused the request")

    if looks_like_login(final_url) and not looks_like_login(requested_url):
        raise Blocked("redirected to a login page: this content needs an account")

    head = text[:HEAD_BYTES].lower()
    for marker in WALL_MARKERS:
        if marker in head:
            raise Blocked(f"the page is a challenge or block page (found '{marker}')")

    if status >= 400:
        raise CollectError(f"HTTP {status}")
