<?php

namespace App\Http\Controllers;

use App\Enums\NewsStatus;
use App\Models\News;
use App\MyCloudflareAI;
use App\Services\NewsCatcherService;
use App\Services\NewsServiceInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class NewsController extends Controller
{
    public const caseSymbols = ['«', '"', "'", '[', '('];

    public const SPECIES = [
        'lion' => [
            'words' => [
                'лев',
                'леві',
                'левів',
                'лева',
                'левам',
                'левами',
                'левах',
                'леви',
                'левові',
                'левом',
                'леву',
                'левиця',
                'левиці',
                'левицею',
                'левиць',
                'левицю',
                'левицям',
                'левицями',
                'левицях',
                'левеня',
                'левеням',
                'левенят',
                'левеняті',
                'левенята',
                'левенятам',
                'левенятами',
                'левенятах',
                'левеняти',
            ],
            'exclude' => [
                'зодіак*',
                'гороскоп*',
                'баскетбол*',
                'астролог*',
                'каннськ*',
                'хоке*',
                'світськ*',
                'бригад*',
            ],
            'excludeCase' => [
                'Лев',
            ]
        ],
        'tiger' => [
            'words' => [
                'тигр',
                'тигра',
                'тигрі',
                'тигрів',
                'тиграм',
                'тиграми',
                'тиграх',
                'тигри',
                'тигрові',
                'тигром',
                'тигру',
                'тигриці',
                'тигрицею',
                'тигриць',
                'тигрицю',
                'тигриця',
                'тигрицям',
                'тигрицями',
                'тигрицях',
                'тигреня',
                'тигреням',
                'тигренят',
                'тигреняті',
                'тигренята',
                'тигренятам',
                'тигренятами',
                'тигренятах',
                'тигреняти',
            ],
            'exclude' => [
                'гороскоп*',
            ],
            'excludeCase' => [
                'Тигр',
            ]
        ],
        'leopard' => [
            'words' => [
                'леопард',
                'леопарда',
                'леопарді',
                'леопардів',
                'леопардам',
                'леопардами',
                'леопардах',
                'леопарди',
                'леопардові',
                'леопардом',
                'леопарду',
                'леопардиця',
                'леопардиці',
                'леопардицею',
                'леопардиць',
                'леопардицю',
                'леопардицям',
                'леопардицями',
                'леопардицях',
                'леопарденя',
                'леопарденям',
                'леопарденят',
                'леопарденяті',
                'леопарденята',
                'леопарденятам',
                'леопарденятами',
                'леопарденятах',
                'леопарденяти',
            ],
            'exclude' => [
                'бригад*',
                'танк*',
                'механізован*',
            ],
            'excludeCase' => [
                'Леопард',
            ]
        ],
        'jaguar' => [
            'words' => [
                'ягуар',
                'ягуара',
                'ягуарі',
                'ягуарів',
                'ягуарам',
                'ягуарами',
                'ягуарах',
                'ягуари',
                'ягуарові',
                'ягуаром',
                'ягуару',
                'ягуариця',
                'ягуариці',
                'ягуарицею',
                'ягуариць',
                'ягуарицю',
                'ягуарицям',
                'ягуарицями',
                'ягуарицях',
                'ягуареня',
                'ягуареням',
                'ягуаренят',
                'ягуареняті',
                'ягуаренята',
                'ягуаренятам',
                'ягуаренятами',
                'ягуаренятах',
                'ягуареняти',
            ],
            'exclude' => [],
            'excludeCase' => [
                'Ягуар',
            ]
        ],
        'cheetah' => [
            'words' => [
                'гепард',
                'гепарда',
                'гепарді',
                'гепардів',
                'гепардам',
                'гепардами',
                'гепардах',
                'гепарди',
                'гепардові',
                'гепардом',
                'гепарду',
                'гепардиця',
                'гепардиці',
                'гепардицею',
                'гепардиць',
                'гепардицю',
                'гепардицям',
                'гепардицями',
                'гепардицях',
                'гепарденя',
                'гепарденям',
                'гепарденят',
                'гепарденяті',
                'гепарденята',
                'гепарденятам',
                'гепарденятами',
                'гепарденятах',
                'гепарденяти',
            ],
            'exclude' => [
                'установк*',
                'танк*',
                'підрозділ*',
                'бригад*',
                'ппо',
            ],
            'excludeCase' => [
                'Гепард',
            ]
        ],
        'panther' => [
            'words' => [
                'пантера',
                'пантери',
                'пантері',
                'пантеру',
                'пантерою',
                'пантеро',
                'пантер',
                'пантерові',
                'пантером',
                'пантерам',
                'пантерами',
                'пантерах',
                'пантерів',
                'пантереня',
                'пантереням',
                'пантеренят',
                'пантереняті',
                'пантеренята',
                'пантеренятам',
                'пантеренятами',
                'пантеренятах',
                'пантереняти',
            ],
            'exclude' => [
                'танк*',
            ],
            'excludeCase' => [
                'Пантер',
                'Чорн',
                'Біл',
                'Рожев',
                'Лігв',
            ],
        ],
        'irbis' => [
            'words' => [
                'ірбіс',
                'ірбіса',
                'ірбісі',
                'ірбісів',
                'ірбісам',
                'ірбісами',
                'ірбісах',
                'ірбіси',
                'ірбісові',
                'ірбісом',
                'ірбісу',
                'ірбісеня',
                'ірбісеням',
                'ірбісенят',
                'ірбісеняті',
                'ірбісенята',
                'ірбісенятам',
                'ірбісенятами',
                'ірбісенятах',
                'ірбісеняти',
                'барс',
                'барса',
                'барсі',
                'барсів',
                'барсам',
                'барсами',
                'барсах',
                'барси',
                'барсові',
                'барсом',
                'барсу',
                'барсиця',
                'барсиці',
                'барсицею',
                'барсиць',
                'барсицю',
                'барсицям',
                'барсицями',
                'барсицях',
                'барсеня',
                'барсеням',
                'барсенят',
                'барсеняті',
                'барсенята',
                'барсенятам',
                'барсенятами',
                'барсенятах',
                'барсеняти',
            ],
            'exclude' => [
                'барселон*',
                'добровольч*',
                'сицілійськ*',
                'позивни*',
                'астролог*',
            ],
            'excludeCase' => [
                'Барс',
                'БАРС',
            ]
        ],
        'puma' => [
            'words' => [
                'пума',
                'пуми',
                'пумі',
                'пуму',
                'пумою',
                'пумо',
                'пум',
                'пумам',
                'пумами',
                'пумах',
                'пумах',
                'пумі',
                'пумів',
                'пумів',
                'пуменя',
                'пуменям',
                'пуменят',
                'пуменяті',
                'пуменята',
                'пуменятам',
                'пуменятами',
                'пуменятах',
                'пуменяти',
                'кугуар',
                'кугуара',
                'кугуарі',
                'кугуарів',
                'кугуарам',
                'кугуарами',
                'кугуарах',
                'кугуари',
                'кугуарові',
                'кугуаром',
                'кугуару',
                'кугуариця',
                'кугуариці',
                'кугуарицею',
                'кугуариць',
                'кугуарицю',
                'кугуарицям',
                'кугуарицями',
                'кугуарицях',
                'кугуареня',
                'кугуареням',
                'кугуаренят',
                'кугуареняті',
                'кугуаренята',
                'кугуаренятам',
                'кугуаренятами',
                'кугуаренятах',
                'кугуареняти',
            ],
            'exclude' => [],
        ],
        'lynx' => [
            'words' => [
                'рись',
                'рисі',
                'рися',
                'рисем',
                'рисю',
                'рисе',
                'рисів',
                'рисям',
                'рисями',
                'рисях',
                'рисеня',
                'рисеням',
                'рисенят',
                'рисеняті',
                'рисенята',
                'рисенятам',
                'рисенятами',
                'рисенятах',
                'рисеняти',
            ],
            'exclude' => [
                'рис',
                'рисом',
                'рису',
                'риса',
            ],
        ],
        'ocelot' => [
            'words' => [
                'оцелот',
                'оцелота',
                'оцелоті',
                'оцелотів',
                'оцелотам',
                'оцелотами',
                'оцелотах',
                'оцелоти',
                'оцелотові',
                'оцелотом',
                'оцелоту',
                'оцелотеня',
                'оцелотеням',
                'оцелотенят',
                'оцелотеняті',
                'оцелотенята',
                'оцелотенятам',
                'оцелотенятами',
                'оцелотенятах',
                'оцелотеняти',
            ],
            'exclude' => [],
        ],
        'caracal' => [
            'words' => [
                'каракал',
                'каракала',
                'каракалі',
                'каракалів',
                'каракалам',
                'каракалами',
                'каракалах',
                'каракали',
                'каракалові',
                'каракалом',
                'каракалу',
                'каракаленя',
                'каракаленям',
                'каракаленят',
                'каракаленяті',
                'каракаленята',
                'каракаленятам',
                'каракаленятами',
                'каракаленятах',
                'каракаленяти',
            ],
            'exclude' => [],
        ],
        'serval' => [
            'words' => [
                'сервал',
                'сервала',
                'сервалі',
                'сервалів',
                'сервалам',
                'сервалами',
                'сервалах',
                'сервали',
                'сервалові',
                'сервалом',
                'сервалу',
                'серваленя',
                'серваленям',
                'серваленят',
                'серваленяті',
                'серваленята',
                'серваленятам',
                'серваленятами',
                'серваленятах',
                'серваленяти',
            ],
            'exclude' => [],
        ],
    ];

    const LOAD_TIME = '16:00:00';

    private NewsServiceInterface $service;

    public function __construct(NewsCatcherService $service)
    {
        $this->service = $service;
    }

    public function process()
    {
        Log::info('Processing news');
        // TODO $this->publish();

        $models = [];
        if (now()->format('H:i:s') >= self::LOAD_TIME && now()->format('G') % 3 == 0) {
            $models = $this->loadNews();
        }

        foreach (News::whereIn('status', [
            NewsStatus::CREATED,
            NewsStatus::PENDING_REVIEW,
        ])->whereNotIn('id', array_keys($models))->get() as $model) {
            $models[$model->id] = $model;
        }

        $this->processNews($models);

        // TODO $this->deleteNewsFiles();
    }

    private function loadNews(): array
    {
        $models = [];

        $query = '';
        $specieses = [];
        $words = [];
        $exclude = [];
        foreach (self::SPECIES as $species => $data) {
            $isSearch = false;
            $currentSpecieses = array_merge($specieses, [$species]);
            $currentWords = array_merge($words, $data['words']);
            $currentExclude = array_unique(array_merge($exclude, $data['exclude']));

            $currentQuery = $this->service->generateSearchQuery($currentWords, $currentExclude);

            if (Str::length($currentQuery) > $this->service->getSearchQueryLimit()) {
                $isSearch = true;

                $currentSpecieses = $specieses;
                $currentWords = $words;
                $currentExclude = $exclude;

                $currentQuery = $query;
            }

            if (array_key_last(self::SPECIES) == $species) {
                $isSearch = true;
            }

            if ($isSearch) {
                $news = $this->service->getNews($currentQuery);

                foreach ($news as $article) {
                    $model = News::updateOrCreate([
                        'platform' => $this->service->getName(),
                        'external_id' => $article['_id']
                    ], [
                        'date' => explode(' ', $article['published_date'])[0],
                        'author' => $article['author'],
                        'title' => $article['title'],
                        'content' => $article['summary'],
                        'link' => $article['link'],
                        'source' => $article['rights'] ?? $article['clean_url'],
                        'language' => $article['language'],
                        'media' => $article['media'],
                        'posted_at' => $article['published_date'],
                    ]);

                    // get array of all unique words in the article including multibyte ones
                    $articleWords = array_unique(preg_split('/\s+/', preg_replace(
                        '/[^\p{L}\p{N}\p{Zs}]/u',
                        ' ',
                        $article['title'] . ' ' . $article['summary'])
                    ));

                    foreach ($currentSpecieses as $currentSpecies) {
                        foreach (self::SPECIES[$currentSpecies]['words'] as $word) {
                            foreach ($articleWords as $articleWord) {
                                if ($word == Str::lower($articleWord)) {
                                    if (!in_array($currentSpecies, $model->species ?? [])) {
                                        $model->species = array_merge($model->species ?? [], [$currentSpecies]);
                                        $model->save();
                                    }

                                    break;
                                }
                            }
                        }
                    }

                    $models[$model->id] = $model;
                }

                $specieses = [$species];
                $words = $data['words'];
                $exclude = $data['exclude'];

                $query = $this->service->generateSearchQuery($words, $exclude);
            } else {
                $specieses = $currentSpecieses;
                $words = $currentWords;
                $exclude = $currentExclude;

                $query = $currentQuery;
            }
        }

        return $models;
    }

    private function processNews(array $models)
    {
        foreach ($models as $model) {
            if (empty($model->status) || $model->status == NewsStatus::CREATED) {
                $this->excludeByTags($model);
            }

            $model->refresh();
            if (
                empty($model->status)
                || $model->status == NewsStatus::CREATED
                || $model->status == NewsStatus::PENDING_REVIEW
            ) {
                if (empty($model->classification)) {
                    $this->classifyNews($model);
                }
            }
        }
    }

    private function classifyNews(News $model)
    {
        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info($model->id . ': News classification');
                $classificationResponse = OpenAI::chat()->create([
                    'model' => 'o1-mini',
                    'messages' => [
                        ['role' => 'user', 'content' => '
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
     - Map only melanistic wild cats that have black color of pelt to the term `panther`, do not map other cases to it.
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
     - Metaphorical Mentions:
       - Always assign a score between 0.1–0.4, regardless of prominence in the narrative.
       - Example: "The brand\'s mascot, a tiger, represents strength." → Score: 0.3
     - Literal Mentions:
       - Only assign higher scores (0.7–1.0) if the species is a real animal directly influencing the narrative.
       - Example: "Conservation efforts for tigers in India have increased." → Score: 0.9
   - Supplemental Section:
     - All species mentioned in supplemental sections: Score between 0.1–0.4, regardless of context.
   - Exclusion:
     - Set probability to 0 for species unrelated to real animals (e.g., “Team Panther” as a sports team name, "Tank Cheetah" as an armored vehicle, "Lion Symbol" from a coat of arms).
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
   - Provide the classification as JSON without any explanations and without code formatting: {"countries": {"[ISO]": [number], ...}, "species": {"[species]": [number], ...}}
7. Constraints:
   - Never include non-ISO codes or invalid species outside the allowed species list.
                        '],
                        ['role' => 'user', 'content' => $model->title . '. ' . $model->content]
                    ],
                ]);

                Log::info($model->id . ': Classification result: ' . json_encode($classificationResponse));

                if (!empty($classificationResponse->choices[0]->message->content)) {
                    $model->classification = json_decode($classificationResponse->choices[0]->message->content);
                    $model->save();
                }
            } catch (Exception $e) {
                $model->classification = null;

                Log::error($model->id . ': News classification fail: ' . $e->getMessage());
            }

            if (!empty($model->classification)) {
                break;
            }
        }
    }

    private function excludeByTags($model)
    {
        $text = $model->title . '. ' . $model->content;

        foreach ($model->species as $species) {
            foreach (self::SPECIES[$species]['excludeCase'] ?? [] as $excludeCase) {
                $lastPosition = 0;
                while (($lastPosition = Str::position($text, $excludeCase, $lastPosition)) !== false) {
                    if (
                        $lastPosition > 1
                        && Str::charAt($text, $lastPosition - 1) == ' '
                        && !in_array(Str::charAt($text, $lastPosition - 2), ['.', '!', '?', '…'])
                    ) {
                        $model->status = NewsStatus::REJECTED_BY_KEYWORD;
                        $model->save();

                        return;
                    }

                    $lastPosition = $lastPosition + Str::length($excludeCase);
                }

                foreach (self::caseSymbols as $caseSymbol) {
                    $excludeWord = $caseSymbol . $excludeCase;
                    if (Str::contains($text, $excludeWord)) {
                        $model->status = NewsStatus::REJECTED_BY_KEYWORD;
                        $model->save();

                        return;
                    }
                }
            }
        }
    }
}
