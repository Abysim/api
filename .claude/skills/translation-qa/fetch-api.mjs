#!/usr/bin/env node
// translation-qa Phase A (API edition). Replaces the SSH-tinker read with a GET to the prod API
// endpoint /news/{id}/translation. curl writes the response BODY straight to a file; this script then
// reads it only to validate + print a SMALL scalar summary. The ~58KB article body therefore never
// passes through the calling (haiku) agent's output — exactly like the old SSH-to-file design.
// All verification (auth, existence) is server-side PHP.
//
// Usage:
//   node fetch-api.mjs --id <N> [--base-url https://api.abysim.com] [--out /tmp/tqa-<N>-source.json]
//   Token: $TQA_API_TOKEN, else the project-root .env (TQA_API_TOKEN=...).
//
// Prints ONE line of JSON: {ok:true, id, out, platform, language, is_translated, lock, dateFormatted,
//   titleChars, contentBytes}. On failure prints {ok:false, error, ...} and exits non-zero.
// The `out` file is the canonical input for bake-workflow.mjs (Phase C) and post-api.mjs (Phase D);
// it already contains `lock` (the md5 optimistic-lock token computed server-side).

import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const argv = process.argv;
const arg = (k, d) => { const i = argv.indexOf(k); return i >= 0 ? argv[i + 1] : d; };
const id = arg('--id');
if (!id || !/^\d+$/.test(id)) { console.error(JSON.stringify({ ok: false, error: 'missing/invalid --id (digits only)' })); process.exit(2); }
const baseUrl = String(arg('--base-url', process.env.TQA_BASE_URL || 'https://api.abysim.com')).replace(/\/+$/, '');
const out = arg('--out', `/tmp/tqa-${id}-source.json`);

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

const url = `${baseUrl}/news/${id}/translation`;
let code;
try {
  // curl writes the body to `out`; %{http_code} comes back on stdout. Body never enters this process'
  // stdout, never the agent. -s silent, -S show errors, --fail-with-body keeps the error JSON in `out`.
  code = execFileSync('curl', [
    '-sS', '-o', out, '-w', '%{http_code}',
    '-H', `Authorization: Bearer ${TOK}`, '-H', 'Accept: application/json', url,
  ], { encoding: 'utf8' }).trim();
} catch (e) {
  console.error(JSON.stringify({ ok: false, error: 'curl failed: ' + (e.shortMessage || e.message) })); process.exit(1);
}

let d;
try { d = JSON.parse(readFileSync(out, 'utf8')); } catch { d = null; }
if (code !== '200') {
  console.error(JSON.stringify({ ok: false, error: 'HTTP ' + code, server: d })); process.exit(1);
}
if (!d || typeof d.publish_content !== 'string' || d.publish_content.length === 0) {
  console.error(JSON.stringify({ ok: false, error: 'empty/missing publish_content', keys: d ? Object.keys(d) : null })); process.exit(1);
}
if (typeof d.lock !== 'string' || d.lock.length !== 32) {
  console.error(JSON.stringify({ ok: false, error: 'missing/invalid lock token in response' })); process.exit(1);
}
console.log(JSON.stringify({
  ok: true, id: Number(id), out,
  platform: d.platform, language: d.language, is_translated: !!d.is_translated,
  lock: d.lock, dateFormatted: d.dateFormatted,
  titleChars: (d.publish_title || '').length, contentBytes: Buffer.byteLength(d.publish_content, 'utf8'),
}));
