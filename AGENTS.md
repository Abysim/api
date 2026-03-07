<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# API (Laravel)

## Purpose
A Laravel 10 API application that aggregates, classifies, translates, and publishes wildlife news (focused on big cat species). It also manages Flickr photo curation and social media cross-posting to Telegram, Bluesky, and IFTTT-connected platforms. The project powers a companion website ("BigCats") with curated, AI-processed content.

## Key Files

| File | Description |
|------|-------------|
| `composer.json` | PHP dependencies and autoload configuration |
| `artisan` | Laravel CLI entry point |
| `CLAUDE.md` | Project instructions for AI agents (deployment, CLI aliases, SSH) |
| `.env` | Environment config (on bigcats server only, never committed) |

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `app/` | Application source code: models, controllers, services, jobs (see `app/AGENTS.md`) |
| `config/` | Laravel and third-party service configuration (see `config/AGENTS.md`) |
| `routes/` | API, web, Telegram webhook, and social media route definitions (see `routes/AGENTS.md`) |
| `database/` | Migrations, factories, and seeders (see `database/AGENTS.md`) |
| `resources/` | Views, AI prompts, species/tag JSON data (see `resources/AGENTS.md`) |
| `tests/` | PHPUnit feature and unit tests (see `tests/AGENTS.md`) |
| `bootstrap/` | Laravel bootstrap and framework cache |
| `public/` | Web root with Filament admin assets |

## For AI Agents

### Working In This Directory
- **No local environment** -- bigcats (SSH host) is the only runtime. See CLAUDE.md for SSH commands.
- PHP 8.2 on web, 8.3 on CLI. Always test against 8.2 compatibility.
- Use `p` alias for `php` and `c` for `composer` in local terminal only.
- Deployment is automatic via IDE SFTP sync on file save.

### Key Architecture
- **News pipeline**: Load (NewsCatcher/FreeNews) -> Classify (AI) -> Translate (Gemini) -> Analyze (OpenAI/Claude/Gemini) -> Review (Telegram) -> Publish (IFTTT/BigCats/Bluesky)
- **Flickr pipeline**: Fetch photos -> Classify -> Review (Telegram) -> Publish
- **AI providers**: OpenAI, OpenRouter, Nebius, Gemini, Anthropic -- routed via `App\AI` facade and direct HTTP
- **News service driver**: Configurable via `NEWS_DRIVER` env (`newscatcher3` default, `free` for FreeNewsService)

### Testing Requirements
- Run tests: `p artisan test` locally or `ssh bigcats "cd ~/api && php artisan test"`
- Check logs: `ssh bigcats "tail -100 ~/api/storage/logs/laravel.log"`

## Dependencies

### External
- `laravel/framework` ^10.10 -- Core framework
- `php-telegram-bot/laravel` -- Telegram bot integration
- `openai-php/laravel` -- OpenAI API client
- `filament/filament` ^3.2 -- Admin panel
- `fivefilters/readability.php` -- HTML content extraction
- `deeplcom/deepl-php` -- DeepL translation
- `jeroen-g/flickr` -- Flickr API client
- `atymic/twitter` -- Twitter API client
- `aws/aws-sdk-php` -- AWS services
- `intervention/image-laravel` -- Image processing

<!-- MANUAL: -->
