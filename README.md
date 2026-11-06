# jobs

Private Zambian job market intelligence platform. Single user, password + authenticator-app code, not indexed by search engines.

Plain Laravel + Blade (no admin framework). The look follows `Design/Afghan Product – Admin Panel.html`.

- Collects job ads only from sources marked `approved` and `active` in the source register.
- Raw ad text is kept 90 days, then cleared; structured data is kept.

## Setup

1. Copy `.env.example` to `.env`, then set `APP_KEY` (`php artisan key:generate`), the database, `ADMIN_EMAIL` and `COLLECTOR_CONTACT`.
2. `php artisan migrate --seed`
3. `php artisan admin:create` (asks for a password; only `ADMIN_EMAIL` can ever sign in)
4. Sign in. You will be asked to scan a QR code with an authenticator app and shown one-time recovery codes.
5. Cron on the server: `* * * * * php artisan schedule:run`
6. In production set `APP_ENV=production` and `APP_DEBUG=false`.

## Lost phone

Use a recovery code at the code prompt. If those are lost too: `php artisan admin:reset-two-factor`, then sign in with your password and set it up again.

## Tests

`php artisan test`
