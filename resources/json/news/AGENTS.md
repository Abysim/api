<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# news

## Purpose
Reference data for the news classification and tagging pipeline, organized by function.

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `species/` | Per-language species keyword definitions with search words and exclusion rules (see below) |
| `tags/` | Classification key to hashtag mappings for species, countries, regions, and English translations (see below) |

## For AI Agents

### species/ Directory
- Files: `uk.json`, `en.json` (one per supported language)
- Structure: `{ "species_key": { "words": ["keyword1*", "keyword2"], "exclude": ["excluded_word"], "excludeCase": ["Case Sensitive Exclude"] } }`
- `words` array: search terms for news API queries and article matching (supports `*` suffix for prefix matching)
- `exclude`: words to exclude from search queries
- `excludeCase`: case-sensitive phrases that trigger keyword rejection in `NewsController::excludeByTags()`

### tags/ Directory
- Files: `species.json`, `country.json`, `region.json`, `english.json`
- Structure: `{ "classification_key": "#hashtag" }`
- Maps AI classification output keys to hashtag strings used in `publish_tags`
- `english.json` maps Ukrainian hashtags to English equivalents for the English Bluesky/IFTTT channel

<!-- MANUAL: -->
