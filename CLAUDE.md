# Project: API (Laravel)

## CRITICAL: Production Safety
- **This project runs directly on production (bigcats). There is no staging environment.**
- **NEVER run commands on bigcats via SSH without explicit user approval.** Before executing any remote command, explain what it does and wait for confirmation. This includes `artisan tinker`, `queue:retry`, DB queries, cache clears, and any write operations.
- **Destructive queue/DB operations are FORBIDDEN without user approval** — `queue:retry all`, `DELETE FROM jobs`, `queue:flush`, table truncation, etc. Always show the command and its impact first.
- **NEVER use `git checkout <file>`, `git restore`, `git reset --hard`, or `git stash`** to revert changes — these destroy ALL uncommitted changes in the file/repo. To revert a specific change, use surgical edits. Only use destructive git commands if the user explicitly requests them by name.
- **`scp` to bigcats IS a deployment action** — requires the same explicit user approval as any SSH command. Never deploy speculatively.
- **`queue:restart` kills workers mid-job** — running jobs (especially long AnalyzeNewsJob with batch polling) are terminated when `queue:restart` is issued. Only run after confirming no critical jobs are in progress, or wait for natural worker rotation (~1 min).
- **Never reset article status via tinker without approval** — `News::find(X)->update(['status' => ...])` is a production write operation. Always show the command and wait for confirmation.

## Overview
Pet project that supports other projects. Laravel 10 API application.
- **Target language**: Ukrainian (`uk`) — all foreign-language news articles are translated INTO Ukrainian. Articles already in Ukrainian skip translation. This is core domain logic, not a bug.

## Tech Stack
- **Framework**: Laravel 10.48.28
- **Language**: PHP
- **PHP version**: 8.4 (both local `p` alias and production bigcats — web and CLI)

## Deployment
- **Production server**: SSH host `bigcats` (connect via `ssh bigcats`)
- **Server path**: `~/api`
- **Deployment method**: Automatic on file save from IDE (SFTP/sync)
- **New files** created outside the IDE (e.g. by Claude) do NOT auto-sync — manually `scp` them to bigcats
- **Modified files** edited by Claude (not via IDE save) also may not auto-sync — verify with `md5sum` comparison between local and remote
- **No local or staging environment** — bigcats is the only runtime environment
- **After deploying code changes** to bigcats, queue workers pick up new code within ~1 minute. `QueueWorkDynamic` workers are one-shot (process one job then exit) with `--max-time=55`, and the scheduler seeds a new worker every minute. No manual restart needed. `queue:restart` is unnecessary for code deploys.
- **Verifying deployed code is running**: After `scp`, new workers (spawned every ~1 min) use updated code immediately. A worker mid-job on `AnalyzeNewsJob` won't pick up changes until it finishes and a new worker starts. Verify with `grep 'expected_new_log_message' storage/logs/laravel.log`.
- **After changing `.env` or config**, run `ssh bigcats "cd ~/api && php artisan config:cache"` — workers will load the new cached config on their next natural restart (~1 min)
- **NEVER run `queue:retry all` as a "deployment step"** — it does NOT restart workers. It re-queues failed jobs and can flood the queue. Deploying code requires NO queue commands.

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
- **GPT-5 family does NOT support `temperature`** — `gpt-5`, `gpt-5-mini`, `gpt-5-nano` all reject any temperature value other than the default (1), both via OpenAI directly and via OpenRouter. Use `reasoning_effort` instead for output control. Only non-GPT-5 models (Qwen, Gemini) accept custom temperature.
- **`reasoning_effort` levels**: `gpt-5-mini` and `gpt-5-nano` support only up to `high`. `xhigh` is only available on larger models (`gpt-5.4`, `o3`). Using unsupported levels causes API errors.

## AI Cost Management
- **OpenAI free token program**: Data-sharing program gives 1M tokens/day for larger models (`gpt-5.4`, `o3`, etc.) and 10M tokens/day for smaller models (`gpt-5-mini`, `gpt-5-nano`, `o4-mini`, etc.). Check eligible models at OpenAI dashboard before switching to a new model
- **Daily token tracking**: `AiUsage` model tracks daily OpenAI token consumption. `AnalyzeNewsJob` checks `AiUsage.total_tokens` against the 1M daily limit before using large OpenAI models (`o3`). When limit is reached, `$isOA` is set to `false` and the job falls back to **Gemini** (`gemini-3.1-pro-preview`) instead — this is the cost-control mechanism to stay within the free tier
- **Gemini free tier**: Only Flash models (`gemini-3-flash-preview`, `gemini-3.1-flash-lite-preview`) have free tier. Pro models (`gemini-3.1-pro-preview`) require paid billing — used as fallback when OpenAI free quota is exhausted because it's still cheaper than paid OpenAI

