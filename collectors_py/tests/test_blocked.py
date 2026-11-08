import pytest

from zamtrends_collect import blocked
from zamtrends_collect.errors import Blocked, CollectError


@pytest.mark.parametrize("status", [401, 403, 407, 429, 451])
def test_refusing_statuses_are_blocks(status):
    with pytest.raises(Blocked):
        blocked.check(status, "https://x.test/jobs", "https://x.test/jobs", "")


@pytest.mark.parametrize("status", [404, 410, 500, 502, 503])
def test_other_error_statuses_are_errors_not_blocks(status):
    with pytest.raises(CollectError) as caught:
        blocked.check(status, "https://x.test/jobs", "https://x.test/jobs", "<html>plain</html>")

    assert not isinstance(caught.value, Blocked)


@pytest.mark.parametrize("html", [
    '<div class="g-recaptcha" data-sitekey="k"></div>',
    "<title>Attention Required! | Cloudflare</title>",
    "Checking your browser before accessing example.com",
    "Please verify you are human to continue",
    '<script src="https://js.hcaptcha.com/1/api.js"></script> hcaptcha.com/1/api',
    "Pardon Our Interruption",
    "Subscribe to continue reading this article",
    "Unusual traffic from your computer network",
])
def test_challenge_and_paywall_pages_are_recognised_even_with_a_200(html):
    with pytest.raises(Blocked):
        blocked.check(200, "https://x.test/jobs", "https://x.test/jobs", f"<html><body>{html}</body></html>")


def test_an_ordinary_page_that_merely_mentions_captcha_is_not_a_wall():
    page = "<html><body><p>Our contact form uses a captcha to stop spam. We discuss bots in our terms.</p></body></html>"

    blocked.check(200, "https://x.test/contact", "https://x.test/contact", page)  # must not raise


def test_a_wall_marker_buried_deep_in_a_long_page_is_ignored():
    page = "<html><body>" + ("<p>vacancy</p>" * 5000) + "g-recaptcha</body></html>"

    blocked.check(200, "https://x.test/jobs", "https://x.test/jobs", page)  # beyond the first 30 KB


def test_being_sent_to_a_login_page_is_a_block():
    with pytest.raises(Blocked, match="login"):
        blocked.check(200, "https://x.test/jobs", "https://x.test/login?next=/jobs", "<h1>Sign in</h1>")


def test_a_login_page_you_asked_for_yourself_is_not_a_redirect_block():
    blocked.check(200, "https://x.test/login", "https://x.test/login", "<h1>Sign in</h1>")


@pytest.mark.parametrize("url", ["https://x.test/login", "https://x.test/sign-in", "https://x.test/signin/", "https://x.test/account/login",
                                 "https://x.test/users/sign_in", "https://x.test/auth?x=1"])
def test_login_urls(url):
    assert blocked.looks_like_login(url)


@pytest.mark.parametrize("url", ["https://x.test/jobs", "https://x.test/jobs/loginess", "https://x.test/author/jane", "https://x.test/blog-in-zambia"])
def test_ordinary_urls_are_not_login_urls(url):
    assert not blocked.looks_like_login(url)
