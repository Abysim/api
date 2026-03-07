<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Commands

## Purpose
Artisan CLI commands for scheduled tasks and manual operations.

## Key Files

| File | Description |
|------|-------------|
| `NewsCommand.php` | `news:process` -- Main news pipeline trigger (typically via cron) |
| `PublishNewsCommand.php` | `news:publish` -- Standalone news publishing command |
| `FlickrPhotoCommand.php` | `flickr:process` -- Fetches and processes Flickr photos |
| `PhotoCopyrightCommand.php` | Validates photo copyright compliance |
| `TelegramCustomFetchCommand.php` | Custom Telegram update fetching (alternative to webhook) |
| `WebSocketFirehoseCommand.php` | Connects to Bluesky/AT Protocol WebSocket firehose for real-time content |
| `WordsCommand.php` | Utility for species keyword management |

## For AI Agents

### Working In This Directory
- Commands extend `Illuminate\Console\Command`
- `NewsCommand` is the primary entry point -- delegates to `NewsController::process()`
- Run on bigcats: `ssh bigcats "cd ~/api && php artisan news:process"`

<!-- MANUAL: -->
