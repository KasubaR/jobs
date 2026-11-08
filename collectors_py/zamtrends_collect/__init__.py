"""Zamtrends Python worker: collects ads from sources that need real parsing (BeautifulSoup) or a
real browser (Playwright), and hands them to the app. It never touches the database; the app decides
what is allowed, stores the ads, logs the run and schedules the next one.

Rules it follows, always:
  * an honest User-Agent with a contact address (given by the app)
  * robots.txt is read first and obeyed
  * a minimum time between requests, a cap on response size, no retries
  * a CAPTCHA, login, paywall, rate limit or bot check stops the source: it is reported as blocked
    and never worked around
"""

__version__ = "0.1.0"

from .errors import Blocked, CollectError, RobotsDisallowed  # noqa: E402,F401
from .ads import Ad  # noqa: E402,F401
