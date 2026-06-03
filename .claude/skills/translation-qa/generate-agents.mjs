#!/usr/bin/env node
// Generates the translation-qa subagent definitions into .claude/agents/ by copying the REAL
// production analyzer/editor prompts (byte-exact, via NewsController::getPrompt logic) into each
// agent's SYSTEM prompt. The workflow then invokes these by agentType (true system prompts).
//
// Run once now, and RE-RUN whenever resources/prompts/{analyzer,editor}.md change (the agents are
// copies — they go stale otherwise). After (re)generating, RESTART Claude Code so the new/updated
// agents are registered (project agents load at session start).
//
// Creates 4 agents: translation-analyzer[-science], translation-editor[-science].
// The per-article DATE is NOT baked (it varies per article) — the workflow supplies it at runtime
// in the user message; editor.md's {date} placeholder is replaced with a pointer to that.
//
// Usage: node .claude/skills/translation-qa/generate-agents.mjs

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SKILL_DIR = dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = resolve(SKILL_DIR, '..', '..', '..');
const AGENTS_DIR = resolve(PROJECT_ROOT, '.claude/agents');

// Replicate NewsController::getPrompt: trim each line, join \n, trim whole; then isScience subs.
function getPrompt(name, isScience) {
  const raw = readFileSync(resolve(PROJECT_ROOT, 'resources/prompts', name + '.md'), 'utf8');
  let text = raw.split('\n').map((l) => l.trim()).join('\n').trim();
  if (isScience) {
    const from = ['Публіцистичн', 'журналістик', 'журналістськ', 'публіцистичн', 'журналістом'];
    const to = ['Науков', 'наук', 'науков', 'науков', 'вченим'];
    for (let i = 0; i < from.length; i++) text = text.split(from[i]).join(to[i]);
  }
  return text;
}

// editor body = editor.md (science-subbed) with {analyzer_preamble} (sliced + 3rd-person rewrite)
// and {date} placeholders resolved, per ApplyNewsAnalysisJob L88-95.
function buildEditorBody(isScience) {
  const analyzerText = getPrompt('analyzer', isScience);
  const cut = analyzerText.indexOf('1.');
  let preamble = (cut >= 0 ? analyzerText.slice(0, cut) : analyzerText).trim();
  const pf = ['Ти — ', 'Твоя задача', 'Надавай одразу'];
  const pt = ['Це — ', 'Його задача', 'Надає одразу'];
  for (let i = 0; i < pf.length; i++) preamble = preamble.split(pf[i]).join(pt[i]);
  let editorText = getPrompt('editor', isScience);
  editorText = editorText.split('{analyzer_preamble}').join(preamble);
  editorText = editorText.split('{date}').join('(дата статті вказана у повідомленні користувача)');
  return editorText;
}

function frontmatter(name, description, model, effort) {
  // description quoted for YAML safety; body follows the closing ---.
  // Web research IS wanted — verifying Ukrainian terminology / proper names / facts against
  // authoritative sources is legitimate translation QA. Researching the Laravel CODEBASE the agent
  // happens to run inside is nonsense for an article task. So DENY the filesystem/shell/code tools
  // while leaving WebSearch/WebFetch available. A denylist (not an allowlist) is used so the workflow's
  // StructuredOutput tool is never accidentally blocked (an empty allowlist might block it).
  // (2026-05-30: translation-analyzer did 6 WebSearches [OK, kept] + a Bash codebase poke [blocked now].)
  // `effort: max` forces maximum reasoning for the Opus judgment agents (analyzer/translator). The
  // frontmatter `effort` field OVERRIDES the session effort level, so we set it ONLY for Opus and omit
  // it for the Sonnet editor (Sonnet has no xhigh/max). `agent()` has no effort option — frontmatter is
  // the only documented per-agent knob.
  const effortLine = effort ? `effort: ${effort}\n` : '';
  return `---\nname: ${name}\ndescription: "${description.replace(/"/g, '\\"')}"\ndisallowedTools: Read, Write, Edit, NotebookEdit, Bash, Grep, Glob\nmodel: ${model}\n${effortLine}---\n\n`;
}

mkdirSync(AGENTS_DIR, { recursive: true });
const written = [];

for (const sci of [false, true]) {
  const aName = 'translation-analyzer' + (sci ? '-science' : '');
  const aDesc = `Translation-QA analyzer for ${sci ? 'SCIENTIFIC' : 'publicistic'} Ukrainian big-cat articles. Invoked ONLY by the translation-qa workflow via agentType; returns verdict and corrections. Not for general tasks.`;
  const aOut = frontmatter(aName, aDesc, 'opus', 'max') + getPrompt('analyzer', sci) + '\n';
  writeFileSync(resolve(AGENTS_DIR, aName + '.md'), aOut, 'utf8');
  written.push({ name: aName, bytes: Buffer.byteLength(aOut, 'utf8') });

  const eName = 'translation-editor' + (sci ? '-science' : '');
  const eDesc = `Translation-QA editor for ${sci ? 'SCIENTIFIC' : 'publicistic'} Ukrainian big-cat articles. Invoked ONLY by the translation-qa workflow via agentType; mechanically applies the philologist's corrections. Not for general tasks.`;
  const eOut = frontmatter(eName, eDesc, 'sonnet') + buildEditorBody(sci) + '\n';
  writeFileSync(resolve(AGENTS_DIR, eName + '.md'), eOut, 'utf8');
  written.push({ name: eName, bytes: Buffer.byteLength(eOut, 'utf8') });

  const tName = 'translation-translator' + (sci ? '-science' : '');
  const tDesc = `Translation-QA TRANSLATOR for ${sci ? 'SCIENTIFIC' : 'publicistic'} big-cat articles: translates a source-language article INTO Ukrainian (whole article, Markdown out). Invoked ONLY by the translation-qa workflow via agentType when the article is not yet Ukrainian. Not for general tasks.`;
  const tOut = frontmatter(tName, tDesc, 'opus', 'max') + getPrompt('translate', sci) + '\n';
  writeFileSync(resolve(AGENTS_DIR, tName + '.md'), tOut, 'utf8');
  written.push({ name: tName, bytes: Buffer.byteLength(tOut, 'utf8') });
}

console.log(JSON.stringify({ agentsDir: AGENTS_DIR, written }));
