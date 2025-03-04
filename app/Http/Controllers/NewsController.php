<?php

namespace App\Http\Controllers;

use App\Bluesky;
use App\Enums\FlickrPhotoStatus;
use App\Enums\NewsStatus;
use App\Helpers\FileHelper;
use App\Jobs\AnalyzeNewsJob;
use App\Jobs\ApplyNewsAnalysisJob;
use App\Jobs\TranslateNewsJob;
use App\Models\BlueskyConnection;
use App\Models\FlickrPhoto;
use App\Models\News;
use App\Services\BigCatsService;
use App\Services\NewsCatcherService;
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

class NewsController extends Controller
{
    private const LOAD_TIME = '18:00:00';

    private const STOP_TIME = '21:00:00';

    private const PUBLISH_AFTER = '07:00:00';

    private const PUBLISH_BEFORE = '22:00:00';

    private array $species = [];

    private array $tags = [];

    private static array $prompts = [];

    private array $previousWeekNews = [];

    private NewsServiceInterface|null $service;

    public function __construct(NewsCatcherService $service)
    {
        $this->service = $service;
    }

    /**
     * @throws Exception
     */
    public function process($load = true, $force = false, $lang = null): void
    {
        Log::info('Processing news ' . $lang);
        $this->publish();

        $models = [];
        if ($force || (
            $load
            && now()->format('H:i:s') >= self::LOAD_TIME
            && now()->format('H:i:s') < self::STOP_TIME
            && in_array(now()->format('G') % 3, [0, 1])
        )) {
            $models = $this->loadNews($lang ?? ((now()->format('G') % 3 == 1) ? 'en' : 'uk'));
        }

        foreach (News::whereIn('status', [
            NewsStatus::CREATED,
            NewsStatus::PENDING_REVIEW,
        ])->whereNotIn('id', array_keys($models))->get() as $model) {
            $models[$model->id] = $model;
        }

        $this->processNews($models);

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
        } else {
            // TODO: Make wait and check that the news is really not published

            Log::error($model->id . ': News not published! ' . $response->body());
        }

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
                        if (Str::length($article['title']) > 1000) {
                            $article['title'] = Str::substr($article['title'], 0, 1000);
                        }

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
        foreach ($models as $model) {
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
                if (!isset($model->classification['species'])) {
                    $this->classifyNews($model, 'species');
                    $this->rejectNewsByClassification($model);

                    if ($model->status !== NewsStatus::REJECTED_BY_CLASSIFICATION) {
                        $classification = $model->classification;
                        unset($classification['species']);
                        $this->classifyNews($model, 'species', true);
                        $this->rejectNewsByClassification($model, true);
                    }

                    if (config('openai.is_deepest') && $model->status !== NewsStatus::REJECTED_BY_DEEP_AI) {
                        $classification = $model->classification;
                        unset($classification['species']);
                        $this->classifyNews($model, 'species', true, true);
                        $this->rejectNewsByClassification($model, true);
                    }

                    if (
                        $model->status === NewsStatus::REJECTED_BY_CLASSIFICATION
                        || $model->status === NewsStatus::REJECTED_BY_DEEP_AI
                    ) {
                        continue;
                    }
                }
                $this->rejectNewsByClassification($model);
                if (
                    $model->status === NewsStatus::REJECTED_BY_CLASSIFICATION
                    || $model->status === NewsStatus::REJECTED_BY_DEEP_AI
                ) {
                    continue;
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

                $model->loadMediaFile();

                $this->preparePublish($model);

                $model->refresh();
                if (
                    empty($model->status)
                    || $model->status == NewsStatus::CREATED
                    || $model->status == NewsStatus::PENDING_REVIEW && empty($model->message_id)
                ) {
                    $this->sendNewsToReview($model);
                }
            }
        }
    }

    private function sendNewsToReview(News $model)
    {
        for ($i = 0; $i < 2; $i++) {
            $telegramResult = Request::sendPhoto([
                'chat_id' => explode(',', config('telegram.admins'))[0],
                'caption' => $model->getCaption(),
                'photo' => empty($model->getFileUrl()) || $i > 0 ? asset('logo.jpg') : $model->getFileUrl(),
                'reply_markup' => $model->getInlineKeyboard(),
            ]);

            if ($telegramResult->isOk()) {
                break;
            }
        }

        if ($telegramResult->isOk()) {
            $model->message_id = $telegramResult->getResult()->getMessageId();
            $model->status = NewsStatus::PENDING_REVIEW;
            $model->save();
        } else {
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
            if ($value >= 0.7 && isset($this->getTags('species')[$key])) {
                $tags[] = $this->getTags('species')[$key];
            }
        }

        foreach ($model->classification['country'] as $key => $value) {
            if ($value >= 0.7 && isset($this->getTags('country')[$key])) {
                $tags[] = $this->getTags('country')[$key];
            }
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
    private function rejectNewsByClassification(News $model, bool $isDeep = false): void
    {
        if (!isset($model->classification['species'])) {
            return;
        }

        $rejected = true;
        foreach ($model->classification['species'] as $key => $value) {
            if (isset($this->getTags('species')[$key]) && $value >= 0.7) {
                $rejected = false;

                break;
            }
        }

        if ($rejected) {
            $model->status = $isDeep ? NewsStatus::REJECTED_BY_DEEP_AI : NewsStatus::REJECTED_BY_CLASSIFICATION;
            $model->save();
        }
    }

    /**
     * @param string $name
     *
     * @return string
     * @throws Exception
     */
    public static function getPrompt(string $name): string
    {
        if (!isset(static::$prompts[$name])) {
            $path = resource_path('prompts/' . $name . '.md');
            if (File::exists($path)) {
                static::$prompts[$name] = File::get($path);
            } else {
                throw new Exception('Prompt not found: ' . $name);
            }
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
                Log::info("$model->id: News $term classification");
                $params = [
                    'model' => $isDeepest ? 'o1-preview' : ($isDeep ? 'gpt-4o' : 'gpt-4o-mini'),
                    'messages' => [
                        [
                            'role' => $isDeepest ? 'user' : 'developer',
                            'content' => static::getPrompt($term)
                        ],
                        ['role' => 'user', 'content' => $model->title . "\n\n" . $model->content]
                    ],
                    'temperature' => $isDeepest ? 1 : 0,
                ];
                if (!$isDeepest) {
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

    private function excludeByDupTitle(News $model)
    {
        if (empty($this->previousWeekNews[$model->language])) {
            $this->previousWeekNews[$model->language] = News::where('language', $model->language)
                ->where('posted_at', '>', now()->subWeek()->toDateTimeString())
                ->get();
        }

        /** @var News $previousModel */
        foreach ($this->previousWeekNews[$model->language] as $previousModel) {
            if ($model->id == $previousModel->id) {
                continue;
            }

            similar_text($model->title, $previousModel->title, $percent);

            if ($percent >= 70) {
                $previousModel->refresh();
                if (
                    (
                        $model->posted_at->format('H:i:s') == '00:00:00'
                        && $previousModel->posted_at->format('H:i:s') != '00:00:00'
                        || (
                            $model->posted_at->format('H:i:s') != '00:00:00'
                            && $previousModel->posted_at->format('H:i:s') != '00:00:00'
                            || $model->posted_at->format('H:i:s') == '00:00:00'
                            && $previousModel->posted_at->format('H:i:s') == '00:00:00'
                        )
                        && $model->posted_at > $previousModel->posted_at
                        || ($model->posted_at == $previousModel->posted_at)
                        && Str::length($model->content) < Str::length($previousModel->content)
                    )
                    && $model->status != NewsStatus::PUBLISHED
                    && $model->status != NewsStatus::APPROVED
                    && !$model->is_translated
                ) {
                    $this->rejectByDupTitle($model, $previousModel);

                    return;
                } else {
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
        $model->status = NewsStatus::PENDING_REVIEW;
        $model->is_auto = false;
        $model->save();
        Request::editMessageReplyMarkup([
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'reply_markup' => $model->getInlineKeyboard(),
        ]);
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
