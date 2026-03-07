<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Http

## Purpose
HTTP layer: controllers handling API requests and middleware for request filtering.

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `Controllers/` | API controllers for news, Flickr photos, and Bluesky (see `Controllers/AGENTS.md`) |
| `Middleware/` | Request middleware classes |

## For AI Agents

### Working In This Directory
- Controllers are thin for Bluesky/Flickr but NewsController contains substantial business logic
- The Telegram bot interaction logic lives partly in NewsController (approve/decline/translate actions)

<!-- MANUAL: -->
