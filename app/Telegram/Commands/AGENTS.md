<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Commands

## Purpose
Telegram bot command and callback handlers. These process user interactions in the Telegram chat used for reviewing and managing news articles and photos.

## Key Files

| File | Description |
|------|-------------|
| `CallbackqueryCommand.php` | Central callback router for all inline keyboard button clicks. Dispatches to NewsController/FlickrPhotoController methods based on callback data prefixes (`news_approve`, `news_decline`, `news_translate`, `delete`, etc.) |
| `ChannelpostCommand.php` | Handles posts forwarded from Telegram channels |
| `NewsCommand.php` | `/news` bot command -- creates news articles directly from Telegram messages |
| `GenericmessageCommand.php` | Handles inline query responses for editing news titles, tags, images, and content |
| `ExcludeTagCommand.php` | `/excludetag` -- Adds a tag to the exclusion list |
| `DeleteExcludedTagCommand.php` | `/deleteexcludedtag` -- Removes a tag from the exclusion list |
| `ShowExcludedTagsCommand.php` | `/showexcludedtags` -- Lists all excluded tags |
| `SubscriptionCommand.php` | Manages user subscriptions |

## For AI Agents

### Working In This Directory
- `CallbackqueryCommand` is the most important file -- it routes all button presses from news review messages
- Callback data format: `{action}` or `{entity}_{action} {id}` (e.g., `news_approve 123`, `delete`)
- `GenericmessageCommand` handles inline edits: parses `news_title`, `news_image`, `news_tags`, `news_content` prefixes
- Commands extend `Longman\TelegramBot\Commands\SystemCommand` or `UserCommand`

<!-- MANUAL: -->
