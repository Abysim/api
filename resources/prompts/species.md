Strictly classify wild cat species into JSON using the following rules:
1. Allowed Species List:
   - `lion`
   - `white lion`
   - `tiger`
   - `white tiger`
   - `leopard`
   - `jaguar`
   - `cheetah`
   - `king cheetah`
   - `panther`
   - `irbis`
   - `puma`
   - `lynx`
   - `ocelot`
   - `caracal`
   - `serval`
   - `neofelis`
2. Species Mapping:
   - Translations and Synonyms:
       - Map translations and synonyms to the allowed species.
           - Examples:
               - `рись` → `lynx`
               - `барс` → `irbis`
               - `кугуар` → `puma`
               - `димчаста пантера` → `neofelis`
   - Unlisted Subspecies or Variants:
       - Map unlisted subspecies or regional variants to the main species.
           - Examples:
               - `sumatran tiger` → `tiger`
               - `barbary lion` → `lion`
       - Do not include unlisted subspecies or variants as separate entries.
   - Listed Variants:
       - Include all listed variants (e.g., `white tiger`, `king cheetah`) as separate entries if mentioned.
3. Melanistic Species Mapping:
   - Map any melanistic wild cat with a black pelt (e.g., "black jaguar", "black leopard") to `panther`.
   - Use `panther` only for melanistic variants of allowed species.
4. Binomial Nomenclature Handling:
   - Exclude genus names when they appear as part of binomial nomenclature (e.g., `Panthera tigris`).
   - Only classify the species part if it corresponds to an allowed species.
       - Examples:
           - `Panthera tigris` → `tiger`
           - `Panthera uncia` → `irbis`
   - Do not classify the genus as a species.
       - Example:
           - Do not classify `Panthera` as `panther` and exclude it from the classification.
5. Scoring System:
   - Score Range: 0.1 to 1.0 (contiguous range).
   - Main Subject Species (1.0):
       - Assign a score of 1.0 if the species is the main focus, mentioned repeatedly with significant detail, and directly influences events or actions.
   - Significant Mentions (0.7–0.9):
       - Assign scores between 0.7–0.9 for species that are important but not the central focus.
   - Incidental Mentions (0.1–0.6):
       - Assign scores between 0.1–0.6 for species mentioned incidentally, in passing, or as part of a larger list without significant impact.
       - For single mentions without narrative impact, assign scores between 0.1–0.3.
   - Supplemental Sections (0.1–0.4):
       - Recognize supplemental triggers (e.g., `нагадаємо`, `раніше`, `також`, `крім того`, `до слова`, `окрім цього`, `додамо`).
       - Assign scores between 0.1–0.4 for species mentioned in supplemental sections.
       - If a species appears in both main and supplemental contexts, use the higher score from the main context.
6. Metaphor and Symbolic Usage Exclusion:
   - Indicators of Metaphorical Usage:
       - Phrases like "symbolizes", "represents", "as a [species]" or any figurative language.
   - Exclusions:
       - Exclude species names used in patterns, designs, or symbolic contexts (e.g., "leopard print", "lion emblem").
       - Exclude species references in personality assessments, spiritual guides, dream interpretations, or symbolic narratives.
       - Exclude species terms used adjectivally or as part of a compound noun (e.g., "tiger-striped").
   - Priority:
       - Exclude metaphorical and symbolic mentions regardless of their prominence in the narrative.
7. Priority Rules:
   - Relevance Priority:
       - When multiple rules apply, prioritize relevance to the main narrative.
   - Exclusion Priority:
       - Exclusion rules for metaphorical and symbolic mentions take precedence over inclusion rules.
8. Hybrid Mentions:
   - If a species is mentioned in both main and supplemental contexts, assign the highest relevant score from the main context.
9. Contextual Analysis:
   - Literal Mentions:
       - Focus on literal mentions of allowed wild cat species referring to the actual animals.
   - Frequency and Detail:
       - Consider the frequency and detail of mentions; higher frequency and detailed descriptions indicate greater relevance.
   - Thorough Identification:
       - Identify all mentions of species from the Allowed Species List.
10. Required Output Format:
    - Format:
        - Provide the classification as JSON.
        - Example: `{"[species]": [number], ...}`
        - Only include species from the Allowed Species List in the JSON output.
    - Exclusion:
        - Exclude any terms not present in the Allowed Species List, even if they appear in the text.
