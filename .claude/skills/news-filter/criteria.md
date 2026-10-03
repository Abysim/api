# news-filter criteria

The rules the skill applies. Decide per item by reading its head and tail in the sheet. The sheet's hints point
at candidates; they never decide on their own. Change this file only after the user agrees, and record the case
that prompted the change under the matching rule.

Statuses: 3 PENDING_REVIEW, 4 REJECTED_MANUALLY, 9 REJECTED_AS_OFF_TOPIC. An item that is off-topic and also fails
another check goes to 9.

## 1. Older than 7 days → 4

"I did not have time to publish news, let's clear all pending that one week later and older", then "mark rejected
manually" (user). Age counts from when the item was fetched (`created_at`), not from the article's date.
Mechanical, no judgement. The skill leaves alone items the user picked (cleaned, translated, auto-translating, or
with a queued job): a safeguard of the skill, not the user's words.

## 2. Off-topic → 9

"news that clearly not about big cats" (user). The news search covers lion, tiger, leopard, jaguar, cheetah,
panther, puma/cougar, lynx, ocelot, caracal and serval (`resources/json/news/species/en.json`). Other wild cats
(bobcat, margay, Pallas's cat and the like) count as on-topic too. That is the skill's reading, not the user's
words, so lean keep.

- Off-topic: house cats and domestic breeds (Highland Lynx is a cat breed), artwork, prints, posters,
  drawings and paintings, 3D models, product pages (clothes, toys, plants or cars named after a cat),
  games and puzzles, word lists, taxidermy for sale, AI-generated images, sports teams named after cats.
- Only clear cases. Mentions a wild cat in passing but is about something else: doubtful, ask.

## 3. Duplicates and pages without a usable article → 4

Duplicates ("decline manually of course duplicates", user):
- One item per story, across pending items and the sheet's recent news (approved, published or being translated,
  last 21 days). If the story is among the recent news, decline every pending copy.
- If the user picked one copy (cleaned, translated, auto-translating or queued), that copy stays; decline the
  others.
- Otherwise keep the older item, since it is the source. Prefer a later copy only when the older one is clearly
  less informative: a video page, a teaser, a stub. A later copy can be longer and still be AI slop.
- Same story in Ukrainian and another language: keep the Ukrainian item, since it needs no translation
  (2026-10-03: ua.news Prague cheetah cubs kept and later approved by the user; its English version declined).
- Same story means the same event. Reworded headlines are common. The loader's own de-duplication only
  compares headlines (similar_text 80% before fetching, 70% after saving), so these reach review.

No usable article ("if there no full text, decline manually too", user):
- Paywall teaser, sign-in or bot-check wall, cookie or newsletter wall with no story.
- Page with only footer or navigation links. Stock-photo, licensing or print-shop page. Video player with no
  text. OCR garbage.
- Text cut off mid-story. `kAm…k^Am` blocks are ROT47-scrambled paywall text (TownNews sites): readable after
  decoding, but the stored copy was incomplete; declined at the user's instruction.

## 4. Ads that are not news → 4

"advertisment articles that are not news are not needed too" (user). Declined so far, with the user's OK:
- tour or safari itinerary pages, safari company listicles with booking pitches
- event promos (zoo Halloween event marked "Sponsored Content"), event listings (a library film screening)
- podcast episode pages
- brand-partnership features (perfume brand × WWF)

Zoos: "if therre some event in zoo, keep it, but if it only add for zoo, we not need it" (user). Keep a story about
something that happened (arrival, birth, death, illness, escape, rescue, a celebration that took place). Decline
a page that only advertises the zoo or one of its events (tickets, dates, sponsored content). A news report about
a zoo's plans or an upcoming change: doubtful, ask.

Not news and not an ad either (fact sheets, encyclopedia-style pages): doubtful, ask.

## Doubtful

Ask the user only about doubtful items, all in one message: id, title, one line on why it is doubtful.
Apply nothing for them until the user answers.

## Known gaps

- Duplicates: rewritten copies of one story overlap little in text (3-word shingle Jaccard ~0–0.008, against
  0.04–0.27 for near-verbatim press-release copies), so the sheet also pairs items that share proper names
  (Bronx, Sarka). On the 2026-10-03 batch of 74 items it listed all 13 duplicate pairs found by hand, within the
  top 28 of 72 pairs.
- Text and names cannot pair copies in different languages or scripts (Ukrainian vs English). Only a pending
  title is compared with the Ukrainian publish title of recent news. Check cross-language copies by reading.

## Open questions (signals, not rules)

Not confirmed with the user; ask before turning any of these into a rule.

- Press releases: the user declined National Geographic's "Pride of Samburu" documentary release (421916), which
  the skill had kept. Is a release that announces a film, show, product or campaign an ad? A conservation result
  announced by the organisation reads as news.
- Wrong-language page: 421957 (a jaguar study) was fetched in Danish because its saved link has an escaped `=`.
  The text was complete, so the skill kept it; the user declined it, reason unknown.
- On 2026-10-03, 43 items survived steps 1-4. The user kept 11 and rejected 32. 18 of the 32 went in one
  instruction after the user picked 8 to keep ("reject all other peding that are not in this list and not being
  translated"), so the patterns below partly reflect that cut, not item-by-item judgements:
  - all 6 Yahoo pages (video blurbs and syndicated stories)
  - all 10 North American mountain lion / cougar items: sightings, attacks on pets or livestock, safety
    reminders, research and road-crossing projects
  - 8 of 9 routine zoo items: arrivals, births, birthdays, illness, deaths (kept: Prague cheetah cubs)
  - also a leopard attack in India via a US TV site, a leopard electrocuted in Nepal, caracal patrols by a
    ratepayers' association, Costa Rica camera traps, a court ruling on jaguar habitat roads

  Kept: rescues and rehabilitation, poaching and poisoning, conservation programmes and park management, new
  research, rare camera-trap records, an escape inspection report, captive-lion welfare findings.
