<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# config

## Purpose
Laravel configuration files. Third-party service credentials and app settings are loaded from `.env` on the bigcats server.

## Key Files

| File | Description |
|------|-------------|
| `services.php` | Third-party API keys and endpoints: Flickr, IFTTT, Bluesky, NewsCatcher, BigCats, OpenRouter, Nebius, Gemini, Anthropic. Also `news.driver` setting. |
| `telegram.php` | Telegram bot token, admin chat IDs, webhook config |
| `openai.php` | OpenAI API key and organization config |
| `filesystems.php` | Storage disk config (local public disk for news images) |
| `app.php` | App name, env, debug, timezone, providers |
| `database.php` | MySQL connection settings |
| `queue.php` | Queue driver config |
| `scraper.php` | ScraperAPI credentials for web content extraction fallback |

## For AI Agents

### Working In This Directory
- Never hardcode credentials -- always use `env()` helper
- The `services.news.driver` key controls which news service implementation is used (`newscatcher3` or `free`)
- AI provider configs (openrouter, nebius, gemini, anthropic) each have `api_key`, `api_endpoint`, and `api_timeout`

<!-- MANUAL: -->
