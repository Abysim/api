#!/usr/bin/env node
// translation-qa Phase D (API edition). Replaces the SSH-stdin writeback with a POST to the prod API
// endpoint /news/{id}/translation. It reads the corrected title/content from the workflow task-output
// file and the optimistic-lock token (`lock`) from the Phase-A source file, then streams the payload to
// curl via STDIN (--data-binary @-) — no ARG_MAX limit, no shell quoting, and the ~58KB body never
// passes through the calling (haiku) agent. ALL verification (auth, optimistic lock, UTF-8, byte-cap,
// flags, analysis_count) is server-side PHP. Default = PREVIEW (writes nothing); --apply performs the POST.
//
// Usage:
//   node post-api.mjs --id <N> --result <task-output.json> --source <source.json> [--apply] [--base-url ...]
//   Token: $TQA_API_TOKEN, else the project-root .env.
//
// Prints ONE line of JSON. --apply success: {ok:true, written:true, http:200, content_md5, bytes,
//   analysis_count, ...}. HTTP 409 = optimistic lock failed (publish_content changed since read) ->
//   NOT written; re-run the skill. Body stays in files; only this small verdict is printed.

import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const argv = process.argv;
const arg = (k, d) => { const i = argv.indexOf(k); return i >= 0 ? argv[i + 1] : d; };
const id = arg('--id');
const resultPath = arg('--result');
const sourcePath = arg('--source');
const apply = argv.includes('--apply');
if (!id || !/^\d+$/.test(id) || !resultPath || !sourcePath) {
  console.error(JSON.stringify({ ok: false, error: 'usage: --id <N> --result <task-output.json> --source <source.json> [--apply]' }));
  process.exit(2);
}
const baseUrl = String(arg('--base-url', process.env.TQA_BASE_URL || 'https://api.abysim.com')).replace(/\/+$/, '');

function readToken() {
  if (process.env.TQA_API_TOKEN && process.env.TQA_API_TOKEN.trim()) return process.env.TQA_API_TOKEN.trim();
  try {
    const envPath = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '.env');
    const m = readFileSync(envPath, 'utf8').match(/^\s*(?:export\s+)?TQA_API_TOKEN\s*=\s*(.*?)\s*$/m);
    if (m) return m[1].trim().replace(/^["']|["']$/g, '');
  } catch { /* ignore */ }
  return '';
}
const TOK = readToken();
if (!TOK) { console.error(JSON.stringify({ ok: false, error: 'no TQA_API_TOKEN (set env or add to project .env)' })); process.exit(2); }

const resJson = JSON.parse(readFileSync(resultPath, 'utf8'));
const r = resJson.result || resJson;                 // task-output wraps the workflow return under .result
const src = JSON.parse(readFileSync(sourcePath, 'utf8'));
const title = r.title, content = r.content, lock = src.lock;
const cycles = (r.cyclesRun ?? r.cycles);            // analyze<->apply iteration count -> analysis_count
const outcome = r.outcome ?? null;
for (const [name, v] of [['result.title', title], ['result.content', content], ['source.lock', lock]]) {
  if (typeof v !== 'string' || v.length === 0) { console.error(JSON.stringify({ ok: false, error: `missing/empty ${name}` })); process.exit(2); }
}

const payload = JSON.stringify({
  title, content, lock,
  ...(Number.isInteger(cycles) ? { cycles: Math.min(cycles, 64) } : {}), // server caps analysis_count at 64
  ...(outcome ? { outcome: String(outcome).slice(0, 64) } : {}),
});
const url = `${baseUrl}/news/${id}/translation`;

if (!apply) {
  console.log(JSON.stringify({
    ok: true, mode: 'preview', id: Number(id), url, lock,
    titleChars: title.length, contentBytes: Buffer.byteLength(content, 'utf8'),
    cycles: Number.isInteger(cycles) ? cycles : null, outcome,
    note: 'PREVIEW — nothing written; re-run with --apply to POST',
  }));
  process.exit(0);
}

// Refuse to autonomously write a NON-CONVERGED result: outcome 'capped' means the loop hit the cycle
// cap with the analyzer STILL finding corrections. The old flow had a human approval gate here; the
// autonomous flow keeps that safety deterministically. --force overrides (writes the capped result).
if (outcome === 'capped' && !argv.includes('--force')) {
  console.error(JSON.stringify({ ok: false, written: false, reason: 'not-converged', outcome: 'capped', error: 'workflow hit the cycle cap WITHOUT converging (no two consecutive «Ні»); refusing autonomous prod write. Re-run with a higher --max-cycles, or pass --force to write the capped result anyway.' }));
  process.exit(1);
}

let out;
try {
  // payload streams via STDIN (--data-binary @-): no ARG_MAX, no shell quoting of «»/—/apostrophes.
  // Response body (small) + trailing http code on stdout.
  out = execFileSync('curl', [
    '-sS', '-w', '\n%{http_code}', '-X', 'POST',
    '-H', `Authorization: Bearer ${TOK}`, '-H', 'Content-Type: application/json', '-H', 'Accept: application/json',
    '--data-binary', '@-', url,
  ], { input: payload, encoding: 'utf8' });
} catch (e) {
  console.error(JSON.stringify({ ok: false, written: false, error: 'curl failed: ' + (e.shortMessage || e.message) })); process.exit(1);
}

const nl = out.lastIndexOf('\n');
const respBody = out.slice(0, nl);
const code = out.slice(nl + 1).trim();
let d; try { d = JSON.parse(respBody); } catch { d = { raw: respBody.slice(0, 200) }; }

if (code === '200' && d && d.ok) {
  // d.content_md5 is the md5 of what the server STORED (after its UTF-8/C0 sanitisation); report it
  // as-is (proof of what landed). We don't compare it to md5(sent) because the server may legitimately
  // strip control bytes, which would differ without being an error.
  console.log(JSON.stringify({ ok: true, written: true, http: 200, ...d, sentLock: lock }));
  process.exit(0);
}
if (code === '409') {
  console.error(JSON.stringify({ ok: false, written: false, http: 409, error: 'optimistic lock failed — publish_content changed since read; re-run the skill', server: d }));
  process.exit(1);
}
console.error(JSON.stringify({ ok: false, written: false, http: code, server: d }));
process.exit(1);
