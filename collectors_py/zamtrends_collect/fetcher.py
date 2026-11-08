"""Plain HTTP fetching with every rule built in: robots.txt, throttle, size cap, no retries,
no redirects off the site, and walls recognised and reported (never worked around)."""

from __future__ import annotations

import time
from urllib.parse import urljoin, urlsplit

import requests

from . import blocked
from .errors import Blocked, CollectError, RobotsDisallowed
from .page import Page
from .robots import RobotsGuard

MAX_REDIRECTS = 3
REDIRECT_STATUSES = {301, 302, 303, 307, 308}


class Throttle:
    """At most one request per host every `minimum` seconds (more if robots.txt asks for it)."""

    def __init__(self, minimum: float, clock=time.monotonic, sleep=time.sleep):
        self.minimum = float(minimum)
        self._clock = clock
        self._sleep = sleep
        self._last: dict[str, float] = {}

    def wait(self, url: str, crawl_delay: float | None = None) -> None:
        host = urlsplit(url).netloc.lower()
        gap = max(self.minimum, crawl_delay or 0.0)
        last = self._last.get(host)
        if last is not None:
            remaining = gap - (self._clock() - last)
            if remaining > 0:
                self._sleep(remaining)
        self._last[host] = self._clock()


class Fetcher:
    def __init__(self, user_agent: str, min_interval: float = 1.0, max_bytes: int = 5 * 1024 * 1024, timeout: float = 20.0,
                 session: requests.Session | None = None):
        self.user_agent = user_agent
        self.max_bytes = int(max_bytes)
        self.timeout = timeout
        self.session = session or requests.Session()
        self.robots = RobotsGuard(user_agent, self.session, timeout)
        self.throttle = Throttle(min_interval)
        self.requests_made = 0

    def check_allowed(self, url: str) -> None:
        if not self.robots.allows(url):
            raise RobotsDisallowed(f"robots.txt does not allow {url} (or could not be read), so it was not fetched")

    def get(self, url: str) -> Page:
        """One page. Never retries: a failure is reported once, then the source backs off."""
        original_host = urlsplit(url).netloc.lower()
        current = url

        for _hop in range(MAX_REDIRECTS + 1):
            self.check_allowed(current)
            self.throttle.wait(current, self.robots.crawl_delay(current))

            try:
                response = self.session.get(
                    current, headers={"User-Agent": self.user_agent, "Accept": "text/html,application/xhtml+xml"},
                    timeout=self.timeout, allow_redirects=False, stream=True,
                )
            except requests.RequestException as e:
                raise CollectError(f"could not reach {current}: {e.__class__.__name__}") from e
            self.requests_made += 1

            if response.status_code in REDIRECT_STATUSES and response.headers.get("Location"):
                target = urljoin(current, response.headers["Location"])
                response.close()

                if urlsplit(target).netloc.lower() != original_host:
                    raise Blocked(f"{current} redirects to another site ({urlsplit(target).netloc}); not followed")
                if blocked.looks_like_login(target) and not blocked.looks_like_login(url):
                    raise Blocked("redirected to a login page: this content needs an account")
                current = target
                continue

            text = self._read(response)
            blocked.check(response.status_code, url, current, text)

            return Page(url=current, status=response.status_code, text=text)

        raise CollectError(f"too many redirects from {url}")

    def _read(self, response: requests.Response) -> str:
        chunks: list[bytes] = []
        size = 0
        for chunk in response.iter_content(chunk_size=65536):
            size += len(chunk)
            if size > self.max_bytes:
                response.close()
                raise CollectError(f"response larger than the allowed {self.max_bytes // 1024} KB; not read")
            chunks.append(chunk)
        response.encoding = response.encoding or response.apparent_encoding or "utf-8"
        return b"".join(chunks).decode(response.encoding, errors="replace")
