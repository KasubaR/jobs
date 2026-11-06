# jobs

Private Zambian job market intelligence platform. Single-user, login + 2FA, not indexed.

- Collects job ads only from sources marked `approved` and `active` in the source register.
- Raw ad text is kept 90 days, then cleared; structured data is kept.
- Setup: copy `.env.example` to `.env`, set `ADMIN_EMAIL` and `COLLECTOR_CONTACT`, run `php artisan migrate --seed`, then `php artisan make:filament-user`.
- Scheduler: run `php artisan schedule:run` every minute (cron).
