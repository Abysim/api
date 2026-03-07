# Project: API (Laravel)

## Overview
Pet project that supports other projects. Laravel 10 API application.

## Tech Stack
- **Framework**: Laravel 10.48.28
- **Language**: PHP
- **PHP version (web)**: 8.2 (ea-php82 via cPanel `.htaccess` handler)
- **PHP version (CLI)**: 8.3 (differs from web — always test against 8.2)

## Deployment
- **Production server**: SSH host `bigcats` (connect via `ssh bigcats`)
- **Server path**: `~/api`
- **Deployment method**: Automatic on file save from IDE (SFTP/sync)
- **New files** created outside the IDE (e.g. by Claude) do NOT auto-sync — manually `scp` them to bigcats
- **No local or staging environment** — bigcats is the only runtime environment

## Database
- **Engine**: MySQL
- **Connection config**: `~/api/.env` on bigcats (contains `DB_*` variables)
- **Access via CLI**: `ssh bigcats "cd ~/api && php artisan tinker"` or read credentials from `.env` and use `mysql` client
- **Never expose credentials** in CLAUDE.md, commits, or logs — always read them from `.env` at runtime

## CLI Aliases (local terminal only)
- Use `p` instead of `php` (e.g. `p artisan migrate`, `p artisan tinker`)
- Use `c` instead of `composer` (e.g. `c require package/name`, `c dump-autoload`)
- **These aliases are only available in the local shell.** When running commands via `ssh bigcats "..."`, use `php` (not `p`)

## Running commands on bigcats via SSH
- **PHP**: `ssh bigcats "cd ~/api && php artisan <command>"`
- **Composer**: `ssh bigcats "cd ~/api && php /opt/cpanel/ea-wappspector/composer.phar <command>"`
- `composer` is not in PATH on bigcats — always use the full `php /opt/cpanel/ea-wappspector/composer.phar` path

## Testing
- **Framework**: PHPUnit 10, Mockery
- **Run unit tests locally**: `p vendor/bin/phpunit --testsuite=Unit --no-coverage`
- **Run unit tests on bigcats**: `ssh bigcats "cd ~/api && php vendor/bin/phpunit --testsuite=Unit --no-coverage"`
- **Run live API integration tests** (hits real Google News RSS + GDELT API, no DB needed):
  ```
  SKIP_LIVE_API_TESTS=false p vendor/bin/phpunit tests/Feature/Services/News/LiveApiTest.php --no-coverage
  ```
- Live API tests are **skipped by default** — set `SKIP_LIVE_API_TESTS=false` env var to enable
- Live tests verify external APIs still return data in the expected format (article structure, date format, required keys)
- GDELT tests gracefully skip if the API returns no results (transient availability)
- Unit tests do NOT require DB — they mock HTTP via `Http::fake()` and dependencies via Mockery
- `phpunit.xml` sets `TELEGRAM_API_TOKEN=""` to prevent TelegramServiceProvider from connecting to DB during tests

## Gotchas
- **PHP-only stack** — no Python/Node dependencies; everything must run in pure PHP on shared hosting
- **Verify vendor packages on bigcats** after deploy — `composer install --no-dev` may skip packages

## Logs
- **Laravel log**: `~/api/storage/logs/laravel.log` on bigcats
- **PHP error log**: `~/api/error_log` on bigcats
- View recent logs: `ssh bigcats "tail -100 ~/api/storage/logs/laravel.log"`
