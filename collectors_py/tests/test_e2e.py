"""The whole path with the REAL app: this worker -> php artisan -> a throwaway SQLite database.

Only the website is fake (the local fixture site). Skipped when PHP or the app's vendor folder is missing.
"""

import json
import shutil
import subprocess
from pathlib import Path

import pytest

from zamtrends_collect.app import AppClient
from zamtrends_collect.fetcher import Fetcher
from zamtrends_collect.runner import run_due

pytestmark = pytest.mark.e2e

APP = Path(__file__).resolve().parents[2]
SCRIPTS = Path(__file__).resolve().parent.parent / "scripts"
PHP = shutil.which("php")

if not PHP or not (APP / "vendor" / "autoload.php").is_file():
    pytest.skip("PHP or the app's vendor folder is not available", allow_module_level=True)


@pytest.fixture()
def app(tmp_path, site):
    db = tmp_path / "e2e.sqlite"
    db.touch()
    env = {
        "DB_CONNECTION": "sqlite", "DB_DATABASE": str(db), "COLLECTOR_CONTACT": "owner@example.com", "ADMIN_EMAIL": "owner@example.com",
        "APP_ENV": "local", "CACHE_STORE": "file", "SESSION_DRIVER": "file", "QUEUE_CONNECTION": "sync", "PROCESSING_ENABLED": "false",
    }
    client = AppClient(APP, php=PHP, env=env, timeout=120)

    def artisan(*args):
        return subprocess.run([PHP, "artisan", *args], cwd=APP, capture_output=True, text=True, encoding="utf-8",
                              env={**__import__("os").environ, **env}, timeout=120)

    assert artisan("migrate", "--force", "--no-interaction").returncode == 0

    php = (
        "$s = App\\Models\\Source::create(['name'=>'JS Board','website_url'=>'%(url)s/','job_listing_url'=>'%(url)s/jobs.html',"
        "'source_type'=>'job_board','collection_method'=>'python','collector'=>'python_worker','collection_frequency'=>'every_6_hours',"
        "'access_status'=>'approved','is_active'=>false,'min_interval_seconds'=>1,"
        "'collector_config'=>['python'=>['script'=>'example_bs4','options'=>['max_ads'=>10]]]]);"
        "$s->forceFill(['is_active'=>true])->save(); echo $s->id;"
    ) % {"url": site.url}
    created = artisan("tinker", "--execute=" + php)
    assert created.returncode == 0, created.stdout + created.stderr

    client.artisan = artisan
    client.source_id = int(created.stdout.strip().splitlines()[-1])
    return client


def query(app, php):
    out = app.artisan("tinker", "--execute=" + php)
    assert out.returncode == 0, out.stdout + out.stderr
    return json.loads(out.stdout.strip().splitlines()[-1])


def factory(src, rules):
    return Fetcher(rules["user_agent"], min_interval=0)


def test_the_real_app_hands_the_source_to_the_worker_and_stores_what_comes_back(app, site, user_agent):
    # 1. the app says the source is due, with the rules to follow
    due = app.due()
    assert [s["id"] for s in due["sources"]] == [app.source_id]
    assert due["user_agent"].startswith("ZamtrendsBot/") and "owner@example.com" in due["user_agent"]
    assert due["sources"][0]["script"] == "example_bs4" and due["sources"][0]["options"] == {"max_ads": 10}

    # 2. the worker collects from the (local) site and hands the ads over
    outcomes = run_due(app, SCRIPTS, fetcher_factory=factory)

    assert [(o.status, o.ads_found) for o in outcomes] == [("imported", 3)]
    assert site.user_agents() == {due["user_agent"]}  # the app's own User-Agent reached the site

    # 3. the app stored them through its own rules and logged the run
    stored = query(app, "echo json_encode([App\\Models\\RawJobPost::count(), App\\Models\\RawJobPost::whereNotNull('structured_data')->count(),"
                        "str_contains(App\\Models\\RawJobPost::where('raw_title','like','Budget%')->value('raw_content'), 'hr@example.org'),"
                        "App\\Models\\SourceRun::first()->only(['trigger','status','items_new','items_failed']),"
                        "App\\Models\\RawJobPost::where('processing_status','pending')->count()]);")
    assert stored == [3, 3, False, {"trigger": "python", "status": "success", "items_new": 3, "items_failed": 0}, 3]

    # 4. the app rescheduled it: nothing is due until six hours have passed
    assert app.due()["sources"] == []
    nxt = query(app, "$s = App\\Models\\Source::first(); echo json_encode([$s->consecutive_failures, $s->next_collection_at->diffInHours(now()->subSeconds(5), true) >= 5]);")
    assert nxt == [0, True]


def test_running_again_finds_nothing_new(app, site):
    run_due(app, SCRIPTS, fetcher_factory=factory)
    query(app, "App\\Models\\Source::query()->update(['next_collection_at'=>null]); echo json_encode(1);")

    outcomes = run_due(app, SCRIPTS, fetcher_factory=factory)

    assert outcomes[0].status == "imported"
    counts = query(app, "echo json_encode([App\\Models\\RawJobPost::count(), App\\Models\\SourceRun::latest('id')->first()->only(['items_new','items_unchanged'])]);")
    assert counts == [3, {"items_new": 0, "items_unchanged": 3}]


def test_a_blocked_site_is_reported_to_the_app_which_backs_the_source_off(app, site):
    query(app, f"App\\Models\\Source::query()->update(['job_listing_url'=>'{site.url}/captcha']); echo json_encode(1);")

    outcomes = run_due(app, SCRIPTS, fetcher_factory=factory)

    assert outcomes[0].status == "blocked"
    state = query(app, "$s = App\\Models\\Source::first(); $r = App\\Models\\SourceRun::first();"
                       "echo json_encode([$s->consecutive_failures, $s->is_active, $r->status, $r->trigger, $r->error_message]);")
    assert state[:4] == [1, True, "failed", "python"]
    assert "did not try to get round it" in state[4] and "challenge" in state[4]
    assert query(app, "echo json_encode(App\\Models\\RawJobPost::count());") == 0
    assert app.due()["sources"] == []  # backing off: not due again straight away


def test_five_blocks_in_a_row_switch_the_source_off(app, site):
    query(app, f"App\\Models\\Source::query()->update(['job_listing_url'=>'{site.url}/forbidden']); echo json_encode(1);")

    for _ in range(5):
        query(app, "App\\Models\\Source::query()->update(['next_collection_at'=>null]); echo json_encode(1);")
        run_due(app, SCRIPTS, fetcher_factory=factory)

    assert query(app, "echo json_encode(App\\Models\\Source::first()->is_active);") is False
    assert app.due()["sources"] == []


def test_robots_txt_that_forbids_the_page_is_a_block_and_the_page_is_never_requested(app, site):
    site.robots_status = 200
    site.robots_body = "User-agent: *\nDisallow: /jobs"

    outcomes = run_due(app, SCRIPTS, fetcher_factory=factory)

    assert outcomes[0].status == "blocked"
    assert "robots.txt" in outcomes[0].detail
    assert site.paths() == []


def test_a_source_that_is_switched_off_is_not_offered_to_the_worker(app):
    query(app, "App\\Models\\Source::query()->update(['is_active'=>false]); echo json_encode(1);")

    assert app.due()["sources"] == []
