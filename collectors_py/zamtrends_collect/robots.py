"""robots.txt, obeyed the same way the app's PHP RobotsGuard does (RFC 9309):

  2xx          -> parse and obey the rules
  4xx          -> there is no robots.txt: everything allowed
  5xx / error  -> unreachable: everything disallowed (tried again after 15 minutes)

Our own token's group wins over "*"; the longest matching pattern wins; on a tie Allow wins;
"*" and a trailing "$" work in patterns. Python's urllib.robotparser is not used because it
ignores those wildcards and so could allow a page the site forbids.
"""

from __future__ import annotations

import re
import time
from urllib.parse import urlsplit

import requests

UNREACHABLE_RETRY_SECONDS = 15 * 60
CACHE_SECONDS = 24 * 3600


def token_of(user_agent: str) -> str:
    """'ZamtrendsBot/0.1 (...)' -> 'zamtrendsbot'"""
    return user_agent.split("/")[0].split(" ")[0].strip().lower()


def parse(body: str, token: str) -> dict:
    groups: list[dict] = []
    current = None
    last_was_agent = False

    for line in re.split(r"\r\n|\r|\n", body):
        line = re.sub(r"#.*", "", line).strip()
        if not line or ":" not in line:
            continue
        name, value = (part.strip() for part in line.split(":", 1))
        name = name.lower()

        if name == "user-agent":
            if not last_was_agent:
                groups.append({"agents": [], "rules": [], "delay": None})
                current = len(groups) - 1
            groups[current]["agents"].append(value.lower())
            last_was_agent = True
            continue

        last_was_agent = False
        if current is None:
            continue

        if name in ("allow", "disallow"):
            if value != "":  # an empty Disallow allows everything
                groups[current]["rules"].append((name == "allow", value))
        elif name == "crawl-delay":
            try:
                groups[current]["delay"] = float(value)
            except ValueError:
                pass

    matching = [g for g in groups if token in g["agents"]] or [g for g in groups if "*" in g["agents"]]
    rules = [r for g in matching for r in g["rules"]]
    delay = None
    for g in matching:
        if g["delay"] is not None:
            delay = g["delay"]

    return {"mode": "rules" if rules else "allow_all", "rules": rules, "delay": delay}


def _pattern_matches(pattern: str, path: str) -> bool:
    anchored = pattern.endswith("$")
    if anchored:
        pattern = pattern[:-1]
    regex = "^" + re.escape(pattern).replace(r"\*", ".*") + ("$" if anchored else "")
    return re.match(regex, path) is not None


def _allowed(rules: list[tuple[bool, str]], path: str) -> bool:
    best = None  # (allow, length)
    for allow, pattern in rules:
        if not _pattern_matches(pattern, path):
            continue
        length = len(pattern)
        if best is None or length > best[1] or (length == best[1] and allow):
            best = (allow, length)
    return True if best is None else best[0]


class RobotsGuard:
    def __init__(self, user_agent: str, session: requests.Session | None = None, timeout: float = 20, clock=time.monotonic):
        self.user_agent = user_agent
        self.token = token_of(user_agent)
        self.session = session or requests.Session()
        self.timeout = timeout
        self._clock = clock
        self._cache: dict[str, tuple[float, dict]] = {}

    @staticmethod
    def origin(url: str) -> str:
        parts = urlsplit(url)
        return f"{parts.scheme}://{parts.netloc}".lower()  # the port belongs to the origin

    def allows(self, url: str) -> bool:
        policy = self._policy(url)
        if policy["mode"] == "allow_all":
            return True
        if policy["mode"] == "disallow_all":
            return False
        parts = urlsplit(url)
        path = (parts.path or "/") + (f"?{parts.query}" if parts.query else "")
        return _allowed(policy["rules"], path)

    def crawl_delay(self, url: str) -> float | None:
        return self._policy(url)["delay"]

    def _policy(self, url: str) -> dict:
        origin = self.origin(url)
        cached = self._cache.get(origin)
        if cached and cached[0] > self._clock():
            return cached[1]

        try:
            response = self.session.get(
                f"{origin}/robots.txt", headers={"User-Agent": self.user_agent}, timeout=self.timeout, allow_redirects=True
            )
            status = response.status_code
        except requests.RequestException:
            return self._remember(origin, {"mode": "disallow_all", "rules": [], "delay": None}, UNREACHABLE_RETRY_SECONDS)

        if status >= 500:
            return self._remember(origin, {"mode": "disallow_all", "rules": [], "delay": None}, UNREACHABLE_RETRY_SECONDS)
        if status >= 400:
            return self._remember(origin, {"mode": "allow_all", "rules": [], "delay": None}, CACHE_SECONDS)

        return self._remember(origin, parse(response.text, self.token), CACHE_SECONDS)

    def _remember(self, origin: str, policy: dict, seconds: float) -> dict:
        self._cache[origin] = (self._clock() + seconds, policy)
        return policy
