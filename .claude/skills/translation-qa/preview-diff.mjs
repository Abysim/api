#!/usr/bin/env node
// Local, INERT before/after preview for translation-qa Phase D. Replaces the writeback.mjs preview:
// the auto-mode permission gate stops `writeback.mjs` even with no `--apply`. The exact reason is
// unconfirmed (likely conservative gating of a node script that CAN write to prod under `--apply`),
// but the no-op preview still required manual approval, which made it unusable in an autonomous run.
//
// This script does ZERO remote I/O: it reads two local JSON files, writes two local .txt files, and
// runs `diff` — so it never trips the gate. The article bytes still never pass through the AI (the
// script reads/writes the text from files; the LLM only passes --id + the file paths).
//
// Usage:
//   node preview-diff.mjs --id <N> --source <phaseA-source.json> --result <task-output.json>
//     -> writes /tmp/tqa-<N>-orig.txt (from source.publish_*) and /tmp/tqa-<N>-new.txt (from result),
//        then prints a unified diff (original -> corrected). Writes NOTHING remote, ever.
//
//   --source : the Phase-A read JSON (`.publish_title` / `.publish_content` = the originals)
//   --result : the workflow's task-output JSON (`.result.title` / `.result.content`, or top-level)

import { readFileSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const argv = process.argv;
const arg = (k) => { const i = argv.indexOf(k); return i >= 0 ? argv[i + 1] : undefined; };
const id = arg('--id');
const sourcePath = arg('--source');
const resultPath = arg('--result');
if (!id || !/^\d+$/.test(id) || !sourcePath || !resultPath) {
  console.error('usage: node preview-diff.mjs --id <N> --source <source.json> --result <task-output.json>');
  process.exit(2);
}

const src = JSON.parse(readFileSync(sourcePath, 'utf8'));
const resJson = JSON.parse(readFileSync(resultPath, 'utf8'));
const r = resJson.result || resJson;          // task-output wraps the workflow return under .result

for (const [name, v] of [['source.publish_content', src.publish_content], ['result.content', r.content]]) {
  if (typeof v !== 'string' || v.length === 0) { console.error(`missing/empty ${name}`); process.exit(2); }
}
const orig = (src.publish_title || '') + '\n\n' + src.publish_content;
const corrected = (r.title || '') + '\n\n' + r.content;

const origPath = `/tmp/tqa-${id}-orig.txt`;
const newPath = `/tmp/tqa-${id}-new.txt`;
writeFileSync(origPath, orig, 'utf8');
writeFileSync(newPath, corrected, 'utf8');

console.log(`=== LOCAL PREVIEW (inert — no remote I/O, nothing written to prod) — article ${id} ===`);
console.log(`orig: ${origPath} (${orig.length} chars)   new: ${newPath} (${corrected.length} chars)`);
console.log('--- unified diff: original publish_* -> corrected (open the two files for a full side-by-side) ---');
try {
  const out = execFileSync('diff', ['-u', '--label', `orig/${id}`, '--label', `new/${id}`, origPath, newPath], { encoding: 'utf8' });
  console.log(out.trim() ? out : '(identical — the loop made no net change)');
} catch (e) {
  // `diff` exits 1 when the files differ — the NORMAL case here, not an error (exit 2 = real trouble).
  if (e.status === 1 && typeof e.stdout === 'string') process.stdout.write(e.stdout);
  else { console.error('diff failed (status ' + (e.status ?? '?') + '): ' + e.message); process.exit(1); }
}
