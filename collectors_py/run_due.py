"""Collect every due Python source and hand the ads to the app.

    python run_due.py                 # all due sources
    python run_due.py --only 7        # just source 7, if it is due
    python run_due.py --dry-run       # collect and show, hand nothing over, record nothing

Start it from Windows Task Scheduler (see README.md). Exit codes: 0 = it ran (individual sources may
still have failed: see the output and the app's run list), 2 = it could not run at all (no app, no PHP).
"""

from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

from zamtrends_collect.app import AppClient, AppError  # noqa: E402
from zamtrends_collect.runner import run_due  # noqa: E402


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Collect due Python-worker sources for Zamtrends.")
    parser.add_argument("--app-dir", default=os.environ.get("ZAMTRENDS_APP", str(HERE.parent)), help="the Laravel app folder (default: the folder above this one)")
    parser.add_argument("--php", default=os.environ.get("ZAMTRENDS_PHP", "php"), help="PHP program (default: php)")
    parser.add_argument("--only", type=int, help="run only this source id")
    parser.add_argument("--dry-run", action="store_true", help="collect and show the result, but hand nothing over")
    args = parser.parse_args(argv)

    app = AppClient(Path(args.app_dir), php=args.php)

    try:
        outcomes = run_due(app, HERE / "scripts", only=args.only, dry_run=args.dry_run)
    except AppError as e:
        print(f"Cannot run: {e}", file=sys.stderr)
        return 2

    if not outcomes:
        print("No approved, active Python sources are due.")
        return 0

    for o in outcomes:
        extra = f" ({o.ads_found} ads)" if o.ads_found else ""
        print(f"[{o.status.upper():8}] {o.name}{extra}" + (f": {o.detail}" if o.detail else ""))

    return 0


if __name__ == "__main__":
    sys.exit(main())
