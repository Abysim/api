<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Providers

## Purpose
Laravel service providers for dependency injection and bootstrapping.

## Key Files

| File | Description |
|------|-------------|
| `AppServiceProvider.php` | Binds `NewsServiceInterface` to either `FreeNewsService` or `NewsCatcher3Service` based on `services.news.driver` config |

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `Filament/` | Filament admin panel provider configuration |

## For AI Agents

### Working In This Directory
- The news driver binding is the key DI configuration: `'free'` -> FreeNewsService, default -> NewsCatcher3Service
- Adding a new news source requires implementing `NewsServiceInterface` and adding a case to the match expression

<!-- MANUAL: -->
