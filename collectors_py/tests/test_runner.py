"""The runner with a pretend app: what gets handed over, what gets reported, and what never happens."""

import json
from pathlib import Path

import pytest

from zamtrends_collect.app import AppError
from zamtrends_collect.fetcher import Fetcher
from zamtrends_collect.runner import run_due, run_source

from conftest import UA


class FakeApp:
    def __init__(self, sources, import_status="success", import_error=None):
        self.sources = sources
        self.import_status = import_status
        self.import_error = import_error
        self.imported = []   # (source_id, ads, file_existed_when_called)
        self.failed = []     # (source_id, message, blocked)

    def due(self):
        return {"user_agent": UA, "max_response_bytes": 1_000_000, "http_timeout": 5, "sources": self.sources}

    def import_file(self, path, source_id):
        if self.import_error:
            raise self.import_error
        ads = json.loads(Path(path).read_text(encoding="utf-8"))
        self.imported.append((source_id, ads, Path(path)))
        return {"status": self.import_status, "discovered": len(ads), "errors": []}

    def fail(self, source_id, message, blocked=False):
        self.failed.append((source_id, message, blocked))
        return "recorded"


def source(id=1, script="ok", name=None, **extra):
    return {"id": id, "name": name or f"Source {id}", "script": script, "listing_url": "http://127.0.0.1:9/jobs", "min_interval_seconds": 1, "options": {}, **extra}


@pytest.fixture()
def scripts(tmp_path):
    folder = tmp_path / "scripts"
    folder.mkdir()
    files = {
        "ok": "from zamtrends_collect import Ad\ndef collect(ctx):\n    return [Ad(title='A', content='a', url='https://x.test/a'), Ad(title='B', content='b')]\n",
        "blocked": "from zamtrends_collect.errors import Blocked\ndef collect(ctx):\n    raise Blocked('CAPTCHA on /jobs')\n",
        "failing": "from zamtrends_collect.errors import CollectError\ndef collect(ctx):\n    raise CollectError('No ads matched the list selector.')\n",
        "crash": "def collect(ctx):\n    return {}['missing']\n",
        "empty": "def collect(ctx):\n    return []\n",
        "junk": "from zamtrends_collect import Ad\ndef collect(ctx):\n    return [Ad(title='Real', content='r'), 'not an ad', None, 42]\n",
    }
    for name, code in files.items():
        (folder / f"{name}.py").write_text(code, encoding="utf-8")
    return folder


def test_a_good_script_hands_its_ads_to_the_app_and_nothing_is_reported_as_failed(scripts):
    app = FakeApp([source(7, "ok")])

    outcomes = run_due(app, scripts)

    assert [(o.status, o.ads_found) for o in outcomes] == [("imported", 2)]
    assert app.failed == []
    source_id, ads, _ = app.imported[0]
    assert source_id == 7
    assert ads == [{"title": "A", "content": "a", "url": "https://x.test/a"}, {"title": "B", "content": "b"}]


def test_the_temporary_file_is_removed_after_the_handover(scripts):
    app = FakeApp([source(1, "ok")])

    run_due(app, scripts)

    assert not app.imported[0][2].exists()


def test_a_block_is_reported_as_blocked_and_nothing_is_handed_over(scripts):
    app = FakeApp([source(2, "blocked")])

    outcomes = run_due(app, scripts)

    assert outcomes[0].status == "blocked"
    assert app.failed == [(2, "CAPTCHA on /jobs", True)]
    assert app.imported == []


def test_an_ordinary_failure_is_reported_but_not_as_blocked(scripts):
    app = FakeApp([source(3, "failing")])

    run_due(app, scripts)

    assert app.failed == [(3, "No ads matched the list selector.", False)]


def test_a_crashing_script_is_reported_and_does_not_stop_the_other_sources(scripts):
    app = FakeApp([source(1, "crash"), source(2, "ok"), source(3, "blocked")])

    outcomes = run_due(app, scripts)

    assert [o.status for o in outcomes] == ["failed", "imported", "blocked"]
    assert "the script crashed: KeyError" in app.failed[0][1]
    assert [i[0] for i in app.imported] == [2]


@pytest.mark.parametrize("name", ["../ok", "..\\ok", "OK", "", None, "nope"])
def test_bad_or_missing_script_names_are_reported_and_nothing_runs(scripts, name):
    app = FakeApp([source(1, name)])

    outcomes = run_due(app, scripts)

    assert outcomes[0].status == "failed"
    assert len(app.failed) == 1 and app.failed[0][2] is False
    assert app.imported == []


def test_an_empty_result_is_still_handed_over_so_the_app_can_log_it(scripts):
    app = FakeApp([source(1, "empty")], import_status="failed")

    outcomes = run_due(app, scripts)

    assert app.imported[0][1] == []
    assert outcomes[0].status == "failed"
    assert app.failed == []  # the app already recorded that run; the worker does not add a second one


def test_things_that_are_not_ads_are_ignored(scripts):
    app = FakeApp([source(1, "junk")])

    run_due(app, scripts)

    assert [a["title"] for a in app.imported[0][1]] == ["Real"]


def test_a_partial_import_counts_as_imported(scripts):
    app = FakeApp([source(1, "ok")], import_status="partial")

    assert run_due(app, scripts)[0].status == "imported"


def test_dry_run_collects_but_hands_over_and_reports_nothing(scripts):
    app = FakeApp([source(1, "ok"), source(2, "blocked")])

    outcomes = run_due(app, scripts, dry_run=True)

    assert [(o.status, o.ads_found) for o in outcomes] == [("skipped", 2), ("blocked", 0)]
    assert app.imported == [] and app.failed == []


def test_only_runs_one_source(scripts):
    app = FakeApp([source(1, "ok"), source(2, "ok")])

    outcomes = run_due(app, scripts, only=2)

    assert [o.source_id for o in outcomes] == [2]


def test_nothing_due_is_fine(scripts):
    assert run_due(FakeApp([]), scripts) == []


def test_a_broken_connection_to_the_app_is_reported_for_that_source_only(scripts):
    app = FakeApp([source(1, "ok"), source(2, "ok")], import_error=AppError("artisan took too long"))

    outcomes = run_due(app, scripts)

    assert [o.status for o in outcomes] == ["failed", "failed"]
    assert "could not talk to the app" in outcomes[0].detail


def test_the_app_decides_the_rules_the_fetcher_uses(scripts, site):
    captured = {}

    def factory(src, rules):
        captured.update(min_interval=src["min_interval_seconds"], user_agent=rules["user_agent"], max_bytes=rules["max_response_bytes"])
        return Fetcher(rules["user_agent"], min_interval=0)

    (scripts / "reads.py").write_text("def collect(ctx):\n    ctx.fetch(ctx.source['listing_url'])\n    return []\n", encoding="utf-8")
    src = source(5, "reads", listing_url=f"{site.url}/jobs.html", min_interval_seconds=3)
    app = FakeApp([src])

    run_source(src, app.due(), app, scripts, fetcher_factory=factory)

    assert captured == {"min_interval": 3, "user_agent": UA, "max_bytes": 1_000_000}
    assert site.user_agents() == {UA}
