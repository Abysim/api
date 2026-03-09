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

## News Search Architecture
- **Species query routing** (NewsController lines ~358-414): species with `exclude` terms in `resources/json/news/species/{lang}.json` get **separate queries** (one per species with their exclusions). Species with empty `exclude` arrays get **batched into one combined query** (grouped by query length limit).
- **Species config**: `resources/json/news/species/en.json` — each species has `words` (positive search terms), `exclude` (title-filter exclusion terms), `excludeCase` (case-sensitive excludes for proper nouns)
- **Query pipeline**: `GeneratesSearchQuery::generateSearchQuery()` builds `(word1 OR word2) !exclude1 !exclude2`, then `FreeNewsService::getNews()` converts `!` to `-` before passing to Google News / GDELT APIs
- **Adding exclusion terms to a species** moves it from batch to separate query — this is intentional and correct

## External API Quirks (News Sources)
- **GDELT API** has aggressive rate limits (HTTP 429 or plain-text "limit requests" on 200). When testing, minimize live calls, add delays between requests, and if you get 0 results — check logs for 429 before concluding the code is broken. Suggest waiting 60s and retrying rather than assuming the query is wrong.
- **GDELT rate-limit strategy**: Never run more than 2-3 GDELT queries in a single test session. If you need to test query syntax, test with Google News first (more tolerant), then confirm with one GDELT call. If GDELT returns 0 results after previous calls in the same session, assume rate limiting — do NOT retry repeatedly.
- **Google News RSS** does NOT support wildcards (`*`) in positive search terms inside `(... OR ...)` groups — the query silently returns 0 or stale results. Exclusion wildcards (`-horoscop*`) are harmless but don't actually do prefix matching; the PHP-side title filter handles real wildcard exclusion.
- When debugging news search issues, **always check `storage/logs/laravel.log`** for rate-limit warnings and raw article counts before drawing conclusions about code correctness.

## Running artisan tinker locally (no DB)
- Local MySQL is not running — use env overrides to bypass DB: `TELEGRAM_API_TOKEN="" DB_CONNECTION=sqlite DB_DATABASE=":memory:" p artisan tinker`

## Logs
- **Laravel log**: `~/api/storage/logs/laravel.log` on bigcats
- **PHP error log**: `~/api/error_log` on bigcats
- View recent logs: `ssh bigcats "tail -100 ~/api/storage/logs/laravel.log"`
