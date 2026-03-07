<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# tests

## Purpose
PHPUnit test suites for the application.

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `Feature/` | Feature/integration tests |
| `Unit/` | Unit tests |

## For AI Agents

### Working In This Directory
- Run tests: `p artisan test` (local) or `ssh bigcats "cd ~/api && php artisan test"`
- Test against PHP 8.2 compatibility (web server version)
- The project uses PHPUnit 10.x
- Use project-relevant search terms in tests: `lion`, `tiger`, `leopard`, `cheetah`, `wildlife` — not generic queries
- Live API tests: set `SKIP_LIVE_API_TESTS=false` env var; use `getenv()` not `env()` for boolean checks
- New test files don't auto-sync to bigcats — `scp` them manually

<!-- MANUAL: -->
