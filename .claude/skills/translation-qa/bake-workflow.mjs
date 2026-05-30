#!/usr/bin/env node
// Bakes the Phase-A article (already saved to a file) into a runnable copy of workflow.mjs, so the AI
// never reproduces the ~20KB Ukrainian article into the Workflow `args` (LLMs corrupt long text — the
// same failure class as pasting base64). The AI runs this with the SOURCE FILE PATH, then invokes
// Workflow({ scriptPath: <out> }) with NO args. Escaping is via JSON.stringify (always valid JS).
//
// Usage:
//   node bake-workflow.mjs --source <phaseA-source.json> --out <run.mjs> \
//        [--max-cycles N] [--editor-model opus|sonnet] [--translator-model opus|sonnet] [--translate true|false]
//
// source.json = Phase-A read: { publish_title, publish_content, platform, dateFormatted, language, is_translated, ... }

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const argv = process.argv;
const arg = (k, d) => { const i = argv.indexOf(k); return i >= 0 ? argv[i + 1] : d; };
const sourcePath = arg('--source');
const outPath = arg('--out');
if (!sourcePath || !outPath) {
  console.error('usage: node bake-workflow.mjs --source <source.json> --out <run.mjs> [--max-cycles N] [--editor-model M] [--translator-model M] [--translate true|false]');
  process.exit(2);
}

const s = JSON.parse(readFileSync(sourcePath, 'utf8'));
if (typeof s.publish_content !== 'string' || s.publish_content.length === 0) {
  console.error('source has no publish_content'); process.exit(2);
}

const A = {
  title: s.publish_title || '',
  content: s.publish_content,
  isScience: s.platform === 'article',
  dateFormatted: s.dateFormatted || '',
  language: s.language || '',
  isTranslated: !!s.is_translated,
  maxCycles: Number(arg('--max-cycles', s.platform === 'article' ? 32 : 16)),
  editorModel: arg('--editor-model', 'sonnet'),
  translatorModel: arg('--translator-model', 'opus'),
};
const t = arg('--translate');
if (t === 'true') A.translate = true; else if (t === 'false') A.translate = false;

const SKILL_DIR = dirname(fileURLToPath(import.meta.url));
let wf = readFileSync(resolve(SKILL_DIR, 'workflow.mjs'), 'utf8');

// Replace the args-parsing line with a baked literal (JSON.stringify = valid JS string escaping).
const ARGS_LINE = /const A = typeof args === 'string' \? JSON\.parse\(args\) : \(args \|\| \{\}\);/;
if (!ARGS_LINE.test(wf)) { console.error('could not find the args-parse line in workflow.mjs to bake into'); process.exit(3); }
wf = wf.replace(ARGS_LINE, 'const A = ' + JSON.stringify(A) + '; // baked by bake-workflow.mjs — no AI text reproduction');

writeFileSync(outPath, wf, 'utf8');
console.log(JSON.stringify({ out: outPath, isScience: A.isScience, translate: A.translate, titleChars: A.title.length, contentChars: A.content.length, bakedBytes: Buffer.byteLength(wf, 'utf8') }));
