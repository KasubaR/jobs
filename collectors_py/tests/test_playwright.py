"""Playwright against the local site. Skipped when Chromium is not installed
(python -m playwright install chromium)."""

from contextlib import ExitStack
from pathlib import Path

import pytest

from zamtrends_collect.browser import BrowserFetcher
from zamtrends_collect.errors import Blocked, CollectError, RobotsDisallowed
from zamtrends_collect.fetcher import Fetcher
from zamtrends_collect.runner import Context, load_script

SCRIPTS = Path(__file__).resolve().parent.parent / "scripts"

pytestmark = pytest.mark.playwright


@pytest.fixture(scope="module")
def chromium_available():
    try:
        from playwright.sync_api import sync_playwright

        with sync_playwright() as p:
            p.chromium.launch(headless=True).close()
    except Exception as e:  # not installed, or the browser binary is missing
        pytest.skip(f"Chromium is not available: {e.__class__.__name__}")


@pytest.fixture()
def browser(chromium_available, user_agent):
    fetcher = Fetcher(user_agent, min_interval=0, timeout=4)
    with BrowserFetcher(fetcher) as b:
        yield b


def test_the_list_that_javascript_builds_is_read_after_it_appears(site, browser):
    page = browser.get(f"{site.url}/js-jobs.html", wait_for="ul.job-list li.job a")

    assert [t for _, t in page.links("li.job a")] == ["Budget Analyst at Example Charity", "Driver at Sample Mining"]


def test_plain_fetching_cannot_see_that_list(site, user_agent):
    page = Fetcher(user_agent, min_interval=0).get(f"{site.url}/js-jobs.html")

    assert page.links("li.job a") == []  # which is exactly why a browser is needed for such a page


def test_the_browser_identifies_itself_honestly(site, browser, user_agent):
    browser.get(f"{site.url}/js-jobs.html", wait_for="ul.job-list")

    assert site.user_agents() == {user_agent}
    assert not any("headless" in ua.lower() or "chrome" in ua.lower() for ua in site.user_agents())


def test_robots_txt_is_obeyed_before_the_browser_goes_anywhere(site, browser):
    site.robots_status = 200
    site.robots_body = "User-agent: *\nDisallow: /js-jobs"

    with pytest.raises(RobotsDisallowed):
        browser.get(f"{site.url}/js-jobs.html")

    assert site.paths() == []


def test_a_challenge_page_stops_the_browser_and_is_reported_as_blocked(site, browser):
    with pytest.raises(Blocked):
        browser.get(f"{site.url}/captcha")


def test_a_refusal_is_a_block_and_the_browser_does_not_retry(site, browser):
    with pytest.raises(Blocked, match="403"):
        browser.get(f"{site.url}/forbidden")

    assert site.paths().count("/forbidden") == 1


def test_a_redirect_to_a_login_page_is_a_block(site, browser):
    with pytest.raises(Blocked, match="login"):
        browser.get(f"{site.url}/needs-login")


def test_a_list_that_never_appears_is_a_layout_problem_not_a_success(site, browser):
    with pytest.raises(CollectError, match="layout may have changed"):
        browser.get(f"{site.url}/jobs.html", wait_for="ul.this-class-does-not-exist")


def test_when_the_expected_list_is_missing_because_of_a_wall_it_is_reported_as_a_wall(site, browser):
    with pytest.raises(Blocked):
        browser.get(f"{site.url}/captcha", wait_for="ul.job-list")


def test_the_playwright_example_collects_the_ads_with_their_facts(site, chromium_available, user_agent):
    fetcher = Fetcher(user_agent, min_interval=0, timeout=6)
    source = {"id": 1, "name": "JS board", "listing_url": f"{site.url}/js-jobs.html", "options": {}}

    with ExitStack() as stack:
        ctx = Context(source, {"user_agent": user_agent}, stack, lambda s, r: fetcher)
        ads = load_script(SCRIPTS, "example_playwright").collect(ctx)

    by_title = {a.title: a for a in ads}
    assert sorted(by_title) == ["Budget Analyst at Example Charity", "Driver at Sample Mining"]
    assert by_title["Driver at Sample Mining"].structured == {"locations": [{"locality": "Solwezi"}], "valid_through": "2026-12-05"}
    assert "Drive haul trucks" in by_title["Driver at Sample Mining"].content
