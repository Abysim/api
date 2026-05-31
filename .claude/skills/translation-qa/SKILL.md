---
name: translation-qa
description: >-
  Run the production translation-QA loop (analyzer ↔ editor) on one news article by id,
  using a Claude Code dynamic workflow. Reads publish_title/publish_content from the bigcats
  production DB (read-only); if the article is not Ukrainian yet, an Opus translator subagent
  translates it first, then it loops an Opus analyzer subagent and a Sonnet editor subagent —
  carrying the EXACT production translate.md/analyzer.md/editor.md prompts — until the analyzer
  confirms nothing to fix (two consecutive «Ні»), shows a diff,
  and writes the corrected text back ONLY after explicit approval. There is no
  oscillation/flip-flop/hash tracking by design (Opus is trusted to converge; a few extra
  cycles are acceptable). Trigger when the user asks to QA / clean up / polish / re-analyze
  the Ukrainian translation of a specific news article id — e.g. "translation-qa 12345",
  "run translation QA on news 12345", "clean up the translation of article 12345".
---

# Translation QA — analyze ↔ apply loop (Claude Code port)

Ports this project's production translation pipeline tail — `TranslateNewsJob` (optional) →
`AnalyzeNewsJob ↔ ApplyNewsAnalysisJob` — into a dynamic workflow. If the article is already
Ukrainian it runs the analyze↔apply loop directly; if it is not yet translated (`language != 'uk'
&& !is_translated`), a translator subagent first renders it into Ukrainian, then the loop runs.
The article id usually comes from its Telegram review message, so a `message_id` is normally present.

The deterministic loop lives in `workflow.mjs` (this directory). This skill is the I/O
wrapper the workflow sandbox cannot be: it reads prod + the prompt files, hands them to the
workflow as `args`, and performs the approval-gated write-back.

## ⚠ Production safety (read first — bigcats is production, no staging)
- The prod **read** (Phase A) is read-only and safe, but **announce it** before running it.
- The **write-back** (Phase D) is the only mutation. It is **gated behind explicit approval**,
  shows the exact command **and a locally-decoded preview** first, and uses an **optimistic
  lock**. NEVER write without the user approving the decoded text.
- The write touches `publish_title` + `publish_content` + the four processing flags
  `is_translated=1, is_deep=1, is_deepest=1, is_auto=0` (marks the article fully-processed/terminal)
  — and **nothing else**. Never `status` / `analysis` / `previous_analysis` / `content_hashes` /
  `analysis_count`.
- No `queue:retry`, no destructive git/DB, no other SSH writes.

## ⚠ Scope discipline (do ONLY the job — never audit the article)
- The article's state/metadata is **whatever it is** — do NOT question, "verify," or flag it.
  In particular, `language=en` + `is_translated=false` + a set `message_id` is a **normal, expected**
  state: a foreign article in the Telegram review queue that is **not yet translated** — exactly the
  case this skill exists to handle. Do NOT call it "unusual," do NOT pause to investigate why it's in
  that state, do NOT generate assumptions about it.
- The orchestrator's whole job is Phase A read → Phase B build prompts → Phase C run workflow →
  Phase D present + gated write. **Nothing else.** Do not explore the codebase, read source files,
  query other tables, or editorialize about article metadata — that wastes tokens and risks wrong
  actions.
- Token policy: the **orchestrator** stays lean (only the four phases). The **subagents**
  (translator / analyzer / editor) may use **as many tokens as they need** for a high-quality
  translation/analysis — quality there is the priority, never the constraint.

## Arguments
- `<id>` (required) — the News article id.
- `--dry-run` / `--text <file>` — skip the prod read; use local text (first line after `# ` is
  the title, the rest is the content). Never writes.
- `--no-write` — run the loop and show the result, but do not offer write-back.
- `--max-cycles N` — safety cap (default 16).
- `--editor-model opus|sonnet` — editor subagent model (default `sonnet`; analyzer is always `opus`).
- `--translate true|false` — force or skip the translate-first step (default: auto — translate only when `language != 'uk' && !is_translated`).
- `--translator-model opus|sonnet` — translator subagent model (default `opus`).

## Phase A — Read the article from prod (read-only; skip for --dry-run/--text)
Announce, then run — **redirect the JSON to a file** so the big `publish_*` fields flow through files, never through your output (substitute the real id for `ID`):

