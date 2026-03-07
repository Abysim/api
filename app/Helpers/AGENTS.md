<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Helpers

## Purpose
Utility classes for cross-cutting concerns like HTTP fetching and file operations.

## Key Files

| File | Description |
|------|-------------|
| `FileHelper.php` | Static utility: `getUrl()` (HTTP fetch with ScraperAPI fallback), `getMimeType()` (in-memory MIME detection), `generateImageCaption()` (GPT-5 vision-based alt text), `getTelegramPhotoUrl()` (extract highest-res photo URL from Telegram response) |

## For AI Agents

### Working In This Directory
- `getUrl()` first tries direct HTTP, then falls back to ScraperAPI for blocked sites
- `generateImageCaption()` uses OpenAI GPT-5/GPT-5-mini vision to generate accessibility captions in the target language
- `getTelegramPhotoUrl()` picks the largest photo size from Telegram's photo array

<!-- MANUAL: -->
