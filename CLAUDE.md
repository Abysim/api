# Project: API (Laravel)

## Overview
Pet project that supports other projects. Laravel 10 API application.
- **Target language**: Ukrainian (`uk`) — all foreign-language news articles are translated INTO Ukrainian. Articles already in Ukrainian skip translation. This is core domain logic, not a bug.

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
- **Modified files** edited by Claude (not via IDE save) also may not auto-sync — verify with `md5sum` comparison between local and remote
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

## AI Client Patterns
- **OpenAI GPT models** (`gpt-5-nano`, `gpt-5-mini`, `gpt-5.4`, `o3`, `o4-mini`): Use `OpenAI::chat()->create($params)` facade. Import: `use OpenAI\Laravel\Facades\OpenAI;`. Config: `config/openai.php` → `OPENAI_API_KEY`
- **Via OpenRouter** (alternating with OpenAI): `AI::client('openrouter')->chat()->create($params)` with `openai/` model prefix. Config: `services.openrouter.*` → `OPENROUTER_API_KEY`
- **Via Nebius** (non-OpenAI models only, e.g. `Qwen/Qwen3-30B-A3B-Instruct-2507`): `AI::client('nebius')->chat()->create($params)`. Config: `services.nebius.*` → `NEBIUS_API_KEY`
- **Via Gemini** (translation/analysis): Raw `Http::asJson()->withToken(config('services.gemini.api_key'))->post('https://' . config('services.gemini.api_endpoint') . '/chat/completions', $params)`. Model: `gemini-3.1-pro-preview`. Config: `services.gemini.*` → `GEMINI_API_KEY`
- **Via Anthropic direct** (deep analysis batches): Raw HTTP to Messages Batches API with `x-api-key` header. Model: `claude-opus-4-6`. Config: `services.anthropic.*` → `ANTHROPIC_API_KEY`
- **`AI::client($name)`** (in `app/AI.php`): Factory that creates OpenAI SDK clients using `config("services.$name.api_key")` and `config("services.$name.api_endpoint")`. Used for openrouter, nebius, anthropic.
- **NEVER** use raw HTTP for OpenAI GPT models — always use the `OpenAI` facade. NEVER use Nebius credentials for GPT models.

## AI Cost Management
- **OpenAI free token program**: Data-sharing program gives 1M tokens/day for larger models (`gpt-5.4`, `o3`, etc.) and 10M tokens/day for smaller models (`gpt-5-mini`, `gpt-5-nano`, `o4-mini`, etc.). Check eligible models at OpenAI dashboard before switching to a new model
- **Daily token tracking**: `AiUsage` model tracks daily OpenAI token consumption. `AnalyzeNewsJob` checks `AiUsage.total_tokens` against the 1M daily limit before using large OpenAI models (`o3`). When limit is reached, `$isOA` is set to `false` and the job falls back to **Gemini** (`gemini-3.1-pro-preview`) instead — this is the cost-control mechanism to stay within the free tier
- **Gemini free tier**: Only Flash models (`gemini-3-flash-preview`, `gemini-3.1-flash-lite-preview`) have free tier. Pro models (`gemini-3.1-pro-preview`) require paid billing — used as fallback when OpenAI free quota is exhausted because it's still cheaper than paid OpenAI

## Gotchas
- **PHP-only stack** — no Python/Node dependencies; everything must run in pure PHP on shared hosting
- **Verify vendor packages on bigcats** after deploy — `composer install --no-dev` may skip packages
- **Single cron entry on bigcats**: `* * * * * php artisan schedule:run` — ALL scheduling is inside Laravel `Kernel.php`, no external cron entries
- **`NewsStatus` is an int-backed enum** (`app/Enums/NewsStatus.php`) — use integer values (not strings) in tinker/raw queries: CREATED=0, PENDING_REVIEW=3, REJECTED_MANUALLY=4, APPROVED=5, BEING_PROCESSED=10
- **`FlickrPhotoController::process()` chains into `NewsController::process()`** — the `flickr-photo` hourly scheduler command is the trigger for automated news loading, not a separate cron. Trace: `Kernel.php` → `flickr-photo` (hourly) → `FlickrPhotoController::process()` → `app(NewsController::class)->process()`
- **When `.env` changes on bigcats**, always run `php artisan config:cache` (not just `config:clear`) — Laravel may serve cached config otherwise

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