```bash
ssh bigcats "cd ~/api && php artisan tinker --execute='\$n=\App\Models\News::find(ID); echo \$n? json_encode([\"publish_title\"=>\$n->publish_title,\"publish_content\"=>\$n->publish_content,\"platform\"=>\$n->platform,\"date\"=>optional(\$n->date)->format(\"Y-m-d\"),\"dateFormatted\"=>optional(\$n->date)->translatedFormat(\"j F Y\"),\"message_id\"=>\$n->message_id,\"language\"=>\$n->language,\"is_translated\"=>(bool)\$n->is_translated],JSON_UNESCAPED_UNICODE):json_encode([\"error\"=>\"not found\"]);'" > /tmp/tqa-ID-source.json
```

- `/tmp/tqa-ID-source.json` is the canonical input for Phases C + D. **Never copy `publish_title`/`publish_content` into your own output** — the helper scripts read them from this file (LLMs corrupt long text / base64 — that is the entire reason for this design).
- You MAY read the file for the SHORT scalars (`platform`, `language`, `is_translated`, `message_id`, `dateFormatted`) to choose flags / disclose. Abort if it contains `{"error":...}` or empty `publish_content`. `dateFormatted` is computed bigcats-side (prod `uk` locale); if `date` is null it is empty.
- The original `publish_content` in this file is the Phase-D optimistic-lock snapshot (the helper reads it; you do not reproduce it).

## Phase B — Determine variant + date (prompts live in pre-built agents)
The translator/analyzer/editor prompts are baked into project agents — `.claude/agents/translation-{translator,analyzer,editor}[-science].md`, generated by `generate-agents.mjs` — and delivered as the subagents' **system prompts**. So there is nothing to build per-run; just determine the values to pass to the workflow:
- `isScience` = `1` when `platform == 'article'`, else `0` (selects the `-science` agent variant).
- `dateFormatted` = the value from Phase A (e.g. `30 травня 2026`); for `--dry-run` use today's date in the `uk` locale. The date can't be baked into a static agent, so the workflow supplies it in the user message.

**Only if `resources/prompts/translate.md`, `analyzer.md`, or `editor.md` changed**, regenerate the agents and then RESTART Claude Code (project agents register at session start):
```bash
node .claude/skills/translation-qa/generate-agents.mjs   # then restart Claude Code
```

## Phase C — Run the workflow (article BAKED in — never passed through the AI)
**Do not put the article in `args`** — that would make you reproduce ~20KB of Ukrainian (same corruption class as base64). Bake the Phase-A file into a runnable workflow copy, then invoke by `scriptPath` with **no args**:

```bash
node .claude/skills/translation-qa/bake-workflow.mjs --source /tmp/tqa-ID-source.json --out /tmp/tqa-ID-run.mjs [--max-cycles N] [--editor-model opus|sonnet] [--translate true|false]
```
```
Workflow({ scriptPath: "/tmp/tqa-ID-run.mjs" })    // NO args — title/content/flags are baked in from source.json
```

`bake-workflow.mjs` reads `source.json`, derives `isScience` (platform=='article'), `language`, `isTranslated`, `dateFormatted`, and inlines them into the workflow's `const A = {…}` via `JSON.stringify` (always valid + byte-exact). The workflow then: if `language != 'uk' && !isTranslated` (or `--translate true`) it invokes `translation-translator[-science]` (Opus) to render Ukrainian first, then loops `translation-analyzer[-science]` (Opus) + `translation-editor[-science]` (Sonnet) BY agentType until **two consecutive «Ні»**. It returns `{ title, content, outcome, cyclesRun, lastAnalysis, appliedCycles, isScience, translated, … }`. **Record the task-output file path** from the completion notification — Phase D needs it.

