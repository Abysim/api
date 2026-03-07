<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# NewsResource

## Purpose
Filament admin resource for managing News articles -- table listing with status filters, inline editing of titles/content/tags, and classification data viewing.

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `Pages/` | Filament page classes (List, Edit, Create) for News records |

## For AI Agents

### Working In This Directory
- `NewsResource::getUrl('edit', ['record' => $model])` is used throughout the app to generate admin edit links
- The edit page allows modifying publish_title, publish_content, publish_tags, status, and media

<!-- MANUAL: -->
