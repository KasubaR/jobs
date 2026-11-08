"""A local website for the tests. Nothing here contacts a real site: every request goes to 127.0.0.1."""

from __future__ import annotations

import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

SITE = Path(__file__).resolve().parent / "site"


class FakeSite:
    """State the tests can change: what robots.txt says, and a record of every request received."""

    def __init__(self):
        self.robots_status = 404
        self.robots_body = ""
        self.hits: list[dict] = []
        self.big_bytes = 0
        self.host = ""
        self.port = 0

    @property
    def url(self) -> str:
        return f"http://{self.host}:{self.port}"

    def paths(self, include_robots: bool = False) -> list[str]:
        return [h["path"] for h in self.hits if include_robots or h["path"] != "/robots.txt"]

    def user_agents(self) -> set[str]:
        return {h["ua"] for h in self.hits}


def make_handler(site: FakeSite):
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *args):  # keep test output quiet
            pass

        def _send(self, status, body=b"", content_type="text/html; charset=utf-8", headers=None):
            self.send_response(status)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(body)))
            for k, v in (headers or {}).items():
                self.send_header(k, v)
            self.end_headers()
            self.wfile.write(body)

        def do_GET(self):  # noqa: N802
            path = self.path
            site.hits.append({"path": path.split("?")[0], "ua": self.headers.get("User-Agent", "")})
            clean = path.split("?")[0]

            if clean == "/robots.txt":
                return self._send(site.robots_status, site.robots_body.encode(), "text/plain")
            if clean == "/forbidden":
                return self._send(403, b"<h1>Forbidden</h1>")
            if clean == "/too-many":
                return self._send(429, b"slow down")
            if clean == "/gone":
                return self._send(404, b"<h1>Not found</h1>")
            if clean == "/captcha":
                return self._send(200, b'<html><body><div class="g-recaptcha" data-sitekey="x"></div>Verify you are human</body></html>')
            if clean == "/subscribe":
                return self._send(200, b"<html><body><h1>Subscribe to continue reading</h1></body></html>")
            if clean == "/needs-login":
                return self._send(302, b"", headers={"Location": "/login?next=/needs-login"})
            if clean == "/login":
                return self._send(200, b"<html><body><h1>Sign in</h1><form><input type=password></form></body></html>")
            if clean == "/to-another-site":
                return self._send(302, b"", headers={"Location": "http://other-site.invalid/jobs"})
            if clean == "/moved":
                return self._send(301, b"", headers={"Location": "/jobs.html"})
            if clean == "/loop":
                return self._send(302, b"", headers={"Location": "/loop"})
            if clean == "/big":
                return self._send(200, b"x" * site.big_bytes, "text/plain")
            if clean == "/empty-list":
                return self._send(200, b"<html><body><ul class='job-list'></ul></body></html>")

            file = (SITE / clean.lstrip("/")).resolve()
            if SITE.resolve() in file.parents and file.is_file():
                return self._send(200, file.read_bytes())

            return self._send(404, b"<h1>Not found</h1>")

    return Handler


@pytest.fixture()
def site():
    state = FakeSite()
    server = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(state))
    state.host, state.port = server.server_address
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    yield state
    server.shutdown()
    server.server_close()


UA = "ZamtrendsBot/0.1 (private research; contact: owner@example.com)"


@pytest.fixture()
def user_agent():
    return UA