## Phase D — Present + gated write-back (via the writeback helper — no AI-handled base64)
1. **Preview (writes nothing — purely local, never gated):** run the **inert diff helper**. It writes two
   local files (`/tmp/tqa-ID-orig.txt` from `source.json`'s `publish_*`, `/tmp/tqa-ID-new.txt` from the
   task-output's `.result`) and prints a unified diff — original → corrected:
   ```bash
   node .claude/skills/translation-qa/preview-diff.mjs --id ID --result <TASK_OUTPUT_JSON_PATH> --source /tmp/tqa-ID-source.json
   ```
   Show that diff to the user as the before/after. You never reproduce the text/base64 — the helper does the
   bytes (reads/writes files, byte-exact). It does **zero** remote I/O, so the auto-mode permission gate never
   stops it. (`writeback.mjs` with no `--apply` also previews, but the gate flags it — exact reason unconfirmed;
   it *can* write to prod under `--apply` — so prefer `preview-diff.mjs`.)
2. If `--dry-run` / `--text` / `--no-write`: stop here (never write).
3. **Side-effect note (informational):** the write is a query-builder MASS UPDATE — **no** Eloquent
   events fire, so it does NOT refresh the Telegram review caption and does NOT re-sync `original_*`
   (the English source is preserved). It sets `publish_title`, `publish_content`, `is_translated=1,
   is_deep=1, is_deepest=1, is_auto=0` (fully-processed/terminal — the auto-pipeline won't re-touch or
   re-translate it). Tell the user this, including that the Telegram message keeps the old text until
   they act on it.
4. Ask (AskUserQuestion): **Apply to prod** / **Discard**.
5. On **Apply**, run the SAME helper with `--apply` — it runs the optimistic-locked UPDATE itself, so
   the base64 never passes through you:
   ```bash
   node .claude/skills/translation-qa/writeback.mjs --id ID --result <TASK_OUTPUT_JSON_PATH> --source /tmp/tqa-ID-source.json --apply
   ```
   - It prints affected rows. **0 rows → it ABORTS** ("publish_content changed under you" — optimistic
     lock failed; re-run). On success it confirms `rows=1` + the flags. (This is the read-then-write race guard.)
   - To also refresh the Telegram caption, use the `$model->save()` variant in Notes.

## Notes
- Models: analyzer = **Opus** (the judgment step); editor = **Sonnet** by default (mechanical
  application). Pass `--editor-model opus` to run both on Opus.
- No oscillation/flip-flop/hash tracking by design — the loop repeats until the analyzer returns
  **two consecutive «Ні»** (a single «Ні» triggers a fresh confirmation pass; only the second «Ні»
  converges-clean), bounded by `--max-cycles`.
- The prompts are **baked into the `.claude/agents/translation-*` definitions** as system prompts
  (not read live). When `resources/prompts/translate.md`, `analyzer.md`, or `editor.md` change — or
  when the agents' `disallowedTools` change — re-run `generate-agents.mjs` **and restart Claude
  Code** (agent definitions load only at session start).
- The agents carry `disallowedTools: Read, Write, Edit, NotebookEdit, Bash, Grep, Glob` — they MAY
  **WebSearch/WebFetch** to verify Ukrainian terminology/names against authoritative sources (wanted),
  but cannot read/grep/shell the **codebase** (nonsense for an article task; a tool-equipped analyzer
  was caught doing 6 codebase-adjacent WebSearches + a Bash poke before this restriction).
- **Write-back goes through `writeback.mjs` — the AI never handles base64 or article text.** It reads
  the corrected text from the workflow task-output file + the snapshot from `source.json`, base64-encodes
  IN-SCRIPT (byte-exact), and runs the optimistic-locked write itself. Two modes:
  - **default** (`--apply`): query-builder `News::where(...)->update([...])` — sets `publish_title`,
    `publish_content`, `is_translated=1`, `is_deep=1`, `is_deepest=1`, `is_auto=0`. Fires **no** Eloquent
    events (verified vs Laravel-10: `Eloquent\Builder::update()` runs raw SQL), so it marks the article
    fully-processed/terminal and preserves the English `original_*`, but does **not** refresh the Telegram caption.
  - **caption-refresh** (`--apply --save`): uses `$model->save()` (fires the `News::updated` observer →
    the review caption updates to the final Ukrainian). Sets the same flags; because `is_translated=1` is
    set first, the observer's `original_*` resync branch (only for `language!='uk' && !is_translated`) won't run.
  Both do the optimistic lock on the Phase-A `publish_content` snapshot (0 rows → abort). **Never hand-write
  a base64 `tinker` command yourself** — LLMs corrupt long base64; that is the exact bug the helper removes.
