<?php

namespace App\Http\Controllers;

use App\AI;
use App\Bluesky;
use App\Enums\FlickrPhotoStatus;
use App\Enums\NewsStatus;
use App\Helpers\FileHelper;
use App\Jobs\AnalyzeNewsJob;
use App\Jobs\ApplyNewsAnalysisJob;
use App\Jobs\NewsJob;
use App\Jobs\TranslateNewsJob;
use App\Models\BlueskyConnection;
use App\Models\FlickrPhoto;
use App\Models\News;
use App\Services\BigCatsService;
use App\Services\NewsCatcher3Service;
use App\Services\NewsServiceInterface;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

class NewsController extends Controller
{
    private const SPECIES_THRESHOLD = 0.25;

    private const LOAD_TIME = '18:00:00';

    private const STOP_TIME = '22:00:00';

    private const PUBLISH_AFTER = '06:00:00';

    private const PUBLISH_BEFORE = '21:00:00';

    private array $species = [];

    private array $tags = [];

    private static array $prompts = [];

    private array $previousWeekNews = [];

    private NewsServiceInterface|null $service;

    public function __construct(NewsCatcher3Service $service)
    {
        $this->service = $service;
    }

    /**
     * @throws Exception
     */
    public function process($load = true, $force = false, $lang = null, $publish = true): void
    {
        Log::info('Processing news ' . $lang);
        if ($publish) {
            $this->publish();
        }

        $models = [];
        if ($force || (
            $load
            && now()->format('H:i:s') >= self::LOAD_TIME
            && now()->format('H:i:s') < self::STOP_TIME
            && in_array(now()->format('G') % 3, [0, 1])
        )) {
            $models = $this->loadNews($lang ?? ((now()->format('G') % 3 == 1) ? 'en' : 'uk'));
        }

        if (empty($models)) {
            foreach (News::whereIn('status', [
                NewsStatus::CREATED,
                NewsStatus::PENDING_REVIEW,
            ])->limit(1000)->get() as $model) {
                $models[$model->id] = $model;
            }

            $count = count($models);
            $this->processNews($models);
            if ($count == 1000) {
                NewsJob::dispatch(publish: false);
            }
        } else {
            NewsJob::dispatch(publish: false);
        }

        $this->deleteNewsFiles();
    }

    private function deleteNewsFiles()
    {
        $models = News::where('status', NewsStatus::PUBLISHED)
            ->whereNotNull('filename')
            ->where('published_at', '<', now()->subDay()->toDateTimeString())
            ->get();

        foreach ($models as $model) {
            $model->deleteFile();

            if ($model->message_id) {
                Request::editMessageCaption([
                    'chat_id' => explode(',', config('telegram.admins'))[0],
                    'message_id' => $model->message_id,
                    'caption' => '',
                ]);

                $response = Request::deleteMessage([
                    'chat_id' => explode(',', config('telegram.admins'))[0],
                    'message_id' => $model->message_id,
                ]);
                if ($response->isOk()) {
                    $model->message_id = null;
                    $model->save();
                }
            }
        }

        $models = News::query()
            ->whereIn('status', [
                NewsStatus::REJECTED_BY_KEYWORD,
                NewsStatus::REJECTED_MANUALLY,
            ])
            ->whereNotNull('filename')
            ->get();

        foreach ($models as $model) {
            $model->deleteFile();
        }
    }

    /**
     * @throws Exception
     */
    private function publish()
    {
        $nowTime = now()->format('H:i:s');
        if ($nowTime < self::PUBLISH_AFTER || $nowTime >= self::PUBLISH_BEFORE) {
            return;
        }

        $lastPublishedPhotoTime = FlickrPhoto::where('status', FlickrPhotoStatus::PUBLISHED)
            ->orderByDesc('published_at')
            ->value('published_at');
        $lastPublishedNewsTime = News::where('status', NewsStatus::PUBLISHED)
            ->orderByDesc('published_at')
            ->value('published_at');
        $lastPublishedTime = max($lastPublishedPhotoTime, $lastPublishedNewsTime);
        $publishInterval = 30;

        if (empty($lastPublishedTime) || $lastPublishedTime->diffInMinutes(now()) >= $publishInterval) {
            $news = News::where('status', NewsStatus::APPROVED)
                ->orderBy('posted_at')
                ->first();
            if ($news) {
                $this->publishNews($news);
            }
        }
    }

