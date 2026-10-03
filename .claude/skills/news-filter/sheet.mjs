#!/usr/bin/env node
// Review sheet for the news-filter skill. Reads dump-pending.php JSON (file argument or stdin) and prints one
// block per pending item, then candidate duplicate pairs among pending items and against recent approved news.
// Hints and pairs only point at candidates; the decisions follow criteria.md.
// Usage: node sheet.mjs <dump.json> > sheet.txt
import { readFileSync } from 'node:fs';

const dump = JSON.parse(readFileSync(process.argv[2] ?? 0, 'utf8'));

const STOP = new Set(`a an the of in on at to for from by with and or but is are was were be been being has have had
    it its this that these those as into after over under new says said say more than about up out not no will can
    could would may might how why what when where who which his her their they them he she we our you your i
    і й та у в на з із зі до від для про що як це за по не чи але або вже ще його її їх він вона вони після під над`
    .split(/\s+/));

// Capitalised words that say nothing about which story an article tells: page furniture, dates, generic parts
// of place and institution names, and the cats themselves.
const GENERIC = new Set([...STOP, ...`also there here some many most all one two three four five first last
    monday tuesday wednesday thursday friday saturday sunday january february march april may june july august
    september october november december jan feb mar apr jun jul aug sep sept oct nov dec
    photo photos image images credit getty reuters read share facebook twitter instagram copyright advertisement
    news video videos watch mr mrs ms dr whatsapp sms email print player play current time copy link linkedin
    reddit pinterest telegram messenger comments comment newsletter login sign menu search home cookie cookies
    privacy policy terms related trending popular latest stories story updated published posted sponsored close
    skip content click follow subscribe google apple android app youtube tiktok threads bluesky mastodon
    zoo zoos park national forest department wildlife service university conservation society trust reserve
    sanctuary fund foundation center centre institute ministry government state county city river lake mountain
    valley island north south east west northern southern eastern western central big cat cats game fish police
    authority council officials residents experts researchers scientists wild
    lion lions lioness tiger tigers leopard leopards snow cheetah cheetahs jaguar jaguars puma pumas cougar cougars
    lynx lynxes bobcat bobcats caracal serval ocelot panther panthers`.split(/\s+/)]);

// Dump fields that mean the user already picked the item.
const PICKED = { is_content_cleaned: 'cleaned', is_translated: 'translated', is_auto: 'auto', queued: 'queued' };

