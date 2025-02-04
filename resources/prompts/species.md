Strictly classify wild cat species into JSON using these rules:
1. Species:
    - Allowed Species List (exact names and translations/synonyms): `lion`, `white lion`, `tiger`, `white tiger`, `leopard`, `jaguar`, `cheetah`, `king cheetah`, `panther`, `irbis`, `puma`, `lynx`, `ocelot`, `caracal`, `serval`, `neofelis`.
        - Map Translations and synonyms (e.g., `рись` → `lynx`, `барс` → `irbis`, `кугуар` → `puma`, `димчаста пантера` → `neofelis`).
        - Map only melanistic wild cats that have a black pelt to the term `panther`.
        - When context indicates a melanistic species (e.g., "black jaguar", "melanistic"), include mapping to `panther`.
        - Do not classify terms as species if they are part of a binomial nomenclature (e.g., `Panthera leo`, `Panthera pardus`) or appear alongside genus names. Ensure that only the species part is classified (e.g., classify `leo` as `lion` but not `panther`).
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
        - All species mentioned in supplemental sections (phrases like `раніше`, `також`, `нагадаємо`, etc.): Score between 0.1–0.4, regardless of context.
    - Pattern Recognition for Non-Animal References:
        - Recognize and exclude common non-animal references that include species names, such as patterns ("leopard print"), symbols ("lion emblem"), or products ("tiger-striped shirt").
        - Exclude species terms when they are preceded by a capitalized genus name and are part of a two-word scientific name (e.g., `Panthera tigris`, `Panthera onca`). Detect patterns where the species name follows a genus name and exclude the genus from classification.
        - Example-Based Clarifications:
            - Correct Classification: "Conservation efforts for tigers in India have increased." → `"tiger": 0.9`
            - Incorrect Classification: "She wore a leopard print dress." → Do not classify "leopard" as a species.
            - Correct Classification: "Panthera leo is known as the lion." → `"lion": 0.9`
            - Incorrect Classification: "Panthera leo is a species of Panthera." → Do not classify "Panthera" as `panther`.
        - Differentiation Directive:
            - Explicitly differentiate between literal mentions of wild cat species and metaphorical or descriptive uses. Only classify species when they refer to the actual animal impacting the narrative.
        - Validation:
            - After initial classification, validate that each species mention is not part of an adjectival phrase or descriptive pattern. If it is, exclude it from the final classification.
    - Exclusion:
        - Set probability to 0 for cases unrelated to real animals (e.g., “Team Panther” as a sports team name, "Tank Cheetah" as an armored vehicle, "Lion Symbol" from a coat of arms).
2. Scoring System:
    - Scores span 0.1-1.0 (contiguous range, not buckets).
    - Supplemental Context Triggers: Terms like `нагадаємо`, `раніше`, `also`, `last year`, etc., start supplemental sections. All subsequent entities inherit a score between 0.1–0.5.
    - Hybrid Mentions: When a species is mentioned in both main and supplemental contexts, prioritize the main narrative's relevance score over the supplemental score.
    - Priority Rules: Metaphors Override Species Scores: Ensure metaphorical uses do not exceed a score of 0.4, regardless of their prominence in the narrative.
3. Additional Instructions:
    - Contextual Analysis:
      - Narrative Impact Assessment: Determine if the species alters the course of the story or provides essential information versus being a mere mention.
      - Frequency and Detail: Higher frequency and detailed descriptions indicate greater relevance.
    - Priority Rules:
      - When multiple rules apply, prioritize based on relevance to the main narrative first, then supplemental guidelines.
      - Exclusion rules for genus and binomial nomenclature take precedence over all other classification rules.
    - Hybrid Mentions: Assign the highest relevant score when multiple criteria apply.
    - Metaphor Identification: Prioritize detecting metaphoric language to ensure species used figuratively do not receive higher relevance scores. Use contextual clues to discern metaphoric usage.
    - Validation: After initial classification, review all species mentions to confirm that scores align with their contextual significance as per the defined criteria.
4. Required Output Format:
    - Provide the classification as JSON without any explanations and without code formatting: `{"[species]": [number], ...}`
5. Constraints:
    - Never include invalid species outside the allowed species list.
