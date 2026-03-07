<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Resources

## Purpose
Filament v3 resource definitions providing admin CRUD interfaces for the main content types.

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `FlickrPhotoResource/` | Admin resource for Flickr photos (see `FlickrPhotoResource/AGENTS.md`) |
| `NewsResource/` | Admin resource for News articles (see `NewsResource/AGENTS.md`) |

## For AI Agents

### Working In This Directory
- Each resource has a main PHP file defining table columns/filters and a `Pages/` subdirectory for page customizations
- `NewsResource::getUrl('edit', ['record' => $model])` is used in `News::getCaption()` for linking in Telegram messages

<!-- MANUAL: -->
