# Zamtrends

Private Zambian job market intelligence platform. Single user, password sign-in with optional authenticator-app two-factor (off by default), not indexed by search engines.

Plain Laravel + Blade (no admin framework). The look follows `Design/Afghan Product – Admin Panel.html`.

- Collects job ads only from sources marked `approved` and `active` in the source register.
- Raw ad text is kept 90 days, then cleared; structured data is kept.

## Setup

1. Copy `.env.example` to `.env`, then set `APP_KEY` (`php artisan key:generate`), the database, `ADMIN_EMAIL` and `COLLECTOR_CONTACT`.
2. `php artisan migrate --seed`
3. `php artisan admin:create` (asks for a password; only `ADMIN_EMAIL` can ever sign in)
4. Sign in with your password. Two-factor is **off by default**; turn it on from the Security page (scan a QR code, get one-time recovery codes). Once on, the code is required at every sign-in. Recommended before the app is on a public address.
5. Cron on the server: `* * * * * php artisan schedule:run`. That one line is enough: every 15 minutes the scheduler queues a background job for each source that is due, and every minute it starts a queue worker (`queue:work --stop-when-empty --max-time=50`) that runs the waiting jobs and then stops. Keep `QUEUE_CONNECTION=database` and `CACHE_STORE=database`.
6. In production set `APP_ENV=production` and `APP_DEBUG=false`.

## Running it on your own computer

Nothing collects in the background unless something runs the scheduler. While you work, keep this running in a terminal:

```
php artisan schedule:work
```

"Run now" on a source page queues the collection, and it starts within a minute while the scheduler is running. To collect straight away from the terminal instead, without the queue: `php artisan collect:run "Source name"`.

## Collection commands

| Command | What it does |
|---|---|
| `collect:dispatch` | Queue a job for every due source (the scheduler runs this) |
| `collect:run [source]` | Collect now, in this process, from due sources or the named one |
| `queue:work --stop-when-empty` | Run the queued jobs, then stop (the scheduler starts this every minute) |
| `collect:import FILE --source=ID\|NAME [--json]` | Import a CSV or JSON file of ads into an approved, active manual source |
| `python:due [--json]` | List the approved, active Python-worker sources that are due (the worker asks this) |
| `python:fail ID "reason" [--blocked]` | Record a failed or blocked Python-worker run so the source backs off |
| `processing:dispatch [--limit=500]` | Queue the processing job for ads still waiting (needs `PROCESSING_ENABLED=true`) |
| `runs:cleanup` | Mark runs that never finished as failed (scheduled every 10 minutes) |
| `raw:purge` | Clear raw ad text older than 90 days (scheduled daily) |

A job that is stopped for taking longer than 15 minutes needs the PHP `pcntl` extension (normal on Linux hosts, not on Windows). Without it, the cleanup above still marks the run failed after 30 minutes.

## Importing ads from a file

For sources that cannot be collected automatically (PDFs, newspapers, partnerships), or for any script outside the app. On a manual source that is approved and active, use **Import file** on its page (a sample CSV can be downloaded there), or the command:

```
php artisan collect:import ads.csv --source="Teaching Service Commission"
php artisan collect:import ads.json --json     # JSON file names its own source; prints a JSON summary
```

- **CSV:** first row names the columns: `title` and `content` (required), `url`, `external_id`, `structured` (optional JSON facts). Comma, semicolon or tab separated; UTF-8 or Excel's Windows-1252; ad text may span lines inside quotes.
- **JSON:** a list of ads, or `{"source": 5, "ads": [ ... ]}` with the same fields (`structured` is an object).
- At most 500 ads and 5 MB per file. One file is one run. A bad row is reported with its row number and the rest are stored; the run fails only if nothing could be stored. Importing the same file again changes nothing; an edited ad becomes a new version.
- Same rules as every other collector: approved and active only, emails removed, `retain_raw` honoured, each new ad handed on for processing. Nothing is fetched from the web.
- Exit code 0 means something was stored (also for a partial import); 1 means nothing was, or the file/source was unusable. With `--json` the output is one line of JSON: `run_id`, `source`, `status`, `discovered`, `new`, `changed`, `unchanged`, `failed`, `errors`.

## Python worker (BeautifulSoup and Playwright)

For pages the built-in collectors cannot read: real parsing (BeautifulSoup) or a real browser for JavaScript-built pages (Playwright). It is a separate Python program in `collectors_py/` that runs on **your own computer**, never touches the database, and hands ads to the app with `collect:import`. Setup, the rules it follows (honest User-Agent, robots.txt, no retries, never gets round a CAPTCHA, login or block), how to add a script and how to schedule it with Windows Task Scheduler are in [collectors_py/README.md](collectors_py/README.md).

In the app a source uses **Collection method = Python worker** and **Collector = python_worker**, with a script name and optional JSON options. The app schedules and logs it like any source (frequency, back-off, switched off after 5 failures) but never fetches it itself: `python:due` tells the worker what is due and `python:fail` records a failure or a block.

## Handing ads on for processing

Every new or changed ad is stored as `pending` and raises a `RawJobPostStored` event. The cleaning, extraction and classification stage plugs in by implementing `App\Processing\RawPostProcessor` and binding it in `AppServiceProvider` in place of the placeholder (which leaves ads pending). Set `PROCESSING_ENABLED=true` once it exists: each ad then gets a background `ProcessRawJobPost` job, and `processing:dispatch` queues the ads that were waiting. Raw ad text is cleared 90 days after an ad is first seen whether or not it was processed; the dashboard warns when waiting ads are within 30 days of that.

## Lost phone

If you turned two-factor on and lose your phone: use a recovery code at the code prompt. If those are lost too: `php artisan admin:reset-two-factor` (turns it off), then sign in with your password.

## Tests

`php artisan test`
