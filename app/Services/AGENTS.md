<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Services

## Purpose
Service classes for external API integrations: news fetching from multiple providers and content publishing to the BigCats website.

## Key Files

| File | Description |
|------|-------------|
| `NewsServiceInterface.php` | Contract for news services: `getNews()`, `getSearchQueryLimit()`, `generateSearchQuery()`, `getName()`. Defines shared constants: DEFAULT_LANG, EXCLUDE_COUNTRIES, EXCLUDE_DOMAINS. |
| `NewsCatcherService.php` | NewsCatcher v2 API implementation (legacy) |
| `NewsCatcher3Service.php` | NewsCatcher v3 API implementation (default driver) |
| `BigCatsService.php` | HTTP client for publishing news to the BigCats website via its API (POST news/create) |

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `News/` | Free (no-API-key) news sources: Google News RSS + GDELT (see `News/AGENTS.md`) |

## For AI Agents

### Working In This Directory
- All news services implement `NewsServiceInterface` -- adding a new source means implementing this interface
- `generateSearchQuery()` builds provider-specific boolean search strings from species keywords
- `getSearchQueryLimit()` returns max query length (varies by provider)
- `BigCatsService` uses bearer token auth against the BigCats API

<!-- MANUAL: -->
