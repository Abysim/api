#!/usr/bin/env node
// Writes the translation-qa workflow result back to the prod DB WITHOUT base64 and WITHOUT putting the
// article text on the ssh command line. Two earlier failure modes drove this design:
//   1. base64 of a large article, embedded TWICE in one ssh argument (the optimistic-lock snapshot in the
//      WHERE + the new value in the SET), exceeded Linux MAX_ARG_STRLEN (~128 KB per single argument)
//      -> `spawnSync ssh E2BIG`: the kernel refused to launch ssh, so the write never even ran.
//   2. That E2BIG crash dumped the giant base64 blob into the agent context -> Anthropic usage-policy flag.
// Fix: stream a small JSON payload {title, content, snapshot} over SSH STDIN into a temp file on bigcats;
// a tiny, content-free `tinker --execute` reads the file, runs the optimistic-locked UPDATE, then rm.
//   - stdin has no ARG_MAX limit            -> works at ANY article size.
//   - no base64                             -> nothing opaque can enter the agent context.
//   - stdin bypasses the shell entirely     -> no quoting of Ukrainian apostrophes/«»/—; JSON carries bytes exactly.
//   - the AI still never handles the bytes  -> this script reads them from files and pipes them itself.
//
// Usage:
//   node writeback.mjs --id <N> --result <task-output.json> --source <phaseA-source.json> [--apply] [--save]
//     (default = PREVIEW: prints the new title + lengths + the (small) remote command; writes NOTHING)
//     (--apply = stream payload + run the optimistic-locked UPDATE: publish_* + is_translated/is_deep/is_deepest=1, is_auto=0)
//     (--save  = use $model->save() instead of query-builder update() -> fires News::updated observer -> Telegram caption refresh)
//
//   --result : the workflow's task-output JSON (`.result.title` / `.result.content`, or top-level title/content)
//   --source : the Phase-A read JSON (`.publish_content` = the optimistic-lock snapshot)

import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const argv = process.argv;
const arg = (k) => { const i = argv.indexOf(k); return i >= 0 ? argv[i + 1] : undefined; };
const id = arg('--id');
const resultPath = arg('--result');
const sourcePath = arg('--source');
const apply = argv.includes('--apply');
const save = argv.includes('--save'); // --save: $model->save() (fires News::updated observer -> Telegram caption refresh) instead of the observer-free query-builder update()
if (!id || !/^\d+$/.test(id) || !resultPath || !sourcePath) {
  console.error('usage: node writeback.mjs --id <N> --result <task-output.json> --source <source.json> [--apply] [--save]');
  process.exit(2);
}

const resJson = JSON.parse(readFileSync(resultPath, 'utf8'));
const r = resJson.result || resJson;          // task-output wraps the workflow return under .result
const title = r.title;
const content = r.content;
const snapshot = JSON.parse(readFileSync(sourcePath, 'utf8')).publish_content;
for (const [name, v] of [['result.title', title], ['result.content', content], ['source.publish_content', snapshot]]) {
  if (typeof v !== 'string' || v.length === 0) { console.error(`missing/empty ${name}`); process.exit(2); }
}

// Payload travels via SSH stdin (no size limit, no shell, no base64). `cat` drains stdin into a temp file
// BEFORE tinker runs, so tinker never touches stdin. The remote --execute is small and content-free: it
// reads the temp file, json_decodes, and runs the optimistic-locked write. Only double-quotes inside the
// PHP (no single quotes) so it is safe within the single-quoted --execute='...'.
const payload = JSON.stringify({ title, content, snapshot });
const tmp = `/tmp/tqa-${id}-wb.json`;
const php = save
  ? `$d=json_decode(file_get_contents("${tmp}"),true); $n=\\App\\Models\\News::find(${id}); if(!$n){echo "0";} elseif($n->publish_content!==$d["snapshot"]){echo "0";} else {$n->publish_title=$d["title"]; $n->publish_content=$d["content"]; $n->is_translated=1; $n->is_deep=1; $n->is_deepest=1; $n->is_auto=0; $n->save(); echo "1";}`
  : `$d=json_decode(file_get_contents("${tmp}"),true); echo \\App\\Models\\News::where("id",${id})->where("publish_content",$d["snapshot"])->update(["publish_title"=>$d["title"],"publish_content"=>$d["content"],"is_translated"=>1,"is_deep"=>1,"is_deepest"=>1,"is_auto"=>0]);`;
// One ssh call: cat stdin -> temp file, run the small tinker, then remove the temp file regardless.
const remote = `cat > ${tmp} && cd ~/api && php artisan tinker --execute='${php}' ; rm -f ${tmp}`;

if (!apply) {
  console.log('=== PREVIEW — nothing written (run again with --apply to write) ===');
  console.log('id: ' + id);
  console.log('new title: ' + title);
  console.log('new content: ' + content.length + ' chars   snapshot (optimistic-lock): ' + snapshot.length + ' chars   payload (stdin): ' + Buffer.byteLength(payload, 'utf8') + ' bytes');
  console.log('\n--- remote command (payload streams via stdin; NO base64, NO content on the command line) ---');
  console.log('ssh bigcats ' + JSON.stringify(remote) + '   < (JSON payload on stdin)');
  console.log('\n(For a full before/after diff, use preview-diff.mjs.)');
  process.exit(0);
}

// --apply: stream the payload over stdin and run the optimistic-locked write. Wrapped so any failure prints
// a SHORT message — the command itself carries no payload, so an error can never leak a large blob.
let out;
try {
  out = execFileSync('ssh', ['bigcats', remote], { input: payload, encoding: 'utf8' });
} catch (e) {
  console.error('ssh write failed (exit ' + (e.status ?? '?') + '): ' + (e.shortMessage || e.message));
  if (typeof e.stdout === 'string' && e.stdout.trim()) console.error('stdout: ' + e.stdout.trim());
  process.exit(1);
}
process.stdout.write(out);
const rows = (out.match(/(\d+)\s*$/) || [])[1];
if (rows !== '1') {
  console.error('\nABORT: affected rows = ' + (rows ?? '?') + ' (expected 1). Either the article was not found, or its publish_content changed under you (optimistic lock failed). Re-run the skill.');
  process.exit(1);
}
console.log('\nOK: wrote article ' + id + ' (rows=1) via stdin payload; flags is_translated/is_deep/is_deepest=1, is_auto=0 set.' + (save ? ' (--save: observer fired → caption refresh)' : ''));
