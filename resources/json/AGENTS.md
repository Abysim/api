<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# json

## Purpose
JSON reference data for the news classification and tagging system.

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `news/` | News-specific reference data (see `news/AGENTS.md`) |

## For AI Agents

### Working In This Directory
- JSON files are loaded by `NewsController` via `getSpecies()` and `getTags()` methods
- These files define the species keyword vocabularies and tag-to-hashtag mappings

<!-- MANUAL: -->
