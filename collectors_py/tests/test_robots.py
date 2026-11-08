from zamtrends_collect.robots import RobotsGuard, parse, token_of

from conftest import UA

TOKEN = "zamtrendsbot"


def allowed(body: str, path: str) -> bool:
    from zamtrends_collect.robots import _allowed

    return _allowed(parse(body, TOKEN)["rules"], path)


def test_the_token_comes_from_the_user_agent():
    assert token_of(UA) == "zamtrendsbot"


def test_a_go_zambia_style_rule_blocks_rss_but_not_jobs_and_gives_the_crawl_delay():
    policy = parse("User-agent: *\nDisallow: /rss/\nCrawl-delay: 1\nSitemap: https://x.test/sitemap.xml", TOKEN)

    assert policy["delay"] == 1.0
    assert not allowed("User-agent: *\nDisallow: /rss/", "/rss/jobs")
    assert allowed("User-agent: *\nDisallow: /rss/", "/jobs")


def test_our_own_group_overrides_the_wildcard_group():
    body = "User-agent: *\nDisallow: /\n\nUser-agent: ZamtrendsBot\nAllow: /\nDisallow: /secret"

    assert allowed(body, "/jobs")
    assert not allowed(body, "/secret/page")


def test_disallow_everything_blocks_us():
    assert not allowed("User-agent: *\nDisallow: /", "/anything")


def test_longest_match_wins_and_allow_wins_a_tie():
    body = "User-agent: *\nDisallow: /jobs\nAllow: /jobs/public\nDisallow: /same\nAllow: /same"

    assert not allowed(body, "/jobs/secret")
    assert allowed(body, "/jobs/public/1")
    assert allowed(body, "/same")


def test_wildcards_and_end_anchors():
    body = "User-agent: *\nDisallow: /*.pdf$\nDisallow: /search?*sort="

    assert not allowed(body, "/files/a.pdf")
    assert allowed(body, "/files/a.pdf.html")
    assert not allowed(body, "/search?q=1&sort=new")


def test_empty_disallow_allows_everything_and_comments_are_ignored():
    assert allowed("User-agent: *\nDisallow:   # nothing", "/anything")


def test_consecutive_user_agent_lines_share_one_group():
    body = "User-agent: other\nUser-agent: ZamtrendsBot\nDisallow: /private"

    assert not allowed(body, "/private/x")


def test_missing_robots_txt_allows_everything(site, user_agent):
    site.robots_status = 404
    assert RobotsGuard(user_agent).allows(f"{site.url}/jobs.html")


def test_unreachable_robots_txt_blocks_everything(site, user_agent):
    site.robots_status = 503
    assert not RobotsGuard(user_agent).allows(f"{site.url}/jobs.html")


def test_a_dead_host_blocks_everything(user_agent):
    # nothing listens on port 9: connection refused counts as unreachable
    assert not RobotsGuard(user_agent, timeout=2).allows("http://127.0.0.1:9/jobs")


def test_robots_txt_is_read_once_per_origin_and_from_the_same_port(site, user_agent):
    site.robots_status = 200
    site.robots_body = "User-agent: *\nDisallow: /private"
    guard = RobotsGuard(user_agent)

    assert guard.allows(f"{site.url}/jobs.html")
    assert not guard.allows(f"{site.url}/private/x")
    assert site.paths(include_robots=True).count("/robots.txt") == 1


def test_it_sends_the_honest_user_agent_when_reading_robots(site, user_agent):
    RobotsGuard(user_agent).allows(f"{site.url}/jobs.html")

    assert site.user_agents() == {user_agent}


def test_crawl_delay_is_exposed(site, user_agent):
    site.robots_status = 200
    site.robots_body = "User-agent: *\nCrawl-delay: 4"

    assert RobotsGuard(user_agent).crawl_delay(f"{site.url}/jobs.html") == 4.0