    /**
     * @throws Exception
     */
    private function publishNews(News $model)
    {
        if (empty($model->filename)) {
            $model->loadMediaFile();
        }

        $service = new BigCatsService();
        $image = $service->publishNews($model);
        if ($image === false) {
            Request::sendMessage([
                'chat_id' => explode(',', config('telegram.admins'))[0],
                'reply_to_message_id' => $model->message_id,
                'text' => 'News not published to BigCats!',
                'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
            ]);
        }

        Log::info($model->id . ': Publishing News');
        $response = Http::post(
            'https://maker.ifttt.com/trigger/news/with/key/' . config('services.ifttt.webhook_key'),
            [
                'value1' => $model->getShortCaption(),
                'value2' => (!empty($image) && $image !== true) ? $image : ($model->media ?: $model->getFileUrl()),
                'value3' => trim(Str::replace(
                    ['### ', '## ', '# ', '---'],
                    '',
                    Str::of(Str::inlineMarkdown($model->publish_content))->stripTags()
                )),
            ]
        );

        if ($response->successful()) {
            $model->status = NewsStatus::PUBLISHED;
            $model->published_at = now();
            $model->save();

            if ($model->message_id) {
                Request::editMessageReplyMarkup([
                    'chat_id' => explode(',', config('telegram.admins'))[0],
                    'message_id' => $model->message_id,
                    'reply_markup' => new InlineKeyboard([]),
                ]);
            }

            $connection = BlueskyConnection::where('handle', config('services.bluesky.handle'))->first();
            if ($connection) {
                try {
                    $bluesky = new Bluesky($connection);
                    $bluesky->post(['text' => $model->getShortCaption()], [['thumb' => $model->getFilePath()]]);
                } catch (Exception $e) {
                    Log::error($model->id . ': Bluesky post error: ' . $e->getMessage());
                }
            }

            if ($model->language == 'en' && !empty($model->original_content) && !empty($model->original_title)) {
                $caption = $model->original_title . "\n"
                    . $this->englishTags($model->publish_tags) . "\n"
                    . $model->link;

                Http::post(
                    'https://maker.ifttt.com/trigger/englishnews/with/key/' . config('services.ifttt.webhook_key'),
                    [
                        'value1' => $caption,
                        'value2' => (!empty($image) && $image !== true) ? $image : ($model->media ?: $model->getFileUrl()),
                        'value3' => trim(Str::replace(
                            ['### ', '## ', '# ', '---'],
                            '',
                            Str::of(Str::inlineMarkdown($model->original_content))->stripTags()
                        )),
                    ]
                );

                $connection = BlueskyConnection::where('handle', config('services.bluesky.english_handle'))->first();
                if ($connection) {
                    try {
                        $bluesky = new Bluesky($connection);
                        $bluesky->post(['text' => $caption], [['thumb' => $model->getFilePath()]]);
                    } catch (Exception $e) {
                        Log::error($model->id . ': Bluesky post error: ' . $e->getMessage());
                    }
                }
            }
        } else {
            // TODO: Make wait and check that the news is really not published

            Log::error($model->id . ': News not published! ' . $response->body());
        }

    }

    /**
     * @throws Exception
     */
    private function englishTags($tags): string
    {
        $result = [];
        foreach (explode(' ', $tags) as $tag) {
            if (isset($this->getTags('english')[$tag])) {
                $result[] = $this->getTags('english')[$tag];
            }
        }

        return implode(' ', $result);
    }

