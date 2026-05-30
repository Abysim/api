#!/usr/bin/env node
// Writes the translation-qa workflow result back to the prod DB WITHOUT the AI ever reproducing the
// article text or base64. LLMs corrupt long base64 / long text (no semantic anchor — pure char copy),
// which is exactly what broke the earlier "AI pastes base64 into the tinker command" approach.
//
// Here the AI only passes FILE PATHS + the id; this script reads the text from files, base64-encodes
// IN-SCRIPT (reliable), and runs the optimistic-locked ssh write itself. base64 is still the transport
// (it dodges shell-quoting of Ukrainian apostrophes/guillemets) — it just never passes through the AI.
//
// Usage:
//   node writeback.mjs --id <N> --result <task-output.json> --source <phaseA-source.json> [--apply]
//     (default = PREVIEW: prints decoded title/content + the exact command, writes NOTHING)
//     (--apply = run the optimistic-locked UPDATE: publish_* + is_translated/is_deep/is_deepest=1, is_auto=0)
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
const save = argv.includes('--save'); // --save: use $model->save() (fires News::updated observer → Telegram caption refresh) instead of the observer-free query-builder update()
if (!id || !/^\d+$/.test(id) || !resultPath || !sourcePath) {
  console.error('usage: node writeback.mjs --id <N> --result <task-output.json> --source <source.json> [--apply]');
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

const b64 = (s) => Buffer.from(s, 'utf8').toString('base64');
// php uses only double-quotes + base64 ([A-Za-z0-9+/=]) → safe inside a single-quoted --execute='...'.
// Default: query-builder update() (observer-free). --save: $model->save() (fires the observer →
// Telegram caption refresh). Both: optimistic-lock on the Phase-A snapshot + set the four flags.
// Both echo a trailing affected-rows count ("1" ok / "0" lock-miss-or-not-found) for the rows check below.
const php = save
  ? `$n=\\App\\Models\\News::find(${id}); if(!$n){echo "0";} elseif($n->publish_content!==base64_decode("${b64(snapshot)}")){echo "0";} else {$n->publish_title=base64_decode("${b64(title)}"); $n->publish_content=base64_decode("${b64(content)}"); $n->is_translated=1; $n->is_deep=1; $n->is_deepest=1; $n->is_auto=0; $n->save(); echo "1";}`
  : `echo \\App\\Models\\News::where("id",${id})->where("publish_content", base64_decode("${b64(snapshot)}"))->update(["publish_title"=>base64_decode("${b64(title)}"),"publish_content"=>base64_decode("${b64(content)}"),"is_translated"=>1,"is_deep"=>1,"is_deepest"=>1,"is_auto"=>0]);`;
const remote = `cd ~/api && php artisan tinker --execute='${php}'`;

if (!apply) {
  console.log('=== PREVIEW — nothing written (run again with --apply to write) ===');
  console.log('id: ' + id);
  console.log('snapshot (optimistic-lock) length: ' + snapshot.length + ' chars');
  console.log('\n--- NEW TITLE ---\n' + title);
  console.log('\n--- NEW CONTENT ---\n' + content);
  console.log('\n--- exact write command (base64 computed in-script; sets publish_* + is_translated/is_deep/is_deepest=1, is_auto=0) ---');
  console.log(`ssh bigcats "${remote.replace(/"/g, '\\"')}"`);
  process.exit(0);
}

// --apply: run the optimistic-locked write. ssh invoked via execFileSync (no local shell) so nothing mangles it.
const out = execFileSync('ssh', ['bigcats', remote], { encoding: 'utf8' });
process.stdout.write(out);
const rows = (out.match(/(\d+)\s*$/) || [])[1];
if (rows === '0') {
  console.error('\nABORT: 0 rows affected — the article’s publish_content changed under you (optimistic lock failed). Re-run the skill.');
  process.exit(1);
}
console.log('\nOK: wrote article ' + id + ' (rows=' + (rows ?? '?') + '), flags is_translated/is_deep/is_deepest=1, is_auto=0 set.');
