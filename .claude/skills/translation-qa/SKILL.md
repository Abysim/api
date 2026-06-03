---
name: translation-qa
description: >-
  QA the Ukrainian translation of one news article by id: optionally translate to Ukrainian, then loop an
  Opus analyzer + Sonnet editor until two consecutive «Ні», then autonomously write the corrected text back
  to prod via the API. Trigger on "translation-qa <id>", "run translation QA on news <id>", "clean up the
  translation of article <id>".
---

# translation-qa

Execute the steps below for `<id>`. Do ONLY these steps — do not read the article body, audit its state, or
explore the codebase (`language=en` + `is_translated=false` is a normal input). Design rationale lives in the
executed files' comments (start with `workflow.mjs`); it is intentionally NOT repeated here.

## Args
- `<id>` (required) — News article id.
- `--no-write` — run the loop + report, skip the POST.
- `--dry-run` / `--text <file>` — use local text instead of the prod read; never POSTs.
- `--max-cycles N` (default 16, 32 for articles) · `--editor-model opus|sonnet` (default sonnet) ·
  `--translate true|false` (default auto) · `--translator-model opus|sonnet` (default opus).

## Steps
1. **Fetch** — `Agent(subagent_type:"translation-fetcher", prompt:"node .claude/skills/translation-qa/fetch-api.mjs --id <id> — return its JSON line verbatim")`. If it returns `ok:false`, report it and stop.
2. **Bake + run** — `node .claude/skills/translation-qa/bake-workflow.mjs --source /tmp/tqa-<id>-source.json --out /tmp/tqa-<id>-run.mjs [--max-cycles N] [--editor-model M] [--translator-model M] [--translate B]`, then `Workflow({ scriptPath:"/tmp/tqa-<id>-run.mjs" })`. Record the task-output path.
3. **Post** (skip on `--no-write`/`--dry-run`/`--text`) — `Agent(subagent_type:"translation-poster", prompt:"node .claude/skills/translation-qa/post-api.mjs --id <id> --result <TASK_OUTPUT_PATH> --source /tmp/tqa-<id>-source.json --apply — return its JSON line verbatim")`.
4. **Report** the poster verdict + the loop `outcome`/`cyclesRun`. Stop.

## Stop-conditions (report; never improvise a write)
- fetch `ok:false` → stop.
- poster `http:409` → optimistic lock failed (article changed since the read); tell the user to re-run.
- poster `reason:"not-converged"` → loop capped without converging; re-run with a higher `--max-cycles`, or re-run the poster with `--force` ONLY on explicit user approval.

## Setup (once; and after editing resources/prompts/{translate,analyzer,editor}.md)
`node .claude/skills/translation-qa/generate-agents.mjs`, then restart Claude Code (agents register only at session start).