    /**
     * @throws Exception
     */
    private function loadNews(string $lang): array
    {
        Log::info('Loading news for language ' . $lang);
        $models = [];

        $query = '';
        $specieses = [];
        $words = [];
        $exclude = [];
        foreach ($this->getSpecies($lang) as $species => $data) {
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

            if (array_key_last($this->getSpecies($lang)) == $species) {
                $isSearch = true;
            }

            if ($isSearch) {
                $news = $this->service->getNews($currentQuery, $lang);

                foreach ($news as $article) {
                    try {
                        $content = $article['content'] ?? $article['summary'];
                        if (Str::length($content) > 65535) {
                            continue;
                        }

                        if (Str::length($article['title']) > 1000) {
                            $article['title'] = Str::substr($article['title'], 0, 1000);
                        }

                        $model = News::updateOrCreate([
                            'platform' => $this->service->getName(),
                            'external_id' => $article['id'] ?? $article['_id']
                        ], [
                            'date' => explode(' ', $article['published_date'])[0],
                            'author' => $article['author'],
                            'title' => $article['title'],
                            'content' => $content,
                            'link' => $article['link'],
                            'source' => !empty($article['name_source'])
                                ? $article['name_source']
                                : ($article['rights'] ?? $article['clean_url'] ?? $article['domain_url']),
                            'language' => $article['language'],
                            'posted_at' => $article['published_date'],
                        ]);
                        if (empty($model->media)) {
                            $model->media = $article['media'];
                        }

                        $this->mapSpecies($model, $currentSpecieses);
                        $models[$model->id] = $model;
                    } catch (Exception $e) {
                        Log::error('News load error: ' . $e->getMessage());

                        continue;
                    }
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

        Log::info('News loaded for language ' . $lang);

        return $models;
    }

    /**
     * @throws Exception
     */
    private function mapSpecies(News $model, $currentSpecieses = null)
    {
        $articleWords = array_unique(preg_split('/\s+/', preg_replace(
                '/[^\p{L}\p{N}\p{Zs}]/u',
                ' ',
                $model->title . ' ' . $model->content)
        ));

        if (is_null($currentSpecieses)) {
            $currentSpecieses = array_keys($this->getSpecies($model->language));
        }

        foreach ($currentSpecieses as $currentSpecies) {
            foreach ($this->getSpecies($model->language, $currentSpecies)['words'] as $word) {
                foreach ($articleWords as $articleWord) {
                    $trimmed = trim($word, '*');
                    if (
                        $word == Str::lower($articleWord)
                        || $trimmed != $word
                        && Str::length($articleWord) >= Str::length($trimmed)
                        && $trimmed == Str::substr(Str::lower($articleWord), 0, Str::length($trimmed))
                    ) {
                        if (!in_array($currentSpecies, $model->species ?? [])) {
                            $model->species = array_merge($model->species ?? [], [$currentSpecies]);
                            $model->save();
                        }

                        break;
                    }
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    private function processNews(array $models)
    {
        Log::info('Processing unprocessed news');

        /** @var News $model */
        foreach ($models as $modelIndex => $model) {
            $model->refresh();
            if (empty($model->status) || $model->status == NewsStatus::CREATED) {
                $this->excludeByTags($model);
            }

            if (empty($model->status) || $model->status == NewsStatus::CREATED) {
                $this->excludeByDupTitle($model);
            }

            $model->refresh();
            if (
                empty($model->status)
                || $model->status == NewsStatus::CREATED
                || $model->status == NewsStatus::PENDING_REVIEW
            ) {
                $model->status = NewsStatus::BEING_PROCESSED;
                $model->save();

                if (!isset($model->classification['species'])) {
                    $this->classifyNews($model, 'species');
                    $this->rejectNewsByClassification($model);

                    if ($model->status !== NewsStatus::REJECTED_BY_CLASSIFICATION) {
                        $classification = $model->classification;
                        unset($classification['species']);
                        $this->classifyNews($model, 'species', true);
                        $this->rejectNewsByClassification($model, true);
                    }

                    if (
                        config('app.is_deepest')
                        && $model->status !== NewsStatus::REJECTED_BY_DEEP_AI
                        && $model->status !== NewsStatus::REJECTED_BY_CLASSIFICATION
                    ) {
                        $classification = $model->classification;
                        unset($classification['species']);
                        $this->classifyNews($model, 'species', true, true);
                        $this->rejectNewsByClassification($model, true, true);
                    }
                }

                $model->refresh();
                if (
                    empty($model->status)
                    || $model->status == NewsStatus::CREATED
                    || $model->status == NewsStatus::PENDING_REVIEW
                    || $model->status == NewsStatus::BEING_PROCESSED
                ) {
                    if (!isset($model->classification['country'])) {
                        $this->classifyNews($model, 'country', true, true);
                    }

                    if (
                        isset($model->classification['country']['UA'])
                        && $model->classification['country']['UA'] >= 0.7
                        && !isset($model->classification['region'])
                    ) {
                        $this->classifyNews($model, 'region', true, true);
                    }

                    $model->loadMediaFile();

                    $this->preparePublish($model);

                    $model->refresh();
                    if (
                        (
                            empty($model->status)
                            || $model->status == NewsStatus::CREATED
                            || $model->status == NewsStatus::BEING_PROCESSED
                            || $model->status == NewsStatus::PENDING_REVIEW
                        )
                        && empty($model->message_id)
                    ) {
                        $this->sendNewsToReview($model);
                    }
                }

                if ($model->status == NewsStatus::BEING_PROCESSED) {
                    if (empty($model->message_id)) {
                        $model->status = NewsStatus::CREATED;
                    } else {
                        $model->status = NewsStatus::PENDING_REVIEW;
                    }
                    $model->save();
                }
            }

            unset($models[$modelIndex]);
            gc_collect_cycles();
        }

        Log::info('Unprocessed news processed');
    }

    private function sendNewsToReview(News $model)
    {
        for ($i = 0; $i < 3; $i++) {
            // TODO: Optimise this
            $telegramResult = Request::sendPhoto([
                'chat_id' => explode(',', config('telegram.admins'))[0],
                'caption' => $model->getCaption(),
                'photo' => (empty($model->media) && empty($model->getFileUrl())) || $i > 1
                    ? asset('logo.jpg')
                    : (empty($model->media) || $i > 0 ? $model->getFileUrl() : $model->media),
                'reply_markup' => $model->getInlineKeyboard(),
            ]);

            if ($telegramResult->isOk()) {
                break;
            }
        }

        if ($telegramResult->isOk()) {
            Log::info("$model->id: News sent to review result: " . json_encode($telegramResult, JSON_UNESCAPED_UNICODE));
            if (empty($model->filename)) {
                $model->media = FileHelper::getTelegramPhotoUrl($telegramResult->getResult()->getPhoto());
            }

            $model->message_id = $telegramResult->getResult()->getMessageId();
            $model->status = NewsStatus::PENDING_REVIEW;
            $model->save();
        } else {
            $model->status = NewsStatus::CREATED;
            $model->save();
            Log::error("$model->id: News not sent to review: " . $telegramResult->getDescription() . ' ' . $model->getFileUrl());
        }

    }

    /**
     * @throws Exception
     */
    private function preparePublish($model): void
    {
        if (empty($model->publish_tags)) {
            $this->preparePublishTags($model);
        }

        if (empty($model->publish_title)) {
            $model->publish_title = $model->title;
        }

        if (empty($model->publish_content)) {
            $model->publish_content = preg_replace('/(?<!\n)\n(?!\n)/', "\n\n", $model->content);
        }

        $model->save();
    }

    /**
     * @throws Exception
     */
    private function preparePublishTags(News $model): void
    {
        if (!isset($model->classification['species']) || !isset($model->classification['country'])) {
            return;
        }

        $tags = [];
        foreach ($model->classification['species'] as $key => $value) {
            if ($value >= self::SPECIES_THRESHOLD && isset($this->getTags('species')[$key])) {
                $tags[] = $this->getTags('species')[$key];
            }
        }

        $countries = [];
        foreach ($model->classification['country'] as $key => $value) {
            if ($value >= 0.7 && isset($this->getTags('country')[$key])) {
                $countries[] = $this->getTags('country')[$key];
            }
        }
        if (count($countries) <= 8) {
            $tags = array_merge($tags, $countries);
        }

        if (isset($model->classification['region'])) {
            foreach ($model->classification['region'] as $key => $value) {
                if ($value >= 0.7 && isset($this->getTags('region')[$key])) {
                    $tags[] = $this->getTags('region')[$key];
                }
            }
        }

        $model->publish_tags = implode(' ', array_unique(explode(' ', implode(' ', $tags))));
    }

    /**
     * @throws Exception
     */
    private function rejectNewsByClassification(News $model, bool $isDeep = false, bool $isDeepest = false): void
    {
        if (!isset($model->classification['species'])) {
            return;
        }

        $rejected = true;
        foreach ($model->classification['species'] as $key => $value) {
            if (isset($this->getTags('species')[$key]) && $value >= self::SPECIES_THRESHOLD) {
                $rejected = false;

                break;
            }
        }

        if ($rejected) {
            $model->status = $isDeepest
                ? NewsStatus::REJECTED_BY_DEEPEST_AI
                : ($isDeep ? NewsStatus::REJECTED_BY_DEEP_AI : NewsStatus::REJECTED_BY_CLASSIFICATION);
            $model->save();
        }
    }

    /**
     * @param string $name
     *
     * @return string
     * @throws Exception
     */
    public static function getPrompt(string $name, bool $isScience = false): string
    {
        if (!isset(static::$prompts[$name])) {
            $path = resource_path('prompts/' . $name . '.md');
            if (File::exists($path)) {
                static::$prompts[$name] = trim(implode("\n", array_map('trim', explode("\n", File::get($path)))));
            } else {
                throw new Exception('Prompt not found: ' . $name);
            }
        }

        if ($isScience) {
            return Str::replace([
                'Публіцистичн',
                'журналістик',
                'журналістськ',
                'публіцистичн',
                'журналістом',
            ], [
                'Науков',
                'наук',
                'науков',
                'науков',
                'вченим',
            ], static::$prompts[$name]);
        }

        return static::$prompts[$name];
    }

    /**
     * @throws Exception
     */
    private function getTags($name) {
        if (!isset($this->tags[$name])) {
            $path = resource_path('json/news/tags/' . $name . '.json');
            if (File::exists($path)) {
                $this->tags[$name] = json_decode(File::get($path), true);
            } else {
                throw new Exception('Tags not found: ' . $name);
            }
        }

        return $this->tags[$name];
    }

    /**
     * @throws Exception
     */
    private function getSpecies(string $lang, string $name = null): array
    {
        if (empty($this->species[$lang])) {
            $path = resource_path('json/news/species/' . $lang . '.json');
            if (File::exists($path)) {
                $this->species[$lang] = json_decode(File::get($path), true);
            } else {
                throw new Exception('Specieses not found!');
            }
        }

        if ($name && !isset($this->species[$lang][$name])) {
            throw new Exception('Species not found: ' . $name);
        }

        return $name ? $this->species[$lang][$name] : $this->species[$lang];
    }

    private function classifyNews(News $model, string $term, bool $isDeep = false, bool $isDeepest = false): void
    {
        $isDeep = $isDeep || $isDeepest;
        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News $term classification $i");
                $params = [
                    'model' => $isDeepest ? ($i % 2 ? 'openai/o4-mini-high' : 'o4-mini') : ($isDeep
                        ? ($i % 2 ? 'openai/gpt-4.1-mini' : 'gpt-4.1-mini')
                        : ($i % 2 ? 'openai/gpt-4.1-nano' : 'Qwen/Qwen2.5-32B-Instruct')
                    ),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => static::getPrompt($term)
                        ],
                        ['role' => 'user', 'content' => $model->title . "\n\n" . $model->content]
                    ],
                ];
                if ($i < 2) {
                    $params['response_format'] = ['type' => 'json_object'];
                }
                if (!$isDeepest) {
                    $params['temperature'] = 0;
                }
                if ($isDeep && !($i % 2)) {
                    if ($isDeepest) {
                        $params['reasoning_effort'] = 'high';
                    }
                    $classificationResponse = OpenAI::chat()->create($params);
                } else {
                    if ($i < 2) {
                        if (!$isDeep) {
                            $params['presence_penalty'] = 2;
                        }
                        $params['provider'] = ['require_parameters' => true];
                    }
                    $classificationResponse = AI::client(($i % 2) ? 'openrouter' : 'nebius')->chat()->create($params);
                }

                Log::info(
                    "$model->id: News $term classification $i result: "
                    . json_encode($classificationResponse, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($classificationResponse->choices[0]->message->content)) {
                    $classification = $model->classification ?? [];
                    $content = Str::after($classificationResponse->choices[0]->message->content, '</think>');
                    $content = Str::after($content, '```json');
                    $content = '{' . Str::after($content, '{');
                    $content = Str::before($content, '}') . '}';
                    $classification[$term] = json_decode($content, true);

                    if (!is_array($classification[$term])) {
                        throw new Exception('Invalid classification ' . $i);
                    }

                    $model->classification = $classification;
                    $model->save();
                }
            } catch (Throwable $e) {
                $classification = $model->classification ?? [];
                unset($classification[$term]);
                $model->classification = $classification;

                Log::error("$model->id: News $term classification $i fail: {$e->getMessage()}");
            }

            unset($chat);
            unset($classificationResponse);
            gc_collect_cycles();

            if (isset($model->classification[$term])) {
                break;
            }
        }
    }

    private function excludeByDupTitle(News $model)
    {
        if (empty($this->previousWeekNews[$model->language])) {
            $this->previousWeekNews[$model->language] = News::where('language', $model->language)
                ->where('posted_at', '>', now()->subWeek()->toDateTimeString())
                ->select('id', 'title')
                ->get();
        }

        /** @var News $previousModel */
        foreach ($this->previousWeekNews[$model->language] as $previousModel) {
            if ($model->id == $previousModel->id) {
                continue;
            }

            similar_text($model->title, $previousModel->title, $percent);

            if ($percent >= 70) {
                if (empty($previousModel->posted_at)) {
                    $previousModel->append(['content', 'posted_at', 'status', 'is_translated'])->refresh();
                }
                if (
                    (
                        $model->posted_at->format('H:i:s') == '00:00:00'
                        && $previousModel->posted_at->format('H:i:s') != '00:00:00'
                        || $model->posted_at->format('H:i:s') != '00:00:00'
                        && $previousModel->posted_at->format('H:i:s') != '00:00:00'
                        && $model->posted_at > $previousModel->posted_at
                        || $model->posted_at == $previousModel->posted_at
                        && Str::length($model->content) < Str::length($previousModel->content)
                    )
                    && $model->status != NewsStatus::PUBLISHED
                    && $model->status != NewsStatus::APPROVED
                    && !$model->is_translated
                ) {
                    $previousModel->append(['classification'])->refresh();
                    $this->rejectByDupTitle($model, $previousModel);

                    return;
                } elseif (
                    $previousModel->status != NewsStatus::PUBLISHED
                    && $previousModel->status != NewsStatus::APPROVED
                    && !$previousModel->is_translated
                ) {
                    $previousModel->append(['classification', 'message_id', 'filename'])->refresh();
                    $this->rejectByDupTitle($previousModel, $model);
                }
            } elseif ($percent >= 50) {
                Log::warning(
                    "$model->id vs $previousModel->id: News title similarity: $percent%: $model->title vs $previousModel->title"
                );
            }
        }
    }

    private function rejectByDupTitle(News $model, News $previousModel)
    {
        if (empty($previousModel->classification) && !empty($model->classification)) {
            $previousModel->classification = $model->classification;
            if (
                $model->status != NewsStatus::PENDING_REVIEW
                && $model->status != NewsStatus::BEING_PROCESSED
                && $model->status != NewsStatus::PUBLISHED
            ) {
                $previousModel->status = $model->status;
            }

            $previousModel->save();
        }

        if ($model->is_translated || in_array($model->status, [
            NewsStatus::REJECTED_BY_DUP_TITLE,
            NewsStatus::PUBLISHED,
            NewsStatus::APPROVED,
        ])) {
            return;
        }

        Log::info("$model->id: News rejected by dup title: $previousModel->id");
        $model->deleteFile();
        if ($model->message_id) {
            $response = Request::deleteMessage([
                'chat_id' => explode(',', config('telegram.admins'))[0],
                'message_id' => $model->message_id,
            ]);
            if ($response->isOk()) {
                $model->message_id = null;
            }
        }
        $model->status = NewsStatus::REJECTED_BY_DUP_TITLE;
        $model->save();
    }

    /**
     * @throws Exception
     */
    private function excludeByTags(News $model)
    {
        $text = $model->content;
        if ($model->language != 'en') {
            $text = $model->title . "\n\n" . $text;
        }

        if (empty($model->species)) {
            $model->status = NewsStatus::REJECTED_BY_KEYWORD;
            $model->save();

            return;
        }

        foreach ($model->species as $species) {
            foreach ($this->getSpecies($model->language, $species)['excludeCase'] ?? [] as $excludeCase) {
                $lastPosition = 0;
                while (($lastPosition = Str::position($text, $excludeCase, $lastPosition)) !== false) {
                    if (
                        $lastPosition > 1
                        && $model->language != 'en'
                        && Str::charAt($text, $lastPosition - 1) == ' '
                        && !in_array(Str::charAt($text, $lastPosition - 2), ["\n", '.', '!', '?', '…', '-', '–', '—', '―'])
                        || $lastPosition > 2
                        && in_array(Str::charAt($text, $lastPosition - 1), ['«', '"', "'", '[', '('])
                        && Str::charAt($text, $lastPosition - 2)  == ' '
                        && !in_array(Str::charAt($text, $lastPosition - 3), [':', '-', '–', '—', '―'])
                        || $lastPosition > 1
                        && Str::substr($text, $lastPosition - 2, 2) == 'A '
                        || $lastPosition > 3
                        && Str::substr($text, $lastPosition - 4, 4) == 'The '
                    ) {
                        Log::info(
                            "$model->id: News rejected by keyword: "
                            . Str::substr($text, $lastPosition - 2, Str::length($excludeCase) + 2)
                        );
                        $model->status = NewsStatus::REJECTED_BY_KEYWORD;
                        $model->save();

                        return;
                    }

                    $lastPosition = $lastPosition + Str::length($excludeCase);
                }
            }
        }
    }

    /**
     * @param News $model
     * @param Message $message
     *
     * @return void
     * @throws Exception
     */
    public function approve(News $model, Message $message): void
    {
        $model->status = NewsStatus::APPROVED;
        $model->save();

        if (empty($model->filename)) {
            $model->loadMediaFile();
        }

        Request::editMessageReplyMarkup([
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'reply_markup' => new InlineKeyboard([
                ['text' => '❌Cancel Approval', 'callback_data' => 'news_cancel ' . $model->id],
            ]),
        ]);
    }

    /**
     * @param News $model
     * @param Message $message
     *
     * @return void
     */
    public function cancel(News $model, Message $message): void
    {
        $model->status = NewsStatus::PENDING_REVIEW;
        $model->save();

        Request::editMessageReplyMarkup([
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'reply_markup' => $model->getInlineKeyboard(),
        ]);
    }

    /**
     * @param News $model
     * @param Message $message
     *
     * @return void
     */
    public function decline(News $model, Message $message): void
    {
        $model->status = NewsStatus::REJECTED_MANUALLY;
        $model->save();

        $this->delete($model, $message);
    }

    /**
     * @param News $model
     * @param Message $message
     *
     * @return void
     */
    public function offtopic(News $model, Message $message): void
    {
        $model->status = NewsStatus::REJECTED_AS_OFF_TOPIC;
        $model->save();

        $this->delete($model, $message);
    }

    /**
     * @param News $model
     * @param Message $message
     *
     * @return void
     */
    public function delete(News $model, Message $message): void
    {
        $model->deleteFile();

        $response = Request::deleteMessage([
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
        ]);

        if ($response->isOk()) {
            $model->message_id = null;
            $model->save();
        }
    }

    /**
     * @throws TelegramException
     */
    public function content(News $model, Message $message): void
    {
        $parts = explode("\n\n", $model->publish_content);
        $parts[] = '';
        $text = '';
        $i = 0;
        foreach ($parts as $key => $part) {
            $newText = $text ? $text . "\n\n" . $part : $part;
            if (Str::length($newText) > 4000 || $key == count($parts) - 1) {
                Request::sendMessage([
                    'chat_id' => $message->getChat()->getId(),
                    'reply_to_message_id' => $message->getMessageId(),
                    'text' => "```\n$text\n```",
                    'parse_mode' => 'Markdown',
                    'reply_markup' => new InlineKeyboard([
                        [
                            'text' => 'Edit',
                            'switch_inline_query_current_chat' => 'news_content ' . $model->id . ' ' . $i . ' '
                        ],
                        ['text' => '❌Delete', 'callback_data' => 'delete'],
                    ]),
                ]);

                $i++;
                $text = $part;
            } else {
                $text = $newText;
            }
        }
    }

    public function translate(News $model, Message $message): void
    {
        if ($model->language == 'uk' || $model->is_translated || $model->is_deepest) {
            $model->updateReplyMarkup();
            return;
        }

        TranslateNewsJob::dispatch($model->id);
    }

    public function auto(News $model, Message $message): void
    {
        if ($model->language == 'uk' || $model->is_translated || $model->is_deepest) {
            $model->updateReplyMarkup();
            return;
        }

        $model->is_auto = true;
        $model->save();
        TranslateNewsJob::dispatch($model->id);
    }

    public function analyze(News $model, Message $message): void
    {
        if ($model->language == 'uk' || !$model->is_translated || !empty($model->analysis) || $model->is_deepest) {
            $model->updateReplyMarkup();
            return;
        }

        AnalyzeNewsJob::dispatch($model->id);
    }

    /**
     * @throws TelegramException
     */
    public function analysis(News $model, Message $message): void
    {
        if (empty($model->analysis)) {
            return;
        }

        Request::sendMessage([
            'chat_id' => $message->getChat()->getId(),
            'reply_to_message_id' => $model->message_id,
            'text' => $model->analysis,
            'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
        ]);
    }

    public function reset(News $model, Message $message): void
    {
        if ($model->is_auto) {
            $model->is_auto = false;
        } elseif ($model->is_deepest) {
            $model->is_deepest = false;
        } else {
            $model->is_deep = false;
        }

        $this->cancel($model, $message);
    }

    public function counter(News $model, Message $message): void
    {
        $model->analysis_count = 0;
        $model->save();
        Request::editMessageReplyMarkup([
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'reply_markup' => $model->getInlineKeyboard(),
        ]);
    }

    public function translation(News $model, Message $message): void
    {
        $model->publish_title = $model->original_title;
        $model->publish_content = $model->original_content;
        $model->analysis = null;
        $model->analysis_count = 0;
        $model->is_deepest = false;
        $model->is_deep = false;
        $model->is_translated = false;

        $this->reset($model, $message);
    }

    public function apply(News $model, Message $message): void
    {
        if ($model->language == 'uk' || !$model->is_translated || empty($model->analysis) || $model->is_deepest) {
            $model->updateReplyMarkup();
            return;
        }

        ApplyNewsAnalysisJob::dispatch($model->id);
    }

    public function deep(News $model, Message $message): void
    {
        if ($model->language == 'uk' || !$model->is_translated || $model->is_deepest) {
            $model->updateReplyMarkup();
            return;
        }

        $model->analysis_count = 0;
        $model->is_deep = true;
        $model->analysis = null;
        $model->save();
    }

    public function deepest(News $model, Message $message): void
    {
        if ($model->language == 'uk' || !$model->is_translated || $model->is_deepest) {
            $model->updateReplyMarkup();
            return;
        }

        $model->is_deepest = true;
        $model->save();
    }
}
