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
| `processing:dispatch [--limit=500] [--outdated] [--failed]` | Queue the processing job for ads still waiting (needs `PROCESSING_ENABLED=true`). `--outdated` also redoes jobs made with older reading rules; `--failed` retries ads whose processing failed |
| `jobs:standardize [--all]` | Standardize jobs and rebuild their matching keys (only jobs made with older rules, or all with `--all`) |
| `terms:install` | Add any missing starting words and places to the dictionary (never overwrites a correction you made) |
| `jobs:sweep-status` | Re-check every job's status: expire past end dates, close ads a full-list source dropped, reopen ones that came back (scheduled daily at 04:00) |
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

## Finding ads through a sitemap

For an HTML source (`html_listing`) whose site publishes a `sitemap.xml`, the ads can be found there instead of by paging through listing pages: one small request lists every ad address, newest first (by the sitemap's `lastmod` dates), and only **new** ads' own pages are opened. Set **Sitemap address** (and usually **Ad address pattern**) in the "Public HTML pages" settings.

- **Ad address pattern** keeps only ad pages and skips category, company and other pages. It matches from the start of the address path; `*` means any characters and `#` means one digit, nothing else. For example `/jobs/#*` matches `/jobs/794679266-credit-controller` but not `/jobs/full-time`. Without a pattern every same-site address in the sitemap is read.
- Needs **Open each new ad's own page** on, and the ad text from either the "Full ad text" selector or **Also read the job data the ad page publishes**. The ad's title comes from the "Title" selector (now a selector on the ad page), else its job data, else its first heading, else the page title. The ad selector, link and next-page settings are not used.
- A sitemap that is an index of other sitemaps is followed one level, newest file first, up to **Listing pages per run** files. Compressed (`.gz`) files and sitemaps on another site are never read. Only addresses on the source's own site are used.
- **Ad pages opened per run** is the budget for new ads. Newer ads come first, so when more are waiting than the budget allows, the rest are picked up by the next runs. An ad whose page could not be read is not stored (never under a made-up title), it is just listed as a problem of the run. **Ads per run** limits how many of the newest addresses are considered.
- robots.txt and the request spacing apply to the sitemap and to every ad page, as for every collector. A sitemap that cannot be read, or a pattern that matches nothing, is a failed run (the site probably changed), not a quiet success. "Test access" reads the sitemap (the first file of an index) only.

## Official JSON APIs

For a site that offers an official API, which is cleaner and steadier than reading its pages. On the source: **Collection method = Public API**, **Collector = api_json**, and the API address (the endpoint that lists the ads) as the **Job listing URL**. The "Public API" settings say where things are in the JSON, as dot paths:

| Setting | Example | Notes |
|---|---|---|
| Where the list of ads is | `data.jobs` | empty = the response itself is the list |
| Title field | `title` | required |
| Link field, or link template | `url`, or `https://example.com/jobs/{id}` | one is required; a template needs the ID field; links must be on the source's own site |
| ID, ad text | `id`, `description` | no text field = the title is stored |
| Employer, location, date posted, closing date, job type | `company`, `place`, ... | optional facts, kept apart from the text |
| Page parameter, pages per run | `page`, 1 to 5 | more than one page needs the parameter; reading stops at the first empty page |

**API key.** The key lives only in the server's `.env`, as a setting whose name starts with `COLLECTOR_KEY_`:

```
COLLECTOR_KEY_EXAMPLE_BOARD=the-key-from-the-provider
```

On the source, type just the **name** (`COLLECTOR_KEY_EXAMPLE_BOARD`) in "API key name". The key is never stored in the database, shown in the app, logged or put in a URL. It is sent in a header (`Authorization: Bearer <key>` by default, or another header such as `X-API-Key`), only over `https://`, and redirects are never followed so it cannot be passed to another host. Only settings starting with `COLLECTOR_KEY_` can be used as keys, so a source cannot be pointed at `APP_KEY` or the database password. If the key is missing the source cannot be switched on, and a refused key (HTTP 401/403) is reported as a failed run without printing it. Run `php artisan config:clear` after changing `.env` if the config is cached.

Everything else is as for every collector: approved and active only, robots.txt and the request spacing respected, a size cap, an honest User-Agent, emails removed, retention honoured, each new ad handed on for processing. A response with no list at the given path, or where no item has a title and link, is a **failed run** (the API probably changed), not a quiet success. "Test access" makes one request and stores nothing.

## Python worker (BeautifulSoup and Playwright)

For pages the built-in collectors cannot read: real parsing (BeautifulSoup) or a real browser for JavaScript-built pages (Playwright). It is a separate Python program in `collectors_py/` that runs on **your own computer**, never touches the database, and hands ads to the app with `collect:import`. Setup, the rules it follows (honest User-Agent, robots.txt, no retries, never gets round a CAPTCHA, login or block), how to add a script and how to schedule it with Windows Task Scheduler are in [collectors_py/README.md](collectors_py/README.md).

In the app a source uses **Collection method = Python worker** and **Collector = python_worker**, with a script name and optional JSON options. The app schedules and logs it like any source (frequency, back-off, switched off after 5 failures) but never fetches it itself: `python:due` tells the worker what is due and `python:fail` records a failure or a block.

## Handing ads on for processing

Every new or changed ad is stored as `pending` and raises a `RawJobPostStored` event. Set `PROCESSING_ENABLED=true` to switch the processing stage on: each ad then gets a background `ProcessRawJobPost` job, and `processing:dispatch` queues the ads that were waiting (run it once after switching on, to take in the ads already stored). Raw ad text is cleared 90 days after an ad is first seen whether or not it was processed; the dashboard warns when waiting ads are within 30 days of that.

### Running it day to day (Phase 6, step 7)

Each ad is one background job that does the whole chain: read the ad into a job, standardize it, build its matching keys, set its status, compare it with other jobs. Everything inside is one transaction, so a job never shows up half made; if any step fails the ad is marked `failed` with the reason and nothing is stored for it. (The `processing` status is therefore never seen: a job goes straight from being read to its real status.)

| Situation | What to do |
|---|---|
| First switch-on, or ads stored while it was off | Set `PROCESSING_ENABLED=true`, then `php artisan processing:dispatch` once. The dashboard warns while ads wait and processing is off |
| An ad failed (the dashboard says so) | Fix the cause if there is one, then `php artisan processing:dispatch --failed` |
| The reading rules were improved (`ExtractionProcessor::VERSION` raised) | `php artisan processing:dispatch --outdated`. It redoes jobs in place (no new jobs, your decisions kept) and only where the ad text is still stored, because re-reading an ad whose text was cleared would lose what was read from it. For those, `php artisan jobs:standardize --all` redoes the standard values |
| The standardizing rules or dictionary changed | `php artisan jobs:standardize --all` |

Dates and sources move jobs on without any new ad: `jobs:sweep-status` runs daily at 04:00. The dashboard shows jobs by status, and what waits for a person: jobs to review, possible duplicates, and values to map.

### Reading an ad into a job (Phase 6, step 1)

`App\Processing\ExtractionProcessor` turns each stored ad into one `jobs` record (title, employer, location, dates, job type, pay, qualification, experience, application link), with a confidence score and a row in `extraction_logs`. For every field the evidence is, strongest first:

1. **Published job data** the ad carried (JobPosting block, API fields, import `structured` column).
2. **Text rules**: the source's own rules from its "Extraction rules" settings, then the built-in label rules (`Closing date:`, `Location:`, `Employer:`, `Job type:`, `Salary:`, `Qualification:`, `Experience:`, `Apply online:`, "apply by 5 December 2026", "3 years experience").
3. **The title** (split into title and employer only when the source sets a title separator, for example ` at `).

A field with no evidence stays empty. A date without a year ("by 30 November") is not read and no year is assumed. Each value remembers its evidence in `jobs.field_sources`. Ads scoring under 50 are flagged `needs_review`. The raw ad is never changed, an edited ad updates its job instead of adding one, and a job a person marked reviewed stays reviewed. Employer, place, qualification and experience stay as the ad wrote them; standardizing them is the next step. An ad with no title and no text at all is marked failed with the reason.

### Job status (Phase 6, step 2)

Every job has one status: **new, processing, published, expired, closed, rejected, duplicate**. An ad still being on a website is never the reason to call it open. The rules, first that applies wins:

1. **duplicate** is left alone (duplicate detection owns it, a later step). **Rejected by a person** is left alone.
2. **rejected** automatically only when the title starts like a notice, not a vacancy ("Tender for…", "Invitation to bid", "Request for proposals", "Expression of interest", "Public notice"). "Tender Clerk" is still a job.
3. **expired** when the closing date has passed (the closing day itself is still open). With **no closing date** the job gets an *assumed end*: `ASSUMED_AD_AGE_DAYS` (default 45) after it was posted, or first seen if no posted date is known. It is shown as assumed, and a closing date the ad later gives replaces it.
4. **closed** only for a source marked **"One run lists every ad that is currently open"**, and only when the ad was missing from 2 successful runs in a row. Failed, partial and empty runs prove nothing. Leave the setting off for feeds that show only the latest ads (JobWeb) or runs limited to the first pages. An ad that appears again reopens.
5. **new** when too little could be read (it waits for a person), otherwise **published**.

A person's decision (published, rejected) is kept, except that dates and the source can still expire or close it. Every change is written to `job_status_changes` with its reason and who made it. `jobs:sweep-status` runs daily so dates move jobs on without a new ad. `processing` is reserved for the standardizing and comparing steps.

After deploying, run `php artisan migrate --force`, then `php artisan jobs:sweep-status` once to set the status of jobs that already exist.

### Standardized values (Phase 6, step 3)

Next to what the ad wrote, each job gets a standard form. **The original is never changed or replaced**: `employer_text`, `location_text`, `qualification_text`, `experience_text` and `employment_type` stay exactly as published.

| The ad says | Standard form (columns) |
|---|---|
| Bachelor of Science, BSc Degree, B.Sc, Bachelor's | Bachelor's Degree (`qualification_level`, `_rank`, `_label`) |
| Bachelor's in Accounting | Bachelor's Degree in Accounting (`qualification_field` = Accounting) |
| Lusaka City, Lusaka, Zambia | Lusaka (`location_id`, a town in the Lusaka province) |
| 3 years' experience, three (3) years, 36 months | 3 Years (`experience_min_months` = 36, `experience_label`) |
| 2-3 years, minimum 5 years, 5+ years | 2–3 Years, 5+ Years |
| FULL_TIME, CONTRACT | `["full_time","contract"]` (`employment_types`) |
| Zamtel (Z) Ltd, ZAMTEL | one employer record (`employer_id`) |

The words and places live in the database (`term_mappings`, `locations`), seeded with a starting set. Nothing is guessed: text the rules do not know, or that names two things ("Diploma or Degree", "5 years in mining and 2 in management"), is left unstandardized and listed under **Values to map** in the menu. There one decision (a level and field, years of experience, a job type or a place, or *Not applicable*) fixes every job waiting on that text, is remembered for later ads, and never touches the original. A correction you make is never overwritten by `terms:install`.

### Matching keys (Phase 6, step 4)

For duplicate detection each job also gets keys, again beside the originals: `match_title` (filler such as "URGENT", "Vacancy", reference numbers, position counts and " at Employer" removed), `match_employer` (legal endings removed), `match_location`, `match_url` (the application link without tracking parts), `match_date` (posted date, else first seen), and a text fingerprint (`content_fingerprint`, a 64-bit hash of the ad text without boilerplate, contact details and dates, plus `content_hash_norm` for exact copies). The fingerprint is kept after the raw text is cleared at 90 days. Two ads for the same vacancy on different sites get the same keys; `MatchingKeys::distance()` says how close two fingerprints are (0 identical, around 32 unrelated).

After deploying, run `php artisan migrate --force` (installs the dictionary and places), then `php artisan jobs:standardize` once for jobs that already exist.

### Duplicate detection (Phase 6, step 5)

The same vacancy is often on the employer's site and on several boards. After a job is read, standardized and given its status, it is compared with other jobs. A wrong merge hides a real vacancy, so the rules lean towards leaving things apart.

1. **Candidates only.** Just jobs that share the employer, the title, the application link or the exact text, and were posted within 60 days. Nothing is compared with everything.
2. **Certain matches.** The same specific application link, or the same ad text from the same employer, each only when the titles also agree (a generic "careers" link or a shared template proves nothing).
3. **A score of 0 to 100** from employer (20), title (25), place (15), closing date (10), posting date (5), application link (5) and text (20). The weights and the lines are in `config/job_intel.php` (`duplicates`). From **90** the job is marked **duplicate** automatically; from **70** to 89 the pair waits on **Possible duplicates** for you; below 70 they are different vacancies.
4. **Guards** (each recorded in the pair's breakdown): different places (unless the text is the same), posting dates more than 60 days apart, closing dates more than 30 days apart, an agency named as employer (the ad may not be theirs), and two ads from the same source never merge automatically. An ad with no text to compare scores at most 80, so unless it shares a specific application link it can be proposed but never merged.
5. **The main ad** is the employer's own site first, then government, NGO, education, job boards, aggregators and media, then the oldest. The other ads become `duplicate` and point at the main one (`duplicate_of_job_id`, never a chain) and keep their own source, link and dates. If a better main ad arrives later, the group moves to it.
6. **Editing and people.** When an ad changes and no longer matches, its link is undone and the stored pair dropped. A pair you reject is never proposed or linked again; a pair you confirm is linked again whenever it comes up. A link made by the system stays only while the pair would still qualify for it.

`DUPLICATE_AUTO_SCORE` and `DUPLICATE_REVIEW_SCORE` in `.env` change the two lines. Start conservative and loosen them once you have seen real pairs.

### The screens (Phase 6, step 6)

- **Jobs**: every job with status, place, closing date and source; filter by status, source, search, or "needs review". A job page shows what the ad said beside the standard value and where each came from, its copies, its status history with reasons, and its reading log. From there you can **Publish**, **Reject** (with a reason, kept in the history), **Mark as reviewed** (publishes a thin ad you have checked), or say a copy **is a different vacancy**. A copy cannot be decided until it is separated.
- **Possible duplicates**: each waiting pair side by side with the score breakdown and notes, a suggested main ad, and **Same vacancy** / **Different vacancies**. The latest automatic merges are listed below for checking.
- **Values to map**: see step 3.

Run `php artisan migrate --force` after deploying. Jobs already marked before this step are compared the next time their ad is processed.

## Lost phone

If you turned two-factor on and lose your phone: use a recovery code at the code prompt. If those are lost too: `php artisan admin:reset-two-factor` (turns it off), then sign in with your password.

## Tests

`php artisan test`

No test reaches a real website: `Tests\TestCase` forbids any request a test has not faked, and `NoRealNetworkTest` fails if that guard is ever removed. Tests use saved files in `tests/Fixtures` and faked HTTP.

`Phase6EndToEndTest` is the acceptance test for the clean dataset. A synthetic Mining Engineer vacancy comes in through the real collectors (an RSS feed and a JSON and a CSV import), on the company's own site, on a board (same text, employer written differently) and, reworded, on a second board, together with look-alikes that must stay separate, a tender notice, an expired ad and an ad too thin to trust. It checks the whole result (one main job, standard values beside untouched originals, the right status for each, a person settling the open questions), that a second intake changes nothing, that processing never alters a stored ad, and that the dataset keeps what it learned after the raw text is cleared. `ChecksDataset` holds the rules the finished dataset must always obey (every job has one of the seven statuses with a reason and a history that ends where it is; copies point at one main job with no chains; standard values agree with each other).

### A source that keeps no text

Some sources are set not to keep ad text (`retain_raw` off, for example where the site's terms forbid it). For those no text fingerprint is made, and nothing text-derived is stored. Their ads can still be matched on employer, title, place and dates and are proposed for review, but they cannot reach the automatic line (an ad with no text scores at most 80) unless they share a specific application link. That is deliberate: merging on text would mean keeping the text.
