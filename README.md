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
5. Cron on the server: `* * * * * php artisan schedule:run`
6. In production set `APP_ENV=production` and `APP_DEBUG=false`.

## Lost phone

If you turned two-factor on and lose your phone: use a recovery code at the code prompt. If those are lost too: `php artisan admin:reset-two-factor` (turns it off), then sign in with your password.

## Tests

`php artisan test`