const HINTS = [
    ['wall', /subscribers? only|subscribe to (?:continue|read)|continue reading|already a subscriber|(?:sign|log) in to (?:continue|read)|verify (?:that )?you(?:'re| are) (?:a )?human|enable javascript|are you a robot|access denied|captcha/i],
    ['shop', /stock photo|licensable|royalty[- ]free|add to (?:cart|basket)|buy now|free shipping|\$\d+\.\d\d\b/i],
    ['promo', /sponsored content|book now|itinerary|\btickets?\b|event details|date and time|join us|podcast|episode|\bregister\b|квитк|на правах реклами|партнерський матеріал/i],
];

const plain = (s) => String(s ?? '')
    .replace(/<[^>]+>/g, ' ')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/https?:\/\/\S+/g, ' ')
    .replace(/&nbsp;|&#160;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#0?39;|&apos;/g, "'")
    // Emails, handles, hashtags and bare domains spell names in lower case.
    .replace(/[\w.+-]{1,64}@[\w-]+(?:\.[\w-]+)+/g, ' ')
    .replace(/(?<![\w&])[@#][\p{L}\p{N}_]+/gu, ' ')
    .replace(/\b(?:[a-z0-9-]{1,63}\.){1,8}(?:com|org|net|gov|edu|info|news|io|tv|ua|uk|au|za)\b/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
const words = (s) => s.toLowerCase().match(/[\p{L}\p{N}]+/gu) ?? [];
const titleSet = (s) => new Set(words(String(s ?? '')).filter((w) => w.length > 2 && !STOP.has(w)));
const host = (url) => {
    try {
        return new URL(url).host.replace(/^www\./, '');
    } catch {
        return '?';
    }
};
const day = (s) => String(s ?? '').slice(0, 10) || '?';
// The article's own date when known; "keep the older item" compares these.
const age = (item) => (item.date ? `pub ${day(item.date)}` : `added ${day(item.created_at)}`);

const shingles = (w) => {
    const out = new Set();
    for (let i = 0; i + 2 < w.length; i++) out.add(`${w[i]} ${w[i + 1]} ${w[i + 2]}`);
    return out;
};
const common = (a, b) => {
    const [small, big] = a.size < b.size ? [a, b] : [b, a];
    let n = 0;
    for (const x of small) if (big.has(x)) n++;
    return n;
};
const jaccard = (a, b) => (a.size && b.size ? common(a, b) / (a.size + b.size - common(a, b)) : 0);
const containment = (part, whole) => (part.size ? common(part, whole) / part.size : 0);

const pendingTexts = dump.pending.map((p) => plain(p.content));
const recentLedes = dump.recent.map((r) => plain(r.lede));

// Words seen in lower case in two or more documents are ordinary words even when capitalised ("Live", "Save").
// One document is not enough: a cub named Hope stays a name when another article says "we hope".
const lowerDocs = new Map();
for (const text of [...pendingTexts, ...recentLedes]) {
    for (const word of new Set(Array.from(text.matchAll(/(?<![\p{L}\p{N}])\p{Ll}\p{L}*/gu), (m) => m[0]))) {
        lowerDocs.set(word, (lowerDocs.get(word) ?? 0) + 1);
    }
}

// Proper names: capitalised words that are not ordinary words and do not open a sentence ("Dr. Okafor" does not).
// Taken from the body only: headlines capitalise every word.
const names = (text) => {
    const out = new Set();
    for (const m of text.matchAll(/(?<![\p{L}\p{N}])\p{Lu}\p{L}{2,}/gu)) {
        const name = m[0].toLowerCase();
        const before = text.slice(Math.max(0, m.index - 6), m.index);
        const opensSentence = /(?:^|[.!?:"“”«»])\s*$/.test(before) && !/\b(?:Mr|Mrs|Ms|Dr|Prof|St)\.\s*$/.test(before);
        if (!GENERIC.has(name) && (lowerDocs.get(name) ?? 0) < 2 && !opensSentence) out.add(name);
    }
    return out;
};

const items = dump.pending.map((p, i) => {
    const text = pendingTexts[i];
    const w = words(text);
    const hints = Object.entries(PICKED).filter(([field]) => p[field]).map(([, flag]) => flag);
    const touched = hints.length > 0;
    if (w.length < 150) hints.push('thin');
    if (/kAm.*?k\^Am/s.test(text)) hints.push('rot47');
    for (const [name, re] of HINTS) {
        const m = text.match(re);
        if (m) hints.push(`${name}(${m[0].toLowerCase()})`);
    }
    if (`${p.title ?? ''} ${text.slice(0, 600)}`.match(/\bzoos?\b|zoological|safari park|wildlife park|зоопарк/i)) {
        hints.push('zoo');
    }
    return { ...p, text, count: w.length, hints, touched, titleSet: titleSet(p.title), shingles: shingles(w), names: names(text) };
});

const recent = dump.recent.map((r, i) => {
    const lede = recentLedes[i];
    return {
        ...r,
        titleSet: titleSet(r.title),
        publishTitleSet: titleSet(r.publish_title),
        shingles: shingles(words(lede)),
        names: names(lede),
    };
});

const df = new Map();
for (const doc of [...items, ...recent]) for (const n of doc.names) df.set(n, (df.get(n) ?? 0) + 1);
const rareMax = Math.max(6, Math.ceil(items.length / 10));
const sharedRare = (a, b) => [...a].filter((n) => b.has(n) && df.get(n) <= rareMax);

const pairs = [];
for (let i = 0; i < items.length; i++) {
    for (let j = i + 1; j < items.length; j++) {
        const [a, b] = [items[i], items[j]];
        const title = jaccard(a.titleSet, b.titleSet);
        const text = jaccard(a.shingles, b.shingles);
        const shared = sharedRare(a.names, b.names);
        const why = [];
        if (title >= 0.3) why.push(`title ${title.toFixed(2)}`);
        if (text >= 0.03) why.push(`text ${text.toFixed(3)}`);
        if (shared.length >= 2) why.push(`names ${shared.slice(0, 6).join(', ')}`);
        if (why.length) pairs.push({ a, b, why, score: why.length + title + text * 5 });
    }
}

const repeats = [];
for (const p of items) {
    for (const r of recent) {
        const title = Math.max(jaccard(p.titleSet, r.titleSet), jaccard(p.titleSet, r.publishTitleSet));
        const text = r.shingles.size >= 20 ? containment(r.shingles, p.shingles) : 0;
        const shared = sharedRare(p.names, r.names);
        const why = [];
        if (title >= 0.3) why.push(`title ${title.toFixed(2)}`);
        if (text >= 0.15) why.push(`lede in text ${text.toFixed(2)}`);
        if (shared.length >= 2) why.push(`names ${shared.slice(0, 6).join(', ')}`);
        if (why.length) repeats.push({ p, r, why, score: why.length + title + text });
    }
}

const out = [`pending: ${items.length} | recent approved/published/processing: ${recent.length}`];
const touched = items.filter((p) => p.touched);
if (touched.length) {
    out.push(`picked by the user (cleaned, translated, auto or queued), leave alone: ${touched.map((p) => `#${p.id}`).join(' ')}`);
}

for (const p of items) {
    const species = [].concat(p.species ?? []).join(',');
    out.push('', `#${p.id} | pub ${day(p.date)} | added ${day(p.created_at)} | ${p.language} | ${host(p.link)} | ${species} | ${p.count}w${p.hints.length ? ` | ${p.hints.join(' ')}` : ''}`);
    out.push(`  title: ${p.title}`);
    if (p.publish_title && p.publish_title !== p.title) out.push(`  publish: ${p.publish_title}`);
    out.push(`  head: ${p.text.slice(0, 300)}`);
    if (p.text.length > 300) out.push(`  tail: …${p.text.slice(-160)}`);
}

const limit = Math.max(100, items.length * 2);
pairs.sort((x, y) => y.score - x.score);
out.push('', `possible duplicates among pending, strongest first (${pairs.length}):`);
for (const { a, b, why } of pairs.slice(0, limit)) out.push(`  #${a.id} (${age(a)}) ~ #${b.id} (${age(b)}): ${why.join('; ')}`);
if (pairs.length > limit) out.push(`  … ${pairs.length - limit} weaker pairs not shown`);

repeats.sort((x, y) => y.score - x.score);
out.push('', `possible repeats of recent approved/published news, strongest first (${repeats.length}):`);
for (const { p, r, why } of repeats.slice(0, limit)) {
    out.push(`  #${p.id} ~ #${r.id} (status ${r.status}, added ${day(r.created_at)}): ${why.join('; ')} | ${r.title}`);
}
if (repeats.length > limit) out.push(`  … ${repeats.length - limit} weaker pairs not shown`);

console.log(out.join('\n'));