## News Processing Pipeline (Jobs)
- **Pipeline order**: `CleanFreeNewsContentJob`/`CleanNewsContentJob` → `TranslateNewsJob` → `AnalyzeNewsJob` ↔ `ApplyNewsAnalysisJob` (cycle)
- **`CleanFreeNewsContentJob` / `CleanNewsContentJob`**: Content cleanup via `gpt-4.1-nano` (OpenAI facade). No inner retry loop, `$tries=2`. On exhausted retries, marks cleaned with original content and proceeds
- **`TranslateNewsJob`**: Translates via Gemini (`gemini-3.1-pro-preview`). 4-iteration inner retry loop, `$tries=2`, `$timeout=3600`. On success dispatches `AnalyzeNewsJob` if `is_auto`
- **`AnalyzeNewsJob`**: Quality analysis. 4-iteration inner retry loop with model alternation. `$tries=2`, `$timeout=7200`
- **`ApplyNewsAnalysisJob`**: Applies analysis edits to the article. 4-iteration inner loop alternating `o4-mini` (OpenAI) / `openai/o4-mini-high` (OpenRouter). `$tries=2`, `$timeout=360`

### Analyze ↔ Apply auto-cycle (when `is_auto=true`)
1. `AnalyzeNewsJob` produces analysis. If "Так" (Yes) → dispatches `ApplyNewsAnalysisJob`
2. `ApplyNewsAnalysisJob` applies edits, clears `analysis`, increments `analysis_count` → dispatches `AnalyzeNewsJob`
3. Steps 1-2 repeat. Cycle limit checked in `ApplyNewsAnalysisJob`: **32** for `platform=='article'`, **16** for others
4. **Escalation to deep**: two paths trigger `is_deep=true` + `analysis_count=0` + re-dispatch:
   - `AnalyzeNewsJob`: analysis says "Ні" (No) → escalates to deep immediately
   - `ApplyNewsAnalysisJob`: cycle limit exceeded → escalates to deep
5. **Deep cycle** runs the same loop but with Claude Opus instead of o3/Gemini. If deep also hits "Ні" → sets `is_deepest=true`, `is_auto=false`, notifies admin
6. If deep cycle limit also exceeded → sets `is_auto=false`, notifies admin ("Deepest analysis limit reached")

### AnalyzeNewsJob model routing
- `$isOA` = `platform == 'article'` OR `config('app.is_news_by_openai')`
- `is_deep` + `$i<=1`: `claude-opus-4-6` via Anthropic Batches API (polls for result with 30s sleep loop)
- `is_deep` + `$i>1`: `anthropic/claude-opus-4-6` via OpenRouter
- `!is_deep` + `$isOA` + `$i<=1`: `o3` via OpenAI facade
- `!is_deep` + `$isOA` + `$i>1`: `openai/o3` via OpenRouter
- `!is_deep` + `!$isOA` (all `$i`): `gemini-3.1-pro-preview` via direct Gemini HTTP

### Job inner retry pattern
- Most AI jobs use `for ($i = 0; $i < 4; $i++)` with provider alternation: direct API for `$i<=1`, OpenRouter fallback for `$i>1` (or odd/even alternation in ApplyNewsAnalysisJob)
- This is separate from Laravel's `$tries` (queue-level retries on job failure). Both layers provide resilience
- When changing AI models: update BOTH the direct API model name AND the OpenRouter-prefixed variant in the same ternary

### Other jobs (no AI models)
- **`NewsJob`**: Wrapper calling `NewsController::process()`. `$tries=1`, `$timeout=3500`
- **`ProcessTelegramChannelPost`**: Media group coordination via Cache. Photo download retry (5 attempts), media group wait loop (4 iterations with sleep)
- **`PostToSocial`**: Posts to social platforms. No loops. `$timeout=180`

## Logs
- **Laravel log**: `~/api/storage/logs/laravel.log` on bigcats
- **PHP error log**: `~/api/error_log` on bigcats
- View recent logs: `ssh bigcats "tail -100 ~/api/storage/logs/laravel.log"`
