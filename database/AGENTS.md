<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# database

## Purpose
Database migrations, model factories, and seeders for MySQL.

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `migrations/` | Schema migrations for all tables (news, flickr_photos, forwards, connections, ai_usage, etc.) |
| `factories/` | Model factories for testing |
| `seeders/` | Database seeders |

## For AI Agents

### Working In This Directory
- Run migrations on bigcats: `ssh bigcats "cd ~/api && php artisan migrate"`
- The `news` table is the largest and most complex -- has JSON columns for `classification`, `species`, `tags`
- Never run destructive migrations without confirming with the user first

<!-- MANUAL: -->
