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
                - `флоридська пантера` → `puma`
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
5. Metaphor and Symbolic Usage Exclusion:
    - Indicators of Metaphorical Usage:
        - Phrases like "symbolizes", "represents", "as a [species]", "inspired by", "depicts", "embodies", "pattern", "emblem", "print", "design", "figure", "motif", "figurative", "abstract", "adjectival usage", "compound noun".
    - Exclusions:
        - Exclude species names embedded in organization, sport team, vehicle, location, or project names (e.g., "Lion Park", "Project Tiger", "Team Panthers", "Cheetah Armoured Car").
        - Exclude species names used in patterns, designs, or symbolic contexts (e.g., "leopard print", "lion emblem").
        - Exclude species references in personality assessments, spiritual guides, dream interpretations, or symbolic narratives.
        - Exclude species terms used adjectivally or as part of a compound noun (e.g., "tiger-striped").
        - Exclude mentions where species are part of metaphors or similes (e.g., "as fierce as a tiger").
        - Implement detection of surrounding words that indicate symbolic or metaphorical usage.
6. Scoring System:
    - Score Range: 0.01 to 1.0 (contiguous range).
    - Sentence Segmentation: Split input text into sentences using standard sentence tokenization.
    - Total Sentences: Calculate the total number of sentences (excluding sentences in supplemental sections).
    - Relevance Check: For each non-supplemental sentence, check if it is related to any species from the Allowed Species List (after applying mapping, handling, and exclusion rules).
    - Contextual Check: Count a sentence as related if it indirectly mentions the species from other sentences.
    - For each species:
        - Score = (Number of sentences related to the species) / (Total sentences)
        - Round the score to two decimal places using standard rounding rules (round half up).
    - Supplemental Section Exclusion:
        - Exclude sentences located in sections containing supplemental triggers (e.g., `нагадаємо`, `раніше`, `також`, `крім того`, `до слова`, `окрім цього`, `додамо`, `цікаво`).
        - Supplemental sentences are not counted in total scoring.
7. Required Output Format:
    - Include all allowed species with a score >0 in the JSON output.
    - Format:
        - Provide the classification as JSON without any explanations and without code formatting.
        - Example: `{"[species]": [number], ...}`
        - Only include species from the Allowed Species List in the JSON output.
    - Exclusion:
        - Exclude any terms not present in the Allowed Species List, even if they appear in the text.
