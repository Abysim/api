<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Controllers

## Purpose
API controllers handling HTTP requests and containing core business logic.

## Key Files

| File | Description |
|------|-------------|
| `Controller.php` | Base controller class |
| `NewsController.php` | **The largest file in the project.** Handles the entire news lifecycle: loading from news services, species mapping, keyword/duplicate filtering, AI classification (multi-tier), publishing via IFTTT/BigCats/Bluesky, and Telegram review actions (approve/decline/translate/analyze/apply/deep/content). |
| `FlickrPhotoController.php` | Flickr photo fetching, classification, and publishing pipeline |
| `BlueskyController.php` | Bluesky social network integration endpoint |

## For AI Agents

### Working In This Directory
- `NewsController` is injected with `NewsServiceInterface` via constructor DI
- News processing flow: `process()` -> `loadNews()` -> `processNews()` -> `classifyNews()` -> `preparePublish()` -> `sendNewsToReview()`
- Telegram review actions are public methods called by `CallbackqueryCommand`: `approve()`, `decline()`, `translate()`, `analyze()`, `apply()`, `deep()`, `deepest()`, `content()`, `reset()`, etc.
- `getPrompt()` is a static method that loads markdown templates from `resources/prompts/`
- Classification uses alternating AI providers (even/odd iterations) with fallback

### Common Patterns
- Species keywords loaded from `resources/json/news/species/{lang}.json`
- Tags loaded from `resources/json/news/tags/{name}.json`
- 4-iteration retry loops with alternating providers
- `similar_text()` at 70% threshold for duplicate detection

<!-- MANUAL: -->
