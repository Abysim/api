<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Models

## Purpose
Eloquent models representing the database entities. Models are unguarded (mass assignment protection disabled in `booted()`).

## Key Files

| File | Description |
|------|-------------|
| `News.php` | Central model: stores articles with AI classification, translation state, publish content, Telegram message tracking. Has `booted()` hooks for auto-updating Telegram messages on attribute changes. |
| `FlickrPhoto.php` | Flickr photo with classification and review workflow similar to News |
| `Post.php` | Social media posts |
| `Forward.php` | Content forwarding rules |
| `PostForward.php` | Post-forward relationship pivot |
| `BlueskyConnection.php` | Bluesky account credentials (handle + session tokens) |
| `FediverseConnection.php` | Fediverse/ActivityPub connection data |
| `TwitterConnection.php` | Twitter/X account connection data |
| `ExcludedTag.php` | User-managed tag exclusion list for Telegram bot |
| `AiUsage.php` | Daily AI token usage tracking (per-day totals) |
| `User.php` | Standard Laravel user model |

## For AI Agents

### Working In This Directory
- `News` is the most complex model -- has `booted()` observer that auto-syncs changes to Telegram messages
- `News::classification` is cast as `AsArrayObject` (mutable JSON column)
- `News::species` and `News::tags` are cast as arrays
- Models use `unguard()` -- all attributes are mass-assignable
- `News::getCaption()` generates Telegram message text; `getInlineKeyboard()` generates review buttons
- File management: `loadMediaFile()`, `deleteFile()`, `getFilePath()`, `getFileUrl()` on News model

<!-- MANUAL: -->
