<?php

namespace App\Http\Controllers;

use App\Enums\NewsStatus;
use App\Models\News;
use App\MyCloudflareAI;
use App\Services\NewsCatcherService;
use App\Services\NewsServiceInterface;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class NewsController extends Controller
{
    private const caseSymbols = ['«', '"', "'", '[', '('];

    private const SPECIES = [
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

    private const SPECIES_TAGS = [
        'lion' => '#лев',
        'white lion' => '#лев #білийлев',
        'tiger' => '#тигр',
        'white tiger' => '#тигр #білийтигр',
        'leopard' => '#леопард',
        'jaguar' => '#ягуар',
        'cheetah' => '#гепард',
        'king cheetah' => '#гепард #королівськийгепард',
        'panther' => '#пантера',
        'irbis' => '#ірбіс',
        'puma' => '#пума',
        'lynx' => '#рись',
        'ocelot' => '#оцелот',
        'caracal' => '#каракал',
        'serval' => '#сервал',
        'neofelis' => '#димчастапантера',
    ];

    private const LOAD_TIME = '16:00:00';

    private array $prompts = [];

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
                if (!isset($model->classification['species'])) {
                    $this->classifyNews($model, 'species');
                    $this->rejectNewsByClassification($model);

                    if ($model->status !== NewsStatus::REJECTED_BY_CLASSIFICATION) {
                        $classification = $model->classification;
                        unset($classification['species']);
                        $this->classifyNews($model, 'species', true);
                        $this->rejectNewsByClassification($model);
                    }

                    if ($model->status === NewsStatus::REJECTED_BY_CLASSIFICATION) {
                        continue;
                    }
                }

                if (!isset($model->classification['country'])) {
                    $this->classifyNews($model, 'country');
                }

                if (
                    isset($model->classification['country']['UA'])
                    && $model->classification['country']['UA'] >= 0.7
                    && !isset($model->classification['region'])
                ) {
                    $this->classifyNews($model, 'region');
                }

                if (empty($model->publish_tags)) {
                    // TODO $this->preparePublishTags($model);
                }

                if (empty($model->publish_title)) {
                    $model->publish_title = $model->title;
                    $model->save();
                }

                $model->refresh();
                if (
                    empty($model->status)
                    || $model->status == NewsStatus::CREATED
                    || $model->status == NewsStatus::PENDING_REVIEW && empty($model->message_id)
                ) {
                    // TODO $this->sendNewsToReview($model);
                }
            }
        }
    }

    private function rejectNewsByClassification(News $model): void
    {
        if (!isset($model->classification['species'])) {
            return;
        }

        $rejected = true;
        foreach ($model->classification['species'] as $key => $value) {
            if (isset(self::SPECIES_TAGS[$key]) && $value >= 0.7) {
                $rejected = false;

                break;
            }
        }

        if ($rejected) {
            $model->status = NewsStatus::REJECTED_BY_CLASSIFICATION;
            $model->save();
        }
    }

    /**
     * @param string $name
     *
     * @return string
     * @throws Exception
     */
    private function getPrompt(string $name): string
    {
        if (!isset($this->prompts[$name])) {
            $path = resource_path('prompts/' . $name . '.md');
            if (File::exists($path)) {
                $this->prompts[$name] = File::get($path);
            } else {
                throw new Exception('Prompt not found: ' . $name);
            }
        }

        return $this->prompts[$name];
    }

    private function classifyNews(News $model, string $term, bool $isDeep = false): void
    {
        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News $term classification");
                $params = [
                    'model' => $isDeep ? 'o1-mini' : 'gpt-4o-mini',
                    'messages' => [
                        ['role' => $isDeep ? 'user' : 'system', 'content' => $this->getPrompt($term)],
                        ['role' => 'user', 'content' => $model->title . "\n\n" . $model->content]
                    ],
                    'temperature' => $isDeep ? 1 : 0,
                ];
                if (!$isDeep) {
                    $params['response_format'] = ['type' => 'json_object'];
                }
                $classificationResponse = OpenAI::chat()->create($params);

                Log::info(
                    "$model->id: News $term classification result: "
                    . json_encode($classificationResponse, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($classificationResponse->choices[0]->message->content)) {
                    $classification = $model->classification ?? [];
                    $classification[$term] = json_decode($classificationResponse->choices[0]->message->content, true);

                    if (!is_array($classification[$term])) {
                        throw new Exception('Invalid classification');
                    }

                    $model->classification = $classification;
                    $model->save();
                }
            } catch (Exception $e) {
                $classification = $model->classification ?? [];
                unset($classification[$term]);
                $model->classification = $classification;

                Log::error("$model->id: News $term classification fail: {$e->getMessage()}");
            }

            if (isset($model->classification[$term])) {
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
