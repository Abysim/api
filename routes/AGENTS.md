<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# routes

## Purpose
HTTP route definitions for the application. Routes are split by concern: API endpoints, web pages, Telegram webhook, and social media callbacks.

## Key Files

| File | Description |
|------|-------------|
| `api.php` | Main API routes: `GET /news` (trigger news processing), `GET /flickr-photo`, `ANY /bluesky` |
| `web.php` | Web routes (minimal -- just welcome page) |
| `telegram.php` | `POST /telegram/webhook/{token}` -- Telegram bot webhook with token validation |
| `console.php` | Artisan console route definitions |
| `channels.php` | Broadcast channel authorization |
| `fediverse.php` | Fediverse/ActivityPub routes |
| `twitter.php` | Twitter integration routes |

## For AI Agents

### Working In This Directory
- API routes use the `api` middleware group (no session, token-based)
- Telegram webhook validates the bot token in the URL against config
- The `GET /news` endpoint triggers the full news pipeline via `NewsController@process`

<!-- MANUAL: -->