## Gotchas
- **NEVER redeclare properties defined by Laravel traits** — `Queueable` defines `public $connection`, `public $queue`, etc. Redeclaring with a different type OR a different default value causes a **fatal error** ("definition differs and is considered incompatible"). To override: set the value in the constructor (`$this->connection = 'some_connection'`), never as a class property.
- **PHP-only stack** — no Python/Node dependencies; everything must run in pure PHP on shared hosting
- **Verify vendor packages on bigcats** after deploy — `composer install --no-dev` may skip packages
- **Single cron entry on bigcats**: `* * * * * php artisan schedule:run` — ALL scheduling is inside Laravel `Kernel.php`, no external cron entries
- **`NewsStatus` is an int-backed enum** (`app/Enums/NewsStatus.php`) — use integer values (not strings) in tinker/raw queries: CREATED=0, PENDING_REVIEW=3, REJECTED_MANUALLY=4, APPROVED=5, BEING_PROCESSED=10
- **`DailyStat::flushCache()` runs after each species in `loadNews()`** — fetch counters (raw_http, jina, diffbot, scraperapi) accumulate via `Cache::increment()` during extraction but only persist to DB on flush. If flush calls are removed or the process dies before them, the dashboard "Content Fetching Today" shows 0.
- **`gc_collect_cycles()` after each species fetch** — the HTML5 parser (masterminds/html5) used by Readability creates circular references that PHP's refcount GC doesn't reclaim. Without explicit GC, memory accumulates across species iterations and can exceed the 128MB limit, killing the process.
- **`FlickrPhotoController::process()` chains into `NewsController::process()`** — the `flickr-photo` hourly scheduler command is the trigger for automated news loading, not a separate cron. Trace: `Kernel.php` → `flickr-photo` (hourly) → `FlickrPhotoController::process()` → `app(NewsController::class)->process()`
- **When `.env` changes on bigcats**, always run `php artisan config:cache` (not just `config:clear`) — Laravel may serve cached config otherwise
- **NEVER run `queue:retry all`** — this re-queues ALL failed jobs at once and can flood the queue with hundreds of stale jobs, blocking the entire pipeline. Always inspect failed jobs first with `DB::table('failed_jobs')->get()` and retry specific jobs by UUID: `php artisan queue:retry <uuid>`. There is no valid reason to blindly retry all failed jobs.
- **Queue workers use `runInBackground()`** in Kernel.php — this is critical to prevent `schedule:run` parent processes from piling up (~89MB each). Without it, each `schedule:run` waits 180s for foreground workers, stacking 6+ schedulers consuming ~534MB. NEVER remove `runInBackground()` and NEVER add additional queue worker lines — a previous attempt to add separate `long_running` workers doubled process count, pushed memory from ~400MB to ~700MB, and caused cPanel to kill the `flickr-photo` news fetching process.
- **Single queue with `retry_after=1800`** (`config/queue.php`) — all jobs including `AnalyzeNewsJob` and `ApplyNewsAnalysisJob` use the default `database` connection. PID guards handle retry_after collisions (alive → release, dead → resume from cached state). No separate `long_running` queue.
- **cPanel process killer** — shared hosting (1GB RAM, 128MB PHP memory_limit) kills processes exceeding memory/CPU thresholds. The `flickr-photo` command runs 20-50 minutes; reducing memory pressure (fewer workers, GC between species, blocking junk domains) is essential for run completion. On 2026-03-22, 6/16 hourly runs were killed before completing.
- **Multiple PHP binaries on bigcats** — `/usr/local/bin/php` and `/opt/alt/php84/usr/bin/php` coexist. The scheduler/cron uses the alt path. When spawning PHP subprocesses from artisan commands, always use `PHP_BINARY` constant (not bare `php`) to ensure the child uses the same binary, config, and extensions as the parent.
- **`QueueWorkDynamic` is one-shot** — each worker processes ONE job (`queue:work --once`) then exits. Before taking a job, it spawns a replacement via `exec()` if under `--max-workers` cap. The scheduler (`everyMinute()`) seeds one worker per minute; spawn cascade fills remaining slots. Idle workers exit within 55s (`--max-time`).
- **MariaDB `XOR` is LOGICAL, not bitwise** — `XOR` returns 0 or 1 (boolean), `^` is the bitwise XOR operator. Always use `^` for bit operations: `BIT_COUNT(col ^ ?)`, never `BIT_COUNT(col XOR ?)`
- **`composer install` on bigcats runs `filament:upgrade`** which clears config cache — always run `php artisan config:cache` after
- **`FlickrPhotoStatus` is an int-backed enum** — CREATED=0, REJECTED_BY_TAG=1, REJECTED_BY_CLASSIFICATION=2, PENDING_REVIEW=3, REJECTED_MANUALLY=4, APPROVED=5, PUBLISHED=6, REMOVED_BY_AUTHOR=7, REJECTED_BY_DUPLICATION=8
- **ApplyNewsAnalysisJob system prompt** is constructed by slicing `analyzer.md` via `Str::before('1.')` + `Str::afterLast("\n")` — this extracts ONLY the 3-line preamble + date, stripping all 24 numbered rules. This is intentional (the applier applies corrections, doesn't need analyzer rules), but the slicing is fragile and breaks if `analyzer.md` structure changes.

- **Google News URLs bypass pre-fetch filters** — `$article['link']` from Google News is `news.google.com/...` (a redirect), not the real URL. Domain/path filters in the `getNews()` loop only catch GDELT articles (direct URLs). For Google News articles, filtering must also happen inside `extractContent()` after `urlDecoder->decode()` resolves the real URL (line ~444).

## Resetting Articles for Re-processing
- **Full re-translation reset** requires clearing ALL of: `publish_title`, `publish_content`, `is_translated` (set to false), `status` (set to 10), `analysis`, `previous_analysis`, `content_hashes`, `analysis_count` (set to 0), `is_deep`/`is_deepest` (set to false), `is_auto` (set to true)
- **TranslateNewsJob skips** if `is_translated=true` (line 58) — MUST clear this flag or translation won't run
- **TranslateNewsJob reads from `publish_content`** (line 77), not `content` — if `publish_content` has corrupted text, translation will re-translate the corruption. Reset `publish_content = content` and `publish_title = title` to restore original English
- **Always check queue first**: `DB::table('jobs')->where('payload', 'like', '%{article_id}%')->get()` — delete stale jobs BEFORE dispatching new ones, or old jobs will run concurrently and corrupt content
- **Job constructors take `int $id`**, not model instances: `TranslateNewsJob::dispatch($id)`, `AnalyzeNewsJob::dispatch($id, $isAuto)`

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

## Content Fetching Fallback Chain
- **4-step chain** in `FreeNewsService::extractContent()`: Raw HTTP (4s) → Jina Reader (10s) → Scrape.do (15s) → ScraperAPI (30s, paid)
- **Jina Reader** returns clean markdown — skips Readability extraction, extracts image from markdown, sleeps 2s for rate limiting (20 RPM free tier)
- **Scrape.do and ScraperAPI** return raw HTML — goes through Readability extraction as before
- **Config**: `config/scraper.php` — `JINA_URL`, `SCRAPEDO_URL`/`SCRAPEDO_KEY`, `SCRAPER_URL`/`SCRAPER_KEY`
- **Empty API keys** cause the step to be silently skipped
- **`blocked_domains.json`** (`resources/json/news/blocked_domains.json`): domains skipped entirely during content extraction. E-commerce/fashion sites (eBay, Amazon, etc.) pass Google News keyword filters but waste 30-60s + memory on the 4-step extraction chain for zero useful content. Add new junk domains here when they appear in logs.
- `FileHelper::getUrl()` is NOT used by `extractContent()` — it has its own inline chain. `FileHelper::getUrl()` is still used by other callers (e.g. GoogleNewsUrlDecoder)
- **`news:clear-url-cache`** artisan command clears cached news URLs without flushing other cache entries

## Related Projects (local sibling directories)
- **VPS Scraper**: `/DATA/xampp/htdocs/scrap/` — self-hosted scraping API at `scrap.abysim.com`. Has its own `CLAUDE.md` with SSH alias (`vps-web`), architecture, and log commands. **Always read the sibling CLAUDE.md before SSH-ing to the VPS.**

## Running artisan tinker locally (no DB)
- Local MySQL is not running — use env overrides to bypass DB: `TELEGRAM_API_TOKEN="" DB_CONNECTION=sqlite DB_DATABASE=":memory:" p artisan tinker`

## News Processing Pipeline (Jobs)
- **Pipeline order**: `CleanNewsContentJob` → `TranslateNewsJob` → `AnalyzeNewsJob` ↔ `ApplyNewsAnalysisJob` (cycle)
- **`CleanNewsContentJob`**: Content cleanup via `gpt-5-mini` (OpenAI facade). Prompt loaded from `resources/prompts/cleaner.md`. Two modes: `'auto'` (pipeline cleaning for FreeNews, chains to TranslateNewsJob) and `'manual'` (Telegram button, handles retranslation). No inner retry loop, `$tries=2`. On exhausted retries, marks cleaned with original content and proceeds
- **`TranslateNewsJob`**: Translates via Gemini (`gemini-3.1-pro-preview`). 4-iteration inner retry loop, `$tries=2`, `$timeout=3600`. On success dispatches `AnalyzeNewsJob` if `is_auto`
- **`AnalyzeNewsJob`**: Quality analysis. 4-iteration inner retry loop with model alternation. `$tries=2`, `$timeout=7200`
- **`ApplyNewsAnalysisJob`**: Applies analysis edits to the article. 4-iteration inner loop alternating `gpt-5-mini` (OpenAI) / `openai/gpt-5-mini` (OpenRouter), both with `reasoning_effort: high`. `$tries=2`, `$timeout=360`

### Analyze ↔ Apply auto-cycle (when `is_auto=true`)
1. `AnalyzeNewsJob` produces analysis. If "Так" (Yes) → dispatches `ApplyNewsAnalysisJob`
2. `ApplyNewsAnalysisJob` applies edits, clears `analysis` → dispatches `AnalyzeNewsJob` (which increments `analysis_count` at line 280 when it saves the result)
3. Steps 1-2 repeat. Cycle limit checked in `ApplyNewsAnalysisJob`: **32** for `platform=='article'`, **16** for others
4. **Known gap — orphaned articles**: Articles can get stuck at `status=10` (BEING_PROCESSED) with no pending job. Two causes: (a) all 4 inner-loop iterations throw caught exceptions and the for-loop exits silently, (b) **`retry_after=1800` collision** — the database queue re-releases a reserved job after 1800s while the original worker is still running. A second worker picks it up, the guard clause (`status != PENDING_REVIEW`) returns early, Laravel treats it as "success" and **deletes the job row**. The original worker continues but the job is gone. This affects jobs running >1800s (AnalyzeNewsJob with deep analysis). Check for orphans: `News::where('status', 10)->where('updated_at', '<', now()->subMinutes(30))->where('is_auto', true)->get(['id','analysis_count'])`
5. **Escalation to deep**: two paths trigger `is_deep=true` + `analysis_count=0` + re-dispatch:
   - `AnalyzeNewsJob`: analysis says "Ні" (No) → escalates to deep immediately
   - `ApplyNewsAnalysisJob`: cycle limit exceeded → escalates to deep
6. **Deep cycle** runs the same loop but with Claude Opus instead of GPT-5.4/Gemini. If deep also hits "Ні" → sets `is_deepest=true`, `is_auto=false`, notifies admin
7. If deep cycle limit also exceeded → sets `is_auto=false`, notifies admin ("Deepest analysis limit reached")

### Oscillation detection (content_hashes + previous_analysis)
- **`content_hashes`** (JSON): Per-sentence hashing via `SentenceHasher`. Structure: `{cycles: [{hashes: [sentence1_md5, sentence2_md5, ...]}, ...]}`. Each cycle entry = one Apply iteration's snapshot of all sentence hashes. Title is hashed with `title:` prefix to distinguish from content sentences
- **Flip-flop detection** (`SentenceHasher::detectFlipFlops`): Level 1 (full reversion) compares sorted hash set against older cycles; Level 2 (partial) checks if any new sentence hash appeared in an older cycle. Needs **2+ prior cycles** minimum for set comparison. When flip-flop detected: AI selects best variant per oscillating sentence via `gpt-5-mini`, saves resolved content, then **hands back to analyzer** (never escalates directly). The analyzer gets the final say on escalation via the existing "Ні" two-strike logic.
- **`SentenceHasher`** (`app/Helpers/SentenceHasher.php`): Pure helper — `splitSentences()`, `hashSentences()`, `detectFlipFlops()`, `hash()`, `stripTitlePrefix()`, `isOldFormat()`, `buildVariantSelectionPrompt()`. Ukrainian abbreviation protection via `ABBREV_PATTERN` constant.
- **`previous_analysis`** (TEXT): Previous round's corrections injected into analyzer's user message so it can choose the better variant instead of flip-flopping
- Both fields reset on tier escalation (each tier starts fresh)
- Reset handlers: `translation()`, `counter()`, `deepest()` clear both; `deep()` clears both; `reset()` clears only `previous_analysis`
- Sentence hash: `md5(mb_strtolower(preg_replace('/\s+/', ' ', trim($sentence))))` — per-sentence, not whole-article

### AnalyzeNewsJob model routing
- `$isOA` = `platform == 'article'` OR `config('app.is_news_by_openai')`
- `is_deep` + `$i<=1`: `claude-opus-4-6` via Anthropic Batches API (polls for result with 30s sleep loop)
- `is_deep` + `$i>1`: `anthropic/claude-opus-4-6` via OpenRouter
- `!is_deep` + `$isOA` + `$i<=1`: `gpt-5.4` via OpenAI facade
- `!is_deep` + `$isOA` + `$i>1`: `openai/gpt-5.4` via OpenRouter
- `!is_deep` + `!$isOA` (all `$i`): `gemini-3.1-pro-preview` via direct Gemini HTTP

### Job inner retry pattern
- Most AI jobs use `for ($i = 0; $i < 4; $i++)` with provider alternation: direct API for `$i<=1`, OpenRouter fallback for `$i>1` (or odd/even alternation in ApplyNewsAnalysisJob)
- This is separate from Laravel's `$tries` (queue-level retries on job failure). Both layers provide resilience
- When changing AI models: update BOTH the direct API model name AND the OpenRouter-prefixed variant in the same ternary
- **"Ні" two-strike confirmation is essential**: when `i=0` returns "Ні", the code retries at `i=1` before escalating. Both i=0 and i=1 use direct OpenAI (OpenRouter only at i>=2). ~36% of "Ні" results flip to "Так" on retry — do NOT suggest removing this as an optimization

### AnalyzeNewsJob kill recovery
- **PID guard**: On `retry_after` re-release (1800s), guard checks `posix_kill($pid, 0)` from cached state. Alive → `release()`. Dead → resume from cached `$i`/`$j` + Telegram notification.
- **Cache state**: `Cache::get('analyze_job_state_{id}')` stores `{pid, i, j, batch_id, poll_start_time}`. Updated every polling iteration (30s). TTL = `TIMEOUT` (7200s).
- **Scheduler safety net**: `news:resume-orphaned` runs every 5 min, catches articles stuck at `BEING_PROCESSED` longer than `TIMEOUT` with no live worker PID.

### Other jobs (no AI models)
- **`NewsJob`**: Wrapper calling `NewsController::process()`. `$tries=1`, `$timeout=3500`. Constructor: `__construct($load=false, $force=false, $lang=null, $publish=true)`. To manually dispatch for a specific language: `NewsJob::dispatch(true, true, 'uk', false)` — `$load` must be `true` to load news, `$force=true` bypasses the hourly schedule check (otherwise `shouldLoadNews()` may skip if already ran this hour), `$publish=false` to skip auto-publishing
- **`ProcessTelegramChannelPost`**: Media group coordination via Cache. Photo download retry (5 attempts), media group wait loop (4 iterations with sleep)
- **`PostToSocial`**: Posts to social platforms. No loops. `$timeout=180`

## FlickrPhoto Deduplication
- **Perceptual hashing** via `jenssegers/imagehash` (pHash algorithm) — 64-bit hash stored as signed `BIGINT` in `perceptual_hash` column on `flickr_photos`
- **Pipeline**: after classification in `processPhotos()`, `checkDuplicate()` computes hash, compares via `BIT_COUNT(perceptual_hash ^ ?) <= threshold`
- **Threshold**: configurable via `FLICKR_PHOTO_HASH_THRESHOLD` env (default: 10 Hamming distance out of 64 bits)
- **vs PUBLISHED**: new photo auto-rejected with `REJECTED_BY_DUPLICATION` (status 8)
- **vs queued (PENDING_REVIEW/APPROVED)**: largest file wins (sharpness proxy); loser gets PENDING_REVIEW; Telegram replies with Delete button sent to both
- **Hash permanence**: hashes persist in DB even after file deletion or manual approval override
- **Backfill command**: `php artisan flickr-photo:hash-backfill --detect [--dry-run] [--reject] [--threshold=N]`

## Logs
- **Laravel log**: `~/api/storage/logs/laravel.log` on bigcats
- **PHP error log**: `~/api/error_log` on bigcats
- View recent logs: `ssh bigcats "tail -100 ~/api/storage/logs/laravel.log"`
- **Monitoring analysis pipeline**: filter out verbose JSON with `grep -v 'result:' | grep -v 'Updating'` — result lines contain full AI response payloads (100KB+)
- **Log timestamps are UTC**, but bigcats system clock is CET (UTC+1) — a log entry at `02:30` corresponds to server time `03:30`
- **Keep log entries single-line** — use `json_encode($data, JSON_UNESCAPED_UNICODE)` without `JSON_PRETTY_PRINT` in `Log::info()` calls, so entries remain greppable via `grep 'article_id' laravel.log`
