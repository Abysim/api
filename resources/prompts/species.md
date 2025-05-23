Classify wild cat species into JSON using the following rules:
1. Allowed Species List
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
2. Species Mapping Rules
   - Translations, Synonyms, and Common Names
     - Mapping Guidelines:
         - Map all translations, synonyms, regional names, and common names to their corresponding species in the Allowed Species List.
         - Include regional variants, subspecies names, and local common names scientifically recognized as part of an allowed species.
         - Always use the mappings provided below and extrapolate them.
     - Common Mapping Examples:
         - `bobcat` → `lynx`
         - `mountain lion` → `puma`
         - `snow leopard` → `irbis`
         - `рись` → `lynx`
         - `барс` → `irbis`
         - `кугуар` → `puma`
         - `димчаста пантера` → `neofelis`
         - `флоридська пантера` → `puma`
         - `jag` → `jaguar`
         - `snep` → `irbis`
         - `Egyptian lynx` → `caracal`
   - Unlisted Subspecies and Variants
     - Map unlisted subspecies, regional variants, and local names to their main species.
     - Examples:
         - `sumatran tiger` → `tiger`
         - `barbary lion` → `lion`
         - `amur leopard` → `leopard`
   - Melanistic Variants
     - Panther Mapping:
         - Map any melanistic (all-black) variants to `panther`.
         - Examples:
             - `black jaguar` → `panther`
             - `black leopard` → `panther`
     - Note:
         - Do not map the genus name "Panthera" to `panther` unless it specifically refers to melanistic individuals.
   - Binomial Nomenclature Handling
     - Map species mentioned using scientific names to their corresponding allowed species.
     - Examples:
         - `Panthera tigris` → `tiger`
         - `Panthera uncia` → `irbis`
     - Genus Exclusion:
         - Do not classify genus names alone as species.
         - Example:
             - `Panthera` (genus) → Do not map to any species.
3. Scoring System
   - Sentence Segmentation
     - Split the input text into sentences using standard sentence tokenization.
   - Relevance Check
     - For each sentence, decide if it is about an allowed species (after applying all mapping rules).  
     - Count a sentence as “relevant” to species X if it meets any of the following:
       - It explicitly names species X (or a mapped synonym, regional name, subspecies, scientific binomial).
       - It describes a behavior, trait, habitat, health condition, or conservation issue unique to species X.
       - It refers back to species X by any of:  
          – Pronouns: it, they, she, he, them, their, etc.  
          – Proper names or nicknames that have been introduced (e.g. “Nala”, “Sher Khan”, "F-22", etc.).  
          – Descriptive epithets tied to the group or individual (e.g. “the three cubs”, “the pair”, “the big cat”, “the melanistic individual”, etc.).  
     - Coreference & Name Resolution  
          – Once you have identified a first mention of an individual or group as species X, every subsequent sentence that unambiguously refers to that same entity—via pronouns, given names, or linked descriptions—also counts toward species X.  
          – If a sentence contains multiple pronouns or names, but it is clear they refer to different species, split the counts accordingly.
   - Scoring Calculation
     - Per Species:
         - Score = (Number of related sentences for the species) ÷ (Total number of sentences)
         - Round the score to two decimal places (standard rounding).
   - Include All Relevant Species
     - Include all species with a score greater than 0 in the JSON output.
4. Exclusions
   - Non-Animal Contexts
     - Do Not Include Sentences:
       - Where wild cat species names are used as:
           - Names of Rivers, Mountains, or Places
           - Names of People or Characters
           - Names of Teams, Brands, or Organizations
           - Names of Vehicles, Products, or Other Objects
       - Where the context makes it clear that the mention is not about the animal itself.
   - Metaphorical and Symbolic Usage
     - Do Not Include Sentences:
         - Where the species is mentioned metaphorically or symbolically.
         - In contexts related to fashion, design, costumes, masks, paintings, landmarks, locations, brands, teams, or idioms.
         - Indicators:
             - Words like "symbolizes", "represents", "pattern", "print", "motif", etc.
             - Examples: "leopard print dress", "strong as a lion", "dressed like jaguars", etc.
   - Non-Real Animal References
     - Exclude mentions in fictional, mythological, ritual, ceremonial or astrological contexts.
     - Exclude any mention of humans dressing up as, impersonating, or wearing the likeness of a wild cat.
   - Supplemental Sections
     - Exclude sentences in sections indicated by words like:
         - `Note`, `Additionally`, `Moreover`, `Besides`, `Furthermore`, etc.
         - These sentences are not counted in scoring.
5. Required Output Format
   - JSON Output:
       - Provide the classification as a JSON object.
       - Format Example: `{"[species]": [score], ...}`
       - Only include species from the Allowed Species List.
