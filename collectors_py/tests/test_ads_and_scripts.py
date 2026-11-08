import json
from contextlib import ExitStack
from pathlib import Path

import pytest

from zamtrends_collect import Ad
from zamtrends_collect.ads import write_json
from zamtrends_collect.errors import CollectError
from zamtrends_collect.fetcher import Fetcher
from zamtrends_collect.helpers import labelled_values, parse_date
from zamtrends_collect.runner import Context, load_script

SCRIPTS = Path(__file__).resolve().parent.parent / "scripts"


# ---- ads ----

def test_an_ad_is_cleaned_and_capped_before_it_is_sent():
    ad = Ad(title="  Budget   Analyst \n at X ", content="  text  ", url=" https://x.test/a ", external_id=" 7 ").cleaned()

    assert (ad.title, ad.content, ad.url, ad.external_id) == ("Budget Analyst at X", "text", "https://x.test/a", "7")
    assert len(Ad(title="t" * 900, content="c" * 150_000).cleaned().title) == 500
    assert len(Ad(title="t", content="c" * 150_000).cleaned().content) == 100_000


def test_only_filled_fields_are_written(tmp_path):
    path = tmp_path / "ads.json"
    write_json([Ad(title="A", content="a"), Ad(title="B", content="b", url="https://x.test/b", structured={"employer": "X"})], path)

    data = json.loads(path.read_text(encoding="utf-8"))

    assert data[0] == {"title": "A", "content": "a"}
    assert data[1] == {"title": "B", "content": "b", "url": "https://x.test/b", "structured": {"employer": "X"}}


def test_accents_survive_the_file(tmp_path):
    path = tmp_path / "ads.json"
    write_json([Ad(title="Café Manager", content="Zambézi")], path)

    assert "Café" in path.read_text(encoding="utf-8")


# ---- helpers ----

def test_labelled_values_reads_the_text_next_to_a_label():
    from bs4 import BeautifulSoup

    soup = BeautifulSoup("<dl><dt>Closing date:</dt><dd> 30 November 2026 </dd><dt>Location</dt><dd>Lusaka</dd></dl>", "lxml")

    assert labelled_values(soup) == {"closing date": "30 November 2026", "location": "Lusaka"}


@pytest.mark.parametrize("text, expected", [
    ("30 November 2026", "2026-11-30"), ("5 Dec 2026", "2026-12-05"), ("2026-11-30", "2026-11-30"),
    ("30/11/2026", "2026-11-30"), ("November 30, 2026", "2026-11-30"),
    ("sometime soon", None), ("31/02/2026", None), ("", None), (None, None),
])
def test_dates_are_read_or_left_out_never_guessed(text, expected):
    assert parse_date(text) == expected


# ---- the BeautifulSoup example, against the local site ----

def context(site, user_agent, options=None, listing="/jobs.html"):
    source = {"id": 1, "name": "Local", "listing_url": f"{site.url}{listing}", "options": options or {}}
    fetcher = Fetcher(user_agent, min_interval=0)
    return Context(source, {"user_agent": user_agent}, ExitStack(), lambda s, r: fetcher)


def test_the_bs4_example_collects_the_ads_with_their_facts(site, user_agent):
    ads = load_script(SCRIPTS, "example_bs4").collect(context(site, user_agent))

    by_title = {a.title: a for a in ads}
    assert sorted(by_title) == ["Budget Analyst at Example Charity", "Driver at Sample Mining", "Nurse at Clinic"]

    budget = by_title["Budget Analyst at Example Charity"]
    assert budget.url == f"{site.url}/jobs/1.html"
    assert budget.structured == {"locations": [{"locality": "Lusaka"}], "valid_through": "2026-11-30", "employment_type": ["FULL_TIME"]}
    assert "Prepare budgets" in budget.content

    # an unreadable date is left out, not guessed
    assert "valid_through" not in by_title["Nurse at Clinic"].structured
    # the off-site link was never followed
    assert not any("elsewhere" in p for p in site.paths())


def test_the_example_does_not_put_contact_details_in_the_facts(site, user_agent):
    ads = load_script(SCRIPTS, "example_bs4").collect(context(site, user_agent))

    facts = json.dumps([a.structured for a in ads])
    assert "hr@example.org" not in facts and "+260" not in facts


def test_options_limit_how_many_ads_are_read(site, user_agent):
    ads = load_script(SCRIPTS, "example_bs4").collect(context(site, user_agent, {"max_ads": 1}))

    assert len(ads) == 1
    assert site.paths().count("/jobs/1.html") == 1 and "/jobs/2.html" not in site.paths()


def test_an_empty_list_is_reported_as_a_possible_layout_change_not_as_success(site, user_agent):
    with pytest.raises(CollectError, match="layout may have changed"):
        load_script(SCRIPTS, "example_bs4").collect(context(site, user_agent, listing="/empty-list"))


# ---- loading scripts safely ----

@pytest.mark.parametrize("name", ["../secret", "Upper", "1abc", "a b", "x.py", "a/b", "", "a" * 61, "..\\evil"])
def test_script_names_that_could_reach_outside_the_folder_are_refused(name):
    with pytest.raises(CollectError, match="not a valid script name"):
        load_script(SCRIPTS, name)


def test_a_missing_script_and_a_script_without_collect_are_reported(tmp_path):
    with pytest.raises(CollectError, match="not found"):
        load_script(tmp_path, "nothing_here")

    (tmp_path / "no_collect.py").write_text("x = 1\n")
    with pytest.raises(CollectError, match="no collect"):
        load_script(tmp_path, "no_collect")
