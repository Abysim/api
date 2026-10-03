---
name: news-filter
description: >-
  Filter the news review queue on prod, up to the point where translation starts: decline pending news older
  than 7 days, then review the rest against criteria.md (off-topic → 9; duplicates, pages without a usable
  article and ads → 4). Applies clear cases itself and asks the user only about doubtful ones, in one message.
  Trigger on "news-filter", "filter the pending news", "clean up the review queue".
---

# news-filter

Run steps 0–6 in order, from the project root (`/DATA/xampp/htdocs/api`). The decision rules are in
`.claude/skills/news-filter/criteria.md`: read it at the start of every run. Everything runs on prod (bigcats):
`run-remote.sh` pipes one of this skill's PHP scripts to `php` in `~/api` and deletes it afterwards, so nothing
is deployed. The skill ends where translation starts: never clean, translate, approve or publish.

Invoking the skill is the user's approval for the remote commands in steps 0–5 and for the writes on clear
cases (steps 1 and 4), and for nothing else. That is the user's answer of 2026-10-03, when asked whether the skill
should apply clear cases itself: "ask only about doubtful, we still have many that i need remove manually, if ask
all - it too much". It makes this skill an exception to the project CLAUDE.md rule on bigcats commands. Doubtful
items wait for the user's answer. If the request came from Telegram, every message to the user goes through the
Telegram reply tool.

`remote X …` below means `bash .claude/skills/news-filter/run-remote.sh X …`. Before step 1, run
`date -u +%Y-%m-%d-%H%M` once and use that literal value as `<run>` in every step. `<tmp>` is the session
scratchpad.

## Steps
0. **Follow-up**: `remote followup.php`. Only its groups "kept, user rejected" and "declined by the skill,
   reversed by user" show where criteria.md and the user disagreed; the other groups are agreements, or changes
   the user did not make.
   For each pattern that covers two or more items in those two groups, draft a concrete criteria.md edit for the
   step-5 message.
1. **Old news**: `remote clear-old.php` prints the count, then `remote clear-old.php --run=<run> --apply` moves
   every pending item created more than 7 days ago to 4, except items the user picked (cleaned, translated,
   auto-translating or with a queued job; their ids are printed, for the report). This is a builder update;
   review captions are left as they are.
2. **Sheet**: `remote dump-pending.php > <tmp>/news-filter-<run>.json`, then
   `node .claude/skills/news-filter/sheet.mjs <tmp>/news-filter-<run>.json > <tmp>/news-filter-<run>.txt`.
   Read the whole .txt with the Read tool, paging with offset/limit when it is long (Bash truncates long output).
   One item in full: `jq '.pending[] | select(.id==<id>)' <tmp>/news-filter-<run>.json`.
3. **Classify** every item per criteria.md: off-topic (9), duplicate (4), no usable article (4), ad (4), keep,
   or doubtful. Items the sheet lists as picked by the user stay as they are and go in no list. The sheet's
   hints and pairs only point at candidates; confirm each one by reading.
4. **Apply clear cases**: write `<tmp>/news-filter-<run>-payload.json` as
   `{"decisions": {"<id>": {"to": 4|9, "step": "offtopic|duplicate|no-text|ad", "reason": "<80 chars>"}}, "kept": [<ids judged keep>]}`.
   `remote apply.php --run=<run> --payload=$(base64 -w0 <payload file>)` is a dry run; check its output. Then run
   the same command with `--apply` added, with Bash timeout 600000 (about 2 s per row).
5. **One message**, only if there is something to ask: the doubtful items (id, title, one line on why) and the
   criteria.md edits drafted in step 0. Wait for the answer, then apply it like step 4 (dry run, then `--apply`)
   on the same `<run>`. Declines the user confirmed get the matching `step` and a reason starting with `user:`.
   Doubtful items the user kept go in `kept`, which adds to the earlier list. Edit criteria.md as agreed.
6. **Report**: counts and ids per step, kept items with titles, what was asked and answered, criteria.md changes.

## Gotchas
- The user works in the review bot at the same time. `apply.php` re-checks each row and skips anything that is
  no longer pending or that the user picked in the meantime.
- Exit 137 means the script was killed (SIGKILL), most likely by the hosting process killer. Re-run the same
  command; every script is safe to repeat. Any other non-zero exit (1 script error or bad arguments, 2 refused by `run-remote.sh`,
  3 `php -l` failed on bigcats): read stderr before doing anything else.
- Review messages older than 48h cannot be deleted. Their caption still updates, and the output says
  `review message kept`.
- Undo is a prod write, so ask first. The run file `~/api/storage/app/news-filter/<run>.json` on bigcats lists
  the ids. Step 1 (`old.ids`) kept its review messages, so set the status back:
  `News::whereIn('id', $ids)->where('status', 4)->update(['status' => 3])`. Steps 4–5 (`decisions`):
  `app(NewsController::class)->restore($news)`, which posts a new review message.
