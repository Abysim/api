<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Telegram

## Purpose
Telegram bot command handlers using the php-telegram-bot/laravel package. The bot serves as the primary review interface for news and photos.

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `Commands/` | Individual bot command and callback handlers (see `Commands/AGENTS.md`) |

## For AI Agents

### Working In This Directory
- Commands follow the php-telegram-bot naming convention: `{Action}Command.php`
- The bot handles inline keyboard callbacks for news approval/decline/translate/analyze workflows
- `CallbackqueryCommand` is the central router for all inline button clicks

<!-- MANUAL: -->
