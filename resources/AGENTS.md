<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# resources

## Purpose
Application resources: Blade views, CSS/JS assets, AI prompt templates, and JSON reference data for news species classification and tagging.

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `views/` | Blade templates including Filament admin customizations |
| `prompts/` | Markdown prompt templates for AI classification, translation, and analysis |
| `json/` | Reference data: species keywords per language, tag mappings (see `json/AGENTS.md`) |
| `css/` | CSS assets |
| `js/` | JavaScript assets |

## For AI Agents

### Working In This Directory
- Prompts in `prompts/` are loaded by `NewsController::getPrompt()` -- filenames map to prompt names
- Species JSON in `json/news/species/` defines search keywords and exclusion rules per language
- Tags JSON in `json/news/tags/` maps classification keys to hashtag strings
- Prompt files contain Ukrainian-language instructions for AI models

<!-- MANUAL: -->
