"""Playwright: a real browser for pages that only show their ads after JavaScript runs.

Same rules as the plain fetcher: robots.txt first, throttle, no retries, walls reported and never
worked around. The browser is plain headless Chromium with the worker's honest User-Agent: no stealth
plugins, no fingerprint changes, no proxies. Images, video and fonts are not downloaded (lighter on the
site), which does not change who we are. One page at a time.
"""

from __future__ import annotations

from urllib.parse import urlsplit

from . import blocked
from .errors import Blocked, CollectError
from .fetcher import Fetcher, Throttle
from .page import Page

SKIPPED_RESOURCES = {"image", "media", "font"}


class BrowserFetcher:
    def __init__(self, fetcher: Fetcher, headless: bool = True):
        # Reuse the plain fetcher's robots guard, throttle and limits so both paths obey identically.
        self.fetcher = fetcher
        self.headless = headless
        self._playwright = None
        self._browser = None
        self._context = None
        self._page = None
        self.pages_loaded = 0

    def __enter__(self) -> "BrowserFetcher":
        try:
            from playwright.sync_api import sync_playwright
        except ImportError as e:  # pragma: no cover
            raise CollectError("Playwright is not installed. See collectors_py/README.md.") from e

        self._playwright = sync_playwright().start()
        try:
            self._browser = self._playwright.chromium.launch(headless=self.headless)
        except Exception as e:  # browser binary missing
            self._playwright.stop()
            raise CollectError(f"Chromium could not start ({e.__class__.__name__}). Run: python -m playwright install chromium") from e

        self._context = self._browser.new_context(user_agent=self.fetcher.user_agent, viewport={"width": 1280, "height": 800})
        self._context.route("**/*", self._skip_heavy_files)
        self._page = self._context.new_page()
        self._page.set_default_timeout(self.fetcher.timeout * 1000)
        return self

    def __exit__(self, *exc) -> None:
        for closer in (self._context, self._browser):
            try:
                if closer:
                    closer.close()
            except Exception:  # pragma: no cover
                pass
        if self._playwright:
            self._playwright.stop()

    @staticmethod
    def _skip_heavy_files(route) -> None:
        if route.request.resource_type in SKIPPED_RESOURCES:
            route.abort()
        else:
            route.continue_()

    def get(self, url: str, wait_for: str | None = None) -> Page:
        """Load a page, let its JavaScript run, and return the result. `wait_for` is a CSS selector that
        must appear (the ads list); if it never does, that is an error (the layout may have changed)."""
        self.fetcher.check_allowed(url)
        self.fetcher.throttle.wait(url, self.fetcher.robots.crawl_delay(url))

        try:
            response = self._page.goto(url, wait_until="domcontentloaded")
        except Exception as e:
            raise CollectError(f"could not load {url}: {e.__class__.__name__}") from e

        self.pages_loaded += 1
        status = response.status if response else 0
        final = self._page.url

        if urlsplit(final).netloc.lower() != urlsplit(url).netloc.lower():
            raise Blocked(f"{url} led to another site ({urlsplit(final).netloc}); not followed")

        if wait_for:
            try:
                self._page.wait_for_selector(wait_for)
            except Exception:
                # A wall can be why the expected content never came: check before blaming the layout.
                blocked.check(status, url, final, self._page.content())
                raise CollectError(f"'{wait_for}' did not appear on {url}. The site's layout may have changed.")

        html = self._page.content()
        if len(html.encode("utf-8")) > self.fetcher.max_bytes:
            raise CollectError(f"page larger than the allowed {self.fetcher.max_bytes // 1024} KB; not read")

        blocked.check(status, url, final, html)

        return Page(url=final, status=status, text=html)
