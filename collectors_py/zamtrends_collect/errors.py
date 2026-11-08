class CollectError(Exception):
    """Something went wrong while collecting (bad status, unreadable page, expected element missing)."""


class Blocked(CollectError):
    """The site blocked or challenged the worker (CAPTCHA, login, paywall, rate limit, bot check).

    The worker stops, reports it, and does NOT try to get round it.
    """


class RobotsDisallowed(Blocked):
    """robots.txt does not allow this URL (or robots.txt could not be read), so it was not fetched."""
