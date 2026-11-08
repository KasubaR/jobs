"""Runs every due Python source: ask the app, run the source's script, hand the ads over.

One source failing never stops the others. A script that crashes, or a site that blocks us, is
reported to the app (python:fail), which logs it and makes the source back off. Nothing is retried here.
"""

from __future__ import annotations

import importlib.util
import re
import tempfile
import traceback
from contextlib import ExitStack
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable

from .ads import Ad, write_json
from .app import AppClient, AppError
from .browser import BrowserFetcher
from .errors import Blocked, CollectError
from .fetcher import Fetcher

SCRIPT_NAME = re.compile(r"^[a-z][a-z0-9_]{0,59}$")  # same rule the app applies when you save the source


class Context:
    """What a script receives. It can read pages (plain or browser) and nothing else."""

    def __init__(self, source: dict[str, Any], rules: dict[str, Any], stack: ExitStack,
                 fetcher_factory: Callable[[dict[str, Any], dict[str, Any]], Fetcher]):
        self.source = source
        self.options: dict[str, Any] = source.get("options") or {}
        self._rules = rules
        self._stack = stack
        self._fetcher = fetcher_factory(source, rules)
        self._browser: BrowserFetcher | None = None
        self.messages: list[str] = []

    def fetch(self, url: str):
        """A page by plain HTTP (BeautifulSoup territory)."""
        return self._fetcher.get(url)

    @property
    def browser(self) -> BrowserFetcher:
        """A real browser, started on first use (Playwright territory)."""
        if self._browser is None:
            self._browser = self._stack.enter_context(BrowserFetcher(self._fetcher))
        return self._browser

    def log(self, message: str) -> None:
        self.messages.append(message)


@dataclass
class Outcome:
    source_id: int
    name: str
    status: str                     # imported | failed | blocked | skipped
    detail: str = ""
    ads_found: int = 0
    summary: dict[str, Any] = field(default_factory=dict)


def default_fetcher_factory(source: dict[str, Any], rules: dict[str, Any]) -> Fetcher:
    return Fetcher(
        user_agent=rules["user_agent"],
        min_interval=max(1, int(source.get("min_interval_seconds") or 1)),
        max_bytes=int(rules.get("max_response_bytes") or 5 * 1024 * 1024),
        timeout=float(rules.get("http_timeout") or 20),
    )


def load_script(scripts_dir: Path, name: str):
    """Import scripts/<name>.py. The name is checked first, so it can never point outside scripts/."""
    if not SCRIPT_NAME.match(name or ""):
        raise CollectError(f"'{name}' is not a valid script name (lowercase letters, digits, underscores).")

    path = Path(scripts_dir) / f"{name}.py"
    if not path.is_file():
        raise CollectError(f"The script '{name}' was not found in {scripts_dir}.")

    spec = importlib.util.spec_from_file_location(f"zamtrends_scripts.{name}", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[union-attr]

    if not callable(getattr(module, "collect", None)):
        raise CollectError(f"The script '{name}' has no collect(ctx) function.")

    return module


def run_source(source: dict[str, Any], rules: dict[str, Any], app: AppClient, scripts_dir: Path,
               fetcher_factory=default_fetcher_factory, dry_run: bool = False) -> Outcome:
    sid, name = int(source["id"]), str(source.get("name", source["id"]))

    def report(message: str, blocked: bool) -> Outcome:
        if not dry_run:
            app.fail(sid, message, blocked=blocked)
        return Outcome(sid, name, "blocked" if blocked else "failed", message)

    try:
        module = load_script(scripts_dir, source.get("script") or "")

        with ExitStack() as stack:
            ads = module.collect(Context(source, rules, stack, fetcher_factory))

        ads = [a for a in (ads or []) if isinstance(a, Ad)]
    except Blocked as e:
        return report(str(e), blocked=True)
    except CollectError as e:
        return report(str(e), blocked=False)
    except Exception as e:  # a bug in a script must not stop the other sources
        last = traceback.format_exc().strip().splitlines()[-1]
        return report(f"the script crashed: {e.__class__.__name__}: {e} ({last[:200]})", blocked=False)

    if dry_run:
        return Outcome(sid, name, "skipped", "dry run: nothing was handed to the app", ads_found=len(ads))

    with tempfile.TemporaryDirectory(prefix="zamtrends-") as tmp:
        path = Path(tmp) / "ads.json"
        write_json(ads, path)
        summary = app.import_file(path, sid)

    # The app records the run itself (also a failed run when nothing usable was found).
    ok = summary.get("status") in ("success", "partial")
    return Outcome(
        sid, name, "imported" if ok else "failed",
        detail=("; ".join(e.get("message", "") for e in summary.get("errors", [])[:3]) or summary.get("message", "")),
        ads_found=len(ads), summary=summary,
    )


def run_due(app: AppClient, scripts_dir: Path, only: int | None = None, dry_run: bool = False,
            fetcher_factory=default_fetcher_factory) -> list[Outcome]:
    due = app.due()
    rules = {k: due[k] for k in ("user_agent", "max_response_bytes", "http_timeout") if k in due}

    outcomes: list[Outcome] = []
    for source in due["sources"]:
        if only is not None and int(source["id"]) != only:
            continue
        try:
            outcomes.append(run_source(source, rules, app, Path(scripts_dir), fetcher_factory, dry_run))
        except AppError as e:
            outcomes.append(Outcome(int(source["id"]), str(source.get("name", "")), "failed", f"could not talk to the app: {e}"))

    return outcomes
