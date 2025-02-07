Strictly classify regions of Ukraine into a JSON format according to the following rules:
1. Allowed Regions List:
    - `Cherkasy`
    - `Chernihiv`
    - `Chernivtsi`
    - `Crimea`
    - `Dnipropetrovsk`
    - `Donetsk`
    - `Ivano-Frankivsk`
    - `Kharkiv`
    - `Kherson`
    - `Khmelnytskyi`
    - `Kirovohrad`
    - `Kyiv`
    - `Luhansk`
    - `Lviv`
    - `Mykolaiv`
    - `Odesa`
    - `Poltava`
    - `Rivne`
    - `Sumy`
    - `Ternopil`
    - `Vinnytsia`
    - `Volyn`
    - `Zakarpattia`
    - `Zaporizhzhia`
    - `Zhytomyr`
2. Core Relevance (Directly Tied to Events/Actions):
    - Main Subject Region (Score: 1.0):
        - Assign a score of 1.0 if the region is the main focus of the text, is the location where significant events or actions occur, is mentioned repeatedly with substantial detail, or hosts key institutions or landmarks central to the narrative, even if the region's name is not explicitly mentioned.
        - Example: "The economic development of Kyiv" where Kyiv is the central focus.
    - Significant Mentions (Score: 0.7–0.9):
        - Assign a score between 0.7 and 0.9 for regions that are important but not the central focus.
        - Include regions associated with significant events, actions, institutions, or historical region synonyms mentioned in the text.
        - Base the score on the frequency of mentions, depth of information provided, and the significance of associated events, institutions, or historical regions.
        - Example: Mentioning "Інститут зоології НАН України" increases Kyiv's score as the institute is located there.
3. Indirect or Lesser Relevance:
    - Incidental Mentions (Score: 0.1–0.6):
        - Assign a score between 0.1 and 0.6 for regions mentioned incidentally or indirectly without significant impact on the main narrative.
        - Include regions referenced through general geographical areas, historical region synonyms (e.g., "Полісся" refers to regions like Rivne and Zhytomyr), or landmarks.
        - Assign scores based on context and relevance to the regions located in those areas.
        - Example: Mentioning the Black Sea coast increases scores for Odesa, Kherson, Mykolaiv, and Zaporizhzhia.
    - Supplemental Sections and Geographic Comparisons (Score: 0.1–0.4):
        - Recognize supplemental triggers such as `нагадаємо`, `раніше`, `також`, `крім того`, `до слова`, `окрім цього`, `додамо`, `цікаво`, etc.
        - Assign a score between 0.1 and 0.4 for regions mentioned only in supplemental sections or comparisons.
        - Example: "Similar practices in Vinnytsia" might receive a score of 0.2.
4. Mentions of Institutions, Organizations, Landmarks, and Settlements:
    - When specific places, landmarks, settlements (e.g., villages, towns, districts), or other geographical entities are mentioned, assign scores to the region where they are located, even if the region's name is not explicitly stated.
    - Scoring Guidelines:
        - Main Subject Place (Score: 1.0):
            - Assign a score of 1.0 if the place is central to the main events or actions in the text.
            - Applies when the place is the focus of the narrative, significant events occur there, or it is described in substantial detail.
        - Significant Places (Score: 0.7–0.9):
            - Assign a score between 0.7 and 0.9 for places that are important but not the central focus.
            - Base the score on the frequency of mentions, depth of information, and significance to the narrative.
        - Incidental Places (Score: 0.1–0.6):
            - Assign a score between 0.1 and 0.6 for places mentioned incidentally or indirectly.
            - Applies when the place has minimal impact on the main narrative.
    - Examples: 
        - Mentioning "Центр порятунку диких тварин" as the main focus increases Kyiv's score to 1.0, even if Kyiv is not explicitly mentioned.
        - Mentioning "Подільський зоопарк" as the main focus of the story increases Vinnytsia's score to 1.0, even if Vinnytsia is not explicitly mentioned.
5. Regions Identified by Common Names or Landmarks:
    - Common Names Covering Multiple Regions:
        - If a common name or area refers to multiple regions (e.g., Donbas refers to Donetsk and Luhansk):
            - Assign scores to the specific regions based on context and any additional details provided.
            - Example: Discussing coal mining in Donbas might assign a higher score to Donetsk.
    - Ambiguous Landmarks, Historical Region Synonyms, and General Geographic Areas:
        - When a general geographic area or historical region synonym is mentioned (e.g., "Black Sea shore", "Поділля", "Полісся"):
            - Identify all regions from the Allowed Regions List located in that area or associated with that historical region.
            - Assign scores to these regions based on additional context provided in the text.
            - Regions more prominently associated in the context receive higher scores.
            - Example: Mentioning "Поділля" may increase scores for Vinnytsia, Khmelnytskyi, and Ternopil.
6. Rejection Criteria:
    - Exclusions:
        - Exclude places not in the Allowed Regions List.
        - Do not assign scores to foreign regions or non-listed areas, even if mentioned.
7. Scoring System:
    - Score Range: 0.1 to 1.0.
    - Use Standardized Scores:
        - 1.0 for Main Subject Region.
        - 0.7–0.9 for Significant Mentions, regions associated with significant institutions, organizations, or historical region synonyms.
        - 0.1–0.6 for Incidental Mentions and regions identified via general geographic areas, landmarks, or historical region synonyms.
        - 0.1–0.4 for Supplemental Sections and Geographic Comparisons.
8. Priority Rules:
    - Inclusion of Ambiguous Landmarks, General Geographic Areas, and Historical Region Synonyms:
        - When ambiguous landmarks, general geographic areas, or historical region synonyms are mentioned, include associated regions rather than excluding them.
        - Use context to determine which regions are most relevant.
    - Exclusion Overrides Inclusion for Non-Listed Regions:
        - Do not include any regions not in the Allowed Regions List, even if associated with mentioned landmarks or areas.
    - Assign Highest Relevant Score:
        - If a region fits multiple categories, assign the score reflecting its highest relevance.
9. Contextual Analysis:
    - Consider Institutions, Landmarks, and Historical Regions:
        - Take into account the locations of key institutions, landmarks, or historical regions directly tied to the main events when analyzing relevance, even if the region's name is not explicitly mentioned.
    - Use Additional Knowledge:
        - Utilize available knowledge or resources to identify the region associated with an institution, landmark, or historical region synonym when necessary.
        - Example: Knowing that "Центр порятунку диких тварин" is located in Kyiv, increase Kyiv's score accordingly.
10. Required Output Format:
    - Output the Classification as a JSON Object:
        - Use region names from the Allowed Regions List as keys.
        - Assign corresponding scores as values.
        - Example: `{"[region]": [number], ...}`
    - Important Notes:
        - Only include regions from the Allowed Regions List.
        - Ensure all region names are single words.
        - Do not include any regions not listed, even if they appear in the text.
