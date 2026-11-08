import time

import pytest

from zamtrends_collect.errors import Blocked, CollectError, RobotsDisallowed
from zamtrends_collect.fetcher import Fetcher, Throttle


def fetcher(user_agent, **kw):
    kw.setdefault("min_interval", 0)
    return Fetcher(user_agent, **kw)


def test_a_normal_page_is_fetched_with_the_honest_user_agent(site, user_agent):
    page = fetcher(user_agent).get(f"{site.url}/jobs.html")

    assert page.status == 200
    assert "Budget Analyst" in page.text
    assert site.user_agents() == {user_agent}
    assert "python-requests" not in " ".join(site.user_agents()).lower()


def test_links_stay_on_the_site_and_repeats_and_fragments_are_dropped(site, user_agent):
    page = fetcher(user_agent).get(f"{site.url}/jobs.html")

    links = [url for url, _ in page.links("li.job a")]

    assert links == [f"{site.url}/jobs/1.html", f"{site.url}/jobs/2.html", f"{site.url}/jobs/3.html"]


def test_a_page_robots_txt_forbids_is_never_requested(site, user_agent):
    site.robots_status = 200
    site.robots_body = "User-agent: *\nDisallow: /jobs"

    with pytest.raises(RobotsDisallowed):
        fetcher(user_agent).get(f"{site.url}/jobs.html")

    assert site.paths() == []  # only robots.txt was read


def test_an_unreadable_robots_txt_stops_everything(site, user_agent):
    site.robots_status = 500

    with pytest.raises(RobotsDisallowed):
        fetcher(user_agent).get(f"{site.url}/jobs.html")

    assert site.paths() == []


def test_a_refusal_is_a_block_and_is_not_retried(site, user_agent):
    f = fetcher(user_agent)

    with pytest.raises(Blocked, match="403"):
        f.get(f"{site.url}/forbidden")

    assert site.paths().count("/forbidden") == 1 and f.requests_made == 1


def test_too_many_requests_is_a_block_and_is_not_retried(site, user_agent):
    with pytest.raises(Blocked, match="429"):
        fetcher(user_agent).get(f"{site.url}/too-many")

    assert site.paths().count("/too-many") == 1


def test_a_captcha_page_is_a_block_even_with_status_200(site, user_agent):
    with pytest.raises(Blocked, match="challenge"):
        fetcher(user_agent).get(f"{site.url}/captcha")


def test_a_paywall_is_a_block(site, user_agent):
    with pytest.raises(Blocked):
        fetcher(user_agent).get(f"{site.url}/subscribe")


def test_a_redirect_to_a_login_page_is_a_block_and_the_login_is_not_followed_further(site, user_agent):
    with pytest.raises(Blocked, match="login"):
        fetcher(user_agent).get(f"{site.url}/needs-login")

    assert "/login" not in site.paths()


def test_a_redirect_to_another_site_is_a_block_and_not_followed(site, user_agent):
    with pytest.raises(Blocked, match="another site"):
        fetcher(user_agent).get(f"{site.url}/to-another-site")


def test_a_redirect_within_the_site_is_followed(site, user_agent):
    page = fetcher(user_agent).get(f"{site.url}/moved")

    assert page.url == f"{site.url}/jobs.html" and "Budget Analyst" in page.text


def test_a_redirect_loop_gives_up(site, user_agent):
    with pytest.raises(CollectError, match="too many redirects"):
        fetcher(user_agent).get(f"{site.url}/loop")


def test_a_missing_page_is_an_error_not_a_block(site, user_agent):
    with pytest.raises(CollectError, match="404") as caught:
        fetcher(user_agent).get(f"{site.url}/gone")

    assert not isinstance(caught.value, Blocked)


def test_an_oversized_response_is_refused_without_reading_it_all(site, user_agent):
    site.big_bytes = 300_000

    with pytest.raises(CollectError, match="larger than"):
        fetcher(user_agent, max_bytes=100_000).get(f"{site.url}/big")


def test_an_unreachable_site_is_an_error(user_agent):
    # robots.txt cannot be read, so it is refused before any page request
    with pytest.raises(RobotsDisallowed):
        fetcher(user_agent, timeout=2).get("http://127.0.0.1:9/jobs")


def test_requests_to_one_host_are_spaced_out(site, user_agent):
    f = fetcher(user_agent, min_interval=0.4)

    started = time.monotonic()
    f.get(f"{site.url}/jobs/1.html")
    f.get(f"{site.url}/jobs/2.html")
    f.get(f"{site.url}/jobs/3.html")

    assert time.monotonic() - started >= 0.75  # two gaps of 0.4 s


def test_the_crawl_delay_in_robots_txt_raises_the_gap(site, user_agent):
    site.robots_status = 200
    site.robots_body = "User-agent: *\nCrawl-delay: 0.5"
    f = fetcher(user_agent, min_interval=0.05)

    started = time.monotonic()
    f.get(f"{site.url}/jobs/1.html")
    f.get(f"{site.url}/jobs/2.html")

    assert time.monotonic() - started >= 0.45


def test_throttle_waits_only_the_remaining_time():
    slept = []
    now = [100.0]
    throttle = Throttle(2.0, clock=lambda: now[0], sleep=slept.append)

    throttle.wait("https://a.test/x")        # first request: no wait
    now[0] = 100.5
    throttle.wait("https://a.test/y")        # 0.5 s have passed: wait 1.5 s
    throttle.wait("https://b.test/z")        # another host: no wait

    assert slept == [pytest.approx(1.5)]
