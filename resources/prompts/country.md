Strictly classify countries (using ISO Alpha-2 codes) into a JSON format according to the following rules:
1. Core Relevance (Directly Tied to Events/Actions):
    - Main Subject Country (Score: 1.0):
        - Assign a score of 1.0 if the country is the main focus of the text, mentioned repeatedly with significant detail, and directly influences events or actions.
        - Example: "India's tigers" where India is the central focus.
    - Significant Mentions (Score: 0.7-0.9):
        - Assign a score between 0.7-0.9 for countries that are important but not the central focus.
        - Consider the frequency of mentions and the depth of information provided.
        - Example: Unique regions or landmarks with a clear 1:1 country mapping (e.g., `Chhattisgarh` → `IN`).
2. Indirect or Decoupled Relevance:
    - Incidental Mentions (Score: 0.1-0.6):
        - Assign a score between 0.1-0.6 for countries mentioned incidentally, in passing, or as part of a larger list without significant impact on the main narrative.
        - Example: A single mention without narrative impact.
    - Supplemental Sections and Geographic Comparisons (Score: 0.1-0.4):
        - Recognize supplemental triggers such as `нагадаємо`, `раніше`, `також`, `крім того`, `до слова`, `окрім цього`, `додамо`, etc.
        - Assign a score between 0.1-0.4 for countries mentioned in supplemental sections or solely in geographic comparisons.
        - Example: "Like Ukraine's territory" or mentions in sections starting with "Let's recall."
3. Rejection Criteria:
    - Exclude regions (e.g., Europe), ambiguous landmarks, or cities/landmarks unless they have a clear 1:1 country mapping to a specific country.
        - Only include cities or landmarks if they unequivocally correspond to a single country.
        - Accepted Example: `Nagpur` → `IN` (India).
        - Rejected Example: Danube (spans multiple countries).
4. Scoring System:
    - Score Range: 0.1 to 1.0 (contiguous range).
    - Use the following standardized scores:
        - 1.0 for the Main Subject Country.
        - 0.7-0.9 for Significant Mentions.
        - 0.1-0.6 for Incidental Mentions.
        - 0.1-0.4 for Supplemental Sections and Geographic Comparisons.
5. Priority Rules:
    - Exclusion Takes Precedence:
        - Exclusion rules override inclusion rules. If a mention meets the rejection criteria, it should be excluded even if other rules suggest inclusion.
    - Relevance Overrides Multiple Applicabilities:
        - When a country fits multiple scoring categories, assign the score that reflects its highest relevance to the main narrative.
6. Contextual Analysis:
    - Consider the frequency and detail of mentions. Higher frequency and more detailed descriptions indicate greater relevance and may affect the assigned score.
7. Required Output Format:
    - Output the classification as a JSON object with ISO Alpha-2 country codes as keys and their corresponding scores as values.
    - Example: {"[ISO]": [number], ...}
    - Only include valid ISO Alpha-2 country codes present in your analysis.
    - Exclude any non-ISO codes or invalid entries, even if they appear in the text.
