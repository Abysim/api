Strictly classify countries (ISO Alpha-2 codes) and wild cat species into JSON using these rules:
1. Countries:
    - Core Relevance (directly tied to events/actions):
        - Explicit country name in the main narrative: Score between 0.8-1.0 (e.g., "India’s tigers").
        - Unique regions/settlements/landmarks with 1:1 country mapping: Score between 0.6-0.9 (e.g., `Chhattisgarh` → `IN`).
    - Indirect/Decoupled Relevance (no causal link to events):
        - Geographic comparisons (e.g., "like Ukraine’s territory"): Score between 0.1-0.3.
        - Supplemental sections (phrases like `раніше`, `також`, `нагадаємо`): Score between 0.1-0.4, even if explicit.
    - Rejection Criteria: Exclude regions (e.g., Europe), ambiguous landmarks, or cities/landmarks without a 1:1 country mapping unless unequivocally tied to a specific country.
2. Species:
    - Allowed Species List (exact names and translations/synonyms): `lion`, `white lion`, `tiger`, `white tiger`, `leopard`, `jaguar`, `cheetah`, `king cheetah`, `panther`, `irbis`, `puma`, `lynx`, `ocelot`, `caracal`, `serval`, `neofelis`.
        - Map Translations and synonyms (e.g., `рись` → `lynx`, `барс` → `irbis`, `кугуар` → `puma`, `димчаста пантера` → `neofelis`).
        - Map only melanistic wild cats that have a black pelt to the term `panther`.
        - When context indicates a melanistic species (e.g., "black jaguar", "melanistic"), include mapping to `panther`.
        - Never include species outside the allowed list.
    - Core Focus:
        - Literal significant mentions of a real animal in the main narrative that directly influence events, actions, or are pivotal to the primary subject: Score between 0.7-1.0 (e.g., "India’s tigers").
        - Frequency Matters: Multiple mentions and detailed descriptions increase relevance.
    - Marginal/Statistical:
        - Species mentioned incidentally, in passing, or as part of a larger list without significant impact on the narrative: Score between 0.1-0.5 (e.g., "lynx attack stats", "similar to an ocelot", "tiger hunting").
        - Single Mentions: If a species is mentioned only once without narrative impact, assign a score between 0.1-0.3.
    - Metaphor Detection:
        - Analyze the context to determine if the species is mentioned metaphorically or symbolically.
        - Indicators of metaphorical usage include phrases like "symbolizes," "represents," "as a [species]," or any figurative language.
        - Enhanced Metaphor Detection:
            - Additionally, exclude species names found within patterns, designs, or symbolic contexts (e.g., "leopard print," "lion emblem") from being classified as actual species mentions.
            - Adjective Distinction: Exclude species terms when used adjectivally or as part of a compound noun (e.g., "leopard print" does not count as a "leopard" mention).
    - Supplemental Section:
        - All species mentioned in supplemental sections: Score between 0.1–0.4, regardless of context.
    - Pattern Recognition for Non-Animal References:
        - Recognize and exclude common non-animal references that include species names, such as patterns ("leopard print"), symbols ("lion emblem"), or products ("tiger-striped shirt").
        - Example-Based Clarifications:
            - Correct Classification: "Conservation efforts for tigers in India have increased." → `"tiger": 0.9`
            - Incorrect Classification: "She wore a leopard print dress." → Do not classify "leopard" as a species.
        - Differentiation Directive:
            - Explicitly differentiate between literal mentions of wild cat species and metaphorical or descriptive uses. Only classify species when they refer to the actual animal impacting the narrative.
        - Validation:
            - After initial classification, validate that each species mention is not part of an adjectival phrase or descriptive pattern. If it is, exclude it from the final classification.
    - Exclusion:
        - Set probability to 0 for cases unrelated to real animals (e.g., “Team Panther” as a sports team name, "Tank Cheetah" as an armored vehicle, "Lion Symbol" from a coat of arms).
3. Scoring System:
    - Scores span 0.1-1.0 (contiguous range, not buckets).
    - Supplemental Context Triggers: Terms like `нагадаємо`, `раніше`, `also`, `last year`, etc., start supplemental sections. All subsequent entities inherit a score between 0.1–0.5.
    - Hybrid Mentions: When a species is mentioned in both main and supplemental contexts, prioritize the main narrative\'s relevance score over the supplemental score.
    - Priority Rules: Metaphors Override Species Scores: Ensure metaphorical uses do not exceed a score of 0.4, regardless of their prominence in the narrative.
4. Geographic Precision:
    - Reject cities/landmarks unless they have a 1:1 country mapping (e.g., `Nagpur` → `IN` accepted; Danube rejected).
    - Satellite references (e.g., "України" for area comparisons): Score ≤ 0.3.
5. Additional Instructions:
    - Contextual Analysis:
        - Narrative Impact Assessment: Determine if the species alters the course of the story or provides essential information versus being a mere mention.
        - Frequency and Detail: Higher frequency and detailed descriptions indicate greater relevance.
    - Priority Rules: When multiple rules apply, prioritize based on relevance to the main narrative first, then supplemental guidelines.
    - Hybrid Mentions: Assign the highest relevant score when multiple criteria apply.
    - Metaphor Identification: Prioritize detecting metaphoric language to ensure species used figuratively do not receive higher relevance scores. Use contextual clues to discern metaphoric usage.
    - Validation: After initial classification, review all species mentions to confirm that scores align with their contextual significance as per the defined criteria.
6. Required Output Format:
    - Provide the classification as JSON without any explanations and without code formatting: `{"countries": {"[ISO]": [number], ...}, "species": {"[species]": [number], ...}}`
7. Constraints:
    - Never include non-ISO codes or invalid species outside the allowed species list.
