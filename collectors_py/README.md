# Zamtrends Python worker

Collects ads from sources that the app's own collectors cannot read:

- **BeautifulSoup** (`requests` + `lxml`): plain HTML pages that need real parsing, such as "the text next to the label *Closing date*".
- **Playwright** (headless Chromium): pages that only show their ads after JavaScript runs.

It runs **on your own computer**, next to the app. It never touches the database. The app decides what may be collected, stores the ads, logs the run and schedules the next one; the worker only talks to it through three commands:

| The worker runs | Why |
|---|---|
| `php artisan python:due --json` | Which sources are due, and the rules to follow (User-Agent, limits) |
| `php artisan collect:import FILE --source=ID --trigger=python --json` | Hand over the ads it found |
| `php artisan python:fail ID "reason" [--blocked]` | Say it failed or was blocked, so the source backs off |

## Rules it always follows

- An **honest User-Agent** with your contact address, given by the app (`ZamtrendsBot/0.1 (private research; contact: ...)`). No disguise, and the browser uses the same one.
- **robots.txt** is read first and obeyed (same rules as the app: `*` and `$` wildcards, longest match wins, our own group wins). If it cannot be read, nothing is fetched.
- A **minimum time between requests** (the source's setting, or the site's `Crawl-delay` if larger), a **cap on response size**, and **no retries**.
- It **never gets round a block.** A CAPTCHA, login, paywall, rate limit (429), refusal (401/403) or bot check stops that source: it is reported as *blocked* and the source backs off. No stealth plugins, no fingerprint changes, no proxies, no solving challenges.
- Redirects to another site are not followed. Only approved, active sources are ever offered to the worker, and the app checks again when the ads arrive.

## Set up (once)

You need Python 3.11+ and PHP on your PATH (XAMPP's PHP works).

```
cd collectors_py
python -m venv .venv
.venv\Scripts\python.exe -m pip install -r requirements.txt
.venv\Scripts\python.exe -m playwright install chromium     # only if a script uses the browser (about 150 MB)
```

## Run

```
.venv\Scripts\python.exe run_due.py              # every due Python source
.venv\Scripts\python.exe run_due.py --only 7     # just source 7, if it is due
.venv\Scripts\python.exe run_due.py --dry-run    # collect and show, hand nothing over, record nothing
```

Settings (environment variables, optional): `ZAMTRENDS_APP` (the Laravel folder, default: the folder above this one) and `ZAMTRENDS_PHP` (the PHP program, default `php`; set the full path if `php` is not on PATH).

Exit code 0 means it ran (look at the output, and the app's **Collection runs** page, for individual sources); 2 means it could not run at all.

### Every few hours with Windows Task Scheduler

Create a basic task that runs every 6 hours (or hourly: only due sources do anything):

- Program: `C:\...\job-intel\collectors_py\.venv\Scripts\python.exe`
- Arguments: `run_due.py`
- Start in: `C:\...\job-intel\collectors_py`

The app's `schedule:work` / cron does **not** start this: the app never fetches Python sources itself.

## Add a source

1. Copy `scripts/example_bs4.py` (or `example_playwright.py`) to `scripts/your_site.py` and edit the selectors for that one website. A script is one function, `collect(ctx)`, that returns a list of `Ad`. It can read pages with `ctx.fetch(url)` (plain HTTP) or `ctx.browser.get(url, wait_for="css selector")` (real browser), and nothing else.
2. In the app, add the source: **Collection method = Python worker**, **Collector = python_worker**, a listing URL, a frequency (6 hours suits a busy job board), and in "Python worker": **Script** = `your_site` (the file name without `.py`), optional **Options** (JSON passed to the script as `ctx.options`).
3. Test it without recording anything: approve and activate the source, then `run_due.py --dry-run --only ID`.
4. Only an **approved, active** source is collected. Approval is your decision: read the site's terms and robots.txt first (the app's **Test access** button does not apply to Python sources).

Keep facts (employer, place, dates, pay) in `Ad.structured`; never contact details. Emails in the ad text are removed by the app anyway.

## A script that finds nothing

An empty list is reported as a possible layout change (the app logs a failed run and the source backs off), not as a quiet success. The examples raise `CollectError` for that.

## Tests

```
.venv\Scripts\python.exe -m pytest                 # everything
.venv\Scripts\python.exe -m pytest -m "not playwright and not e2e"
```

Status when this was written: the BeautifulSoup path, the runner, the robots and block rules and the end-to-end tests with the real app all pass. The 9 browser tests are **skipped until Playwright and Chromium are installed** (the downloads could not complete on the connection available at the time), so the Playwright path has been written but not yet run. After installing, run `.venv\Scripts\python.exe -m pytest -m playwright`.

They run against a small local website (`tests/site`, served on 127.0.0.1) and never contact a real site. `-m playwright` needs Chromium installed; `-m e2e` runs the real PHP app against a throwaway SQLite database.
