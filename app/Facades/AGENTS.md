<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Facades

## Purpose
Laravel facade classes providing static-like access to bound services.

## Key Files

| File | Description |
|------|-------------|
| `AI.php` | Facade for the AI service -- provides `AI::client('provider')` to get OpenAI-compatible clients for OpenRouter, Nebius, Gemini, and Anthropic |

## For AI Agents

### Working In This Directory
- The AI facade resolves to a service that creates OpenAI-compatible HTTP clients configured per provider
- Usage: `AI::client('openrouter')->chat()->create($params)`

<!-- MANUAL: -->
