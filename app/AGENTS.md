<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# app

## Purpose
Core application code following Laravel conventions. Contains all business logic for news aggregation, AI-powered classification/translation, Flickr photo curation, Telegram bot interactions, and social media publishing.

## Key Files

| File | Description |
|------|-------------|
| `AI.php` | Facade accessor for multi-provider AI client (OpenRouter, Nebius, Gemini, Anthropic) |
| `Bluesky.php` | Bluesky social network posting client |

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `Console/` | Artisan commands for scheduled tasks (see `Console/AGENTS.md`) |
| `Enums/` | Status enums for News and FlickrPhoto (see `Enums/AGENTS.md`) |
| `Exceptions/` | Custom exception classes (see `Exceptions/AGENTS.md`) |
| `Facades/` | Laravel facades for custom services (see `Facades/AGENTS.md`) |
| `Filament/` | Admin panel resources for News and FlickrPhoto (see `Filament/AGENTS.md`) |
| `Helpers/` | Utility classes for file operations and AI image captioning (see `Helpers/AGENTS.md`) |
| `Http/` | Controllers and middleware (see `Http/AGENTS.md`) |
| `Jobs/` | Queue jobs for news translation, analysis, and publishing (see `Jobs/AGENTS.md`) |
| `Models/` | Eloquent models (see `Models/AGENTS.md`) |
| `Providers/` | Service providers including news driver binding (see `Providers/AGENTS.md`) |
| `Services/` | News fetching services and BigCats API client (see `Services/AGENTS.md`) |
| `Telegram/` | Telegram bot command handlers (see `Telegram/AGENTS.md`) |

## For AI Agents

### Working In This Directory
- The `AI` facade supports multiple providers: call `AI::client('openrouter')` or `AI::client('nebius')` etc.
- News processing is the central feature -- most code paths relate to the news pipeline.
- Telegram is used as the review/approval interface for news and photos.

### Common Patterns
- Models use `unguard()` in `booted()` for mass assignment flexibility
- Jobs implement `ShouldQueue` with retry logic (typically 2-4 attempts)
- AI calls always have fallback providers and retry loops
- Classification results stored as JSON in `classification` column

<!-- MANUAL: -->
