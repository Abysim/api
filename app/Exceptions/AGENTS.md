<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Exceptions

## Purpose
Custom exception classes for domain-specific error handling.

## Key Files

| File | Description |
|------|-------------|
| `Handler.php` | Laravel exception handler |
| `NewsCatcherQuotaExceededException.php` | Thrown when NewsCatcher API quota is exhausted, stops further queries in the batch |

## For AI Agents

### Working In This Directory
- `NewsCatcherQuotaExceededException` is caught in `NewsController::loadNews()` to gracefully stop loading when API limits are hit

<!-- MANUAL: -->
