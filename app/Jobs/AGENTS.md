<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Jobs

## Purpose
Queue jobs for asynchronous, long-running news processing tasks: translation, analysis, publishing, and social media posting.

## Key Files

| File | Description |
|------|-------------|
| `NewsJob.php` | Dispatches a new `NewsController::process()` cycle (used for chaining when there are 1000+ articles) |
| `TranslateNewsJob.php` | Translates non-Ukrainian news to Ukrainian using Gemini 2.5 Pro. Chains to AnalyzeNewsJob in auto mode. |
| `AnalyzeNewsJob.php` | AI editorial analysis using OpenAI/Claude/Gemini with batch API support for Anthropic. Manages deep/deepest analysis tiers. |
| `ApplyNewsAnalysisJob.php` | Applies AI analysis suggestions to news content |
| `PostToSocial.php` | Posts content to social media platforms |
| `ProcessTelegramChannelPost.php` | Handles incoming Telegram channel posts |

## For AI Agents

### Working In This Directory
- Jobs use `ShouldQueue` with 2 retries and long timeouts (up to 7200s for analysis)
- `AnalyzeNewsJob` supports Anthropic batch API with polling loop for deep analysis
- Auto mode chains: TranslateNewsJob -> AnalyzeNewsJob -> ApplyNewsAnalysisJob
- AI provider selection varies by depth tier and retry attempt number

### Common Patterns
- 4-iteration retry loops with alternating AI providers (even/odd iterations)
- `</think>` stripping for reasoning model responses
- Token usage tracking via `AiUsage` model
- Telegram notification on success/failure

<!-- MANUAL: -->
