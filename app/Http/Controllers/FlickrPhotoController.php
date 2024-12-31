<?php

namespace App\Http\Controllers;

use App\Bluesky;
use App\Enums\FlickrPhotoStatus;
use App\Models\BlueskyConnection;
use App\Models\ExcludedTag;
use App\Models\FlickrPhoto;
use App\MyCloudflareAI;
use DeepL\Translator;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JeroenG\Flickr\FlickrLaravelFacade;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

/**
 * Class FlickrPhotoController
 * @package App\Http\Controllers
 */
class FlickrPhotoController extends Controller
{
    private const TEXT_TAGS_COUNT = 12;

    public const TAGS = [
        'lion' => '#лев',
        'tiger' => '#тигр',
        'panther' => '#пантера',
        'leopard' => '#леопард',
        'jaguar' => '#ягуар',
        'cheetah' => '#гепард',
        'ocelot' => '#оцелот',
        'lynx' => '#рись',
        'puma' => '#пума',
        'cougar' => '#пума',
        'caracal' => '#каракал',
        'serval' => '#сервал',
        'lioness' => '#лев',
        'whitelion' => '#лев #білийлев',
        'tigress' => '#тигр',
        'whitetiger' => '#тигр #білийтигр',
        'snowleopard' => '#ірбіс',
        'blackleopard' => '#леопард #пантера',
        'leopardess' => '#леопард',
        'blackjaguar' => '#ягуар #пантера',

        'snep' => '#ірбіс',
        'schneeleopard' => '#ірбіс',
        'irbis' => '#ірбіс',
        'blackpanther' => '#пантера',
        'lions' => '#лев',
        'lionesses' => '#лев',
        'whitelions' => '#лев #білийлев',
        'tigers' => '#тигр',
        'tigresses' => '#тигр',
        'whitetigers' => '#тигр #білийтигр',
        'panthers' => '#пантера',
        'snowleopards' => '#ірбіс',
        'blackleopards' => '#леопард #пантера',
        'leopards' => '#леопард',
        'leopardesses' => '#леопард',
        'jaguars' => '#ягуар',
        'blackjaguars' => '#ягуар #пантера',
        'cheetahs' => '#гепард',
        'ocelots' => '#оцелот',
        'lynxes' => '#рись',

        'pumas' => '#пума',
        'cougars' => '#пума',
        'caracals' => '#каракал',
        'servals' => '#сервал',
        'sneps' => '#ірбіс',
        'irbises' => '#ірбіс',
        'blackpanthers' => '#пантера',
        'mountainlion' => '#пума',
        'mountainlions' => '#пума',
        'leopardcub' => '#леопард',
        'bigcats' => '',
        'bigcat' => '',
        'lioncub' => '#лев',
        'tigercub' => '#тигр',
        'jaguarcub' => '#ягуар',
        'cheetahcub' => '#гепард',
        'lynxcub' => '#рись',
        'kingcheetah' => '#гепард #королівськийгепард',
        'pumacub' => '#пума',
        'cloudedleopard' => '#димчастапантера',

        'maleleopardathide' => '#леопард',
        'kingcheetahs' => '#гепард #королівськийгепард',
        'lioncubs' => '#лев',
        'tigercubs' => '#тигр',
        'jaguarcubs' => '#ягуар',
        'cheetahcubs' => '#гепард',
        'lynxcubs' => '#рись',
        'cloudedleopards' => '#димчастапантера',
        'malelion' => '#лев',
        'maletiger' => '#тигр',
        'malepanther' => '#пантера',
        'maleleopard' => '#леопард',
        'malejaguar' => '#ягуар',
        'malecheetah' => '#гепард',
        'femalelion' => '#лев',
        'femaletiger' => '#тигр',
        'femalepanther' => '#пантера',
        'femaleleopard' => '#леопард',
        'femalejaguar' => '#ягуар',
        'femalecheetah' => '#гепард',
    ];

    private const LICENSES = [
        1 => 'Attribution-NonCommercial-ShareAlike License',
        2 => 'Attribution-NonCommercial License',
        3 => 'Attribution-NonCommercial-NoDerivs License',
        4 => 'Attribution License',
        5 => 'Attribution-ShareAlike License',
        6 => 'Attribution-NoDerivs License',
        7 => 'No known copyright restrictions',
        9 => 'Public Domain Dedication (CC0)',
        10 => 'Public Domain Mark',
    ];

    private const LOAD_TIME = '16:00:00';

    public const DAILY_PUBLISH_COUNT_LIMIT = 4;

    /**
     * @var string[];
     */
    private array $excludedTags = [];

    /**
     * @return string[]
     */
    private function getExcludedTags(): array
    {
        if (empty($this->excludedTags)) {
            $this->excludedTags = ExcludedTag::query()->pluck('name')->all();
        }

        return $this->excludedTags;
    }

    /**
     * @return void
     */
    public function process(): void
    {
        Log::info('Processing Flickr photos');
        $this->publish();

        $models = [];
        if (now()->format('H:i:s') >= self::LOAD_TIME) {
            $queueSize = FlickrPhoto::query()->whereIn('status', [
                FlickrPhotoStatus::APPROVED,
                FlickrPhotoStatus::PENDING_REVIEW,
            ])->count();

            $models = $this->loadPhotos($queueSize <= self::DAILY_PUBLISH_COUNT_LIMIT);
        }

        foreach (FlickrPhoto::query()->whereIn('status', [
            FlickrPhotoStatus::CREATED,
            FlickrPhotoStatus::PENDING_REVIEW,
        ])->whereNotIn('id', array_keys($models))->get() as $model) {
            $models[$model->id] = $model;
        }

        $this->processPhotos($models);

        $this->deletePhotoFiles();
    }

    /**
     * @return void
     */
    private function deletePhotoFiles(): void
    {
        /** @var FlickrPhoto[] $models */
        $models = FlickrPhoto::query()
            ->where('status', FlickrPhotoStatus::PUBLISHED)
            ->whereNotNull('filename')
            ->where('published_at', '<', now()->subDays(2)->toDateTimeString())
            ->get();

        foreach ($models as $model) {
            $model->deleteFile();

            if ($model->message_id) {
                Request::editMessageCaption([
                    'chat_id' => explode(',', config('telegram.admins'))[0],
                    'message_id' => $model->message_id,
                    'caption' => '',
                ]);
            }
        }

        $models = FlickrPhoto::query()
            ->whereIn('status', [
                FlickrPhotoStatus::REJECTED_BY_TAG,
                FlickrPhotoStatus::REJECTED_MANUALLY,
            ])
            ->whereNotNull('filename')
            ->get();

        foreach ($models as $model) {
            $model->deleteFile();
        }
    }

    /**
     * @return int
     */
    private function getDailyPublishCount(): int
    {
        $pendingPublishSize = FlickrPhoto::query()->where('status', FlickrPhotoStatus::APPROVED)->count();
        $publishedLastMonth = FlickrPhoto::query()
            ->where('status', FlickrPhotoStatus::PUBLISHED)
            ->where('published_at', '>', now()->subDays(30)->toDateTimeString())
            ->count();
        $targetPublishRate = self::DAILY_PUBLISH_COUNT_LIMIT *  30 * 30 / max($publishedLastMonth, 1);

        $dailyPublishCount = $pendingPublishSize  > self::DAILY_PUBLISH_COUNT_LIMIT * $targetPublishRate ? (int) ceil(
            24 / max(floor(24 / ceil($pendingPublishSize / $targetPublishRate)), 3)
        ) : min(max($pendingPublishSize - 1, 1), self::DAILY_PUBLISH_COUNT_LIMIT);

        Log::info(sprintf(
            'Pending publish: %d; last month: %d; target rate: %0.2f; daily rate: %0.2f; daily count: %d',
            $pendingPublishSize,
            $publishedLastMonth,
            $targetPublishRate,
            $pendingPublishSize / $targetPublishRate,
            $dailyPublishCount
        ));

        return $dailyPublishCount;
    }

    /**
     * @return void
     */
    private function publish(): void
    {
        $dailyPublishCount = $this->getDailyPublishCount();
        $now = now()->format('m-d H:i');
        if (($now > '12-31 09:30' && $now <= '12-31 23:59') || ($now >= '01-01 00:00' && $now < '01-01 12:30')) {
            $dailyPublishCount = 24;
            Log::info('Happy New Year!');
        }

        /** @var FlickrPhoto[] $lastPublishedPhotos */
        $lastPublishedPhotos = FlickrPhoto::where('status', FlickrPhotoStatus::PUBLISHED)
            ->latest('published_at')
            ->limit(max($dailyPublishCount - 1, 1))
            ->get()
            ->all();
        $publishInterval = intdiv(1440, $dailyPublishCount) - 30;

        if (
            empty($lastPublishedPhotos[0]->published_at)
            || now()->subMinutes($publishInterval)->gt($lastPublishedPhotos[0]->published_at)
        ) {
            Log::info('Publishing Flickr photos');
            /** @var FlickrPhoto[] $photosToPublish */
            $photosToPublish = FlickrPhoto::whereIn('status', [
                FlickrPhotoStatus::APPROVED,
                FlickrPhotoStatus::PENDING_REVIEW
            ])->get()->all();

            Log::info('Publish queue size: ' . count($photosToPublish));

            if (!empty($photosToPublish)) {
                $photoToPublish = array_shift($photosToPublish);
                foreach ($photosToPublish as $photo) {
                    $comparison = $photo->status->value <=> $photoToPublish->status->value
                        ?: $photo->publishScore($lastPublishedPhotos) <=> $photoToPublish->publishScore($lastPublishedPhotos)
                            ?: $photo->publishTagsScore() <=> $photoToPublish->publishTagsScore()
                                ?: $photo->classificationScore() <=> $photoToPublish->classificationScore()
                                    ?: $photoToPublish->posted_at <=> $photo->posted_at;

                    if ($comparison > 0) {
                        $photoToPublish = $photo;
                    }
                }

                $this->publishPhoto($photoToPublish);
            }
        }
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return string
     */
    private function loadPhotoLicense(FlickrPhoto $model): string
    {
        $result = '';
        $licenseResponse = FlickrLaravelFacade::request('flickr.photos.licenses.getLicenseHistory', [
            'photo_id' => $model->id,
        ]);

        $dateChange = 0;
        if ($licenseResponse->getStatus() == 'ok') {
            Log::info($model->id . ': License history: ' . json_encode($licenseResponse->license_history));

            foreach ($licenseResponse->license_history as $license) {
                if (empty($license['new_license'])) {
                    $result = $license['old_license'];

                    break;
                } elseif ($license['date'] > $dateChange) {
                    $result = $license['new_license'];
                    $dateChange = $license['date'];
                }
            }
        }

        return $result;
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return string|null
     */
    private function checkPhotoFile(FlickrPhoto $model): ?string
    {
        $errorMessage = null;

        if (empty($model->filename)) {
            $this->loadPhotoFile($model);

            if (empty($model->filename)) {
                $errorMessage = 'Photo file missing!';
                $model->status = FlickrPhotoStatus::PENDING_REVIEW;
                $model->save();
            }
        }

        return $errorMessage;
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return string|null
     */
    private function checkLicense(FlickrPhoto $model): ?string
    {
        $errorMessage = null;

        if (!in_array($this->loadPhotoLicense($model), self::LICENSES)) {
            $errorMessage = 'The author removed the photo from the publication!';
            $model->status = FlickrPhotoStatus::REMOVED_BY_AUTHOR;
            $model->save();
        }

        return $errorMessage;
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return bool
     */
    private function isPublishingAllowed(FlickrPhoto $model): bool
    {
        $errorMessage = $this->checkPhotoFile($model);

        if (is_null($errorMessage)) {
            $errorMessage = $this->checkLicense($model);
        }

        if (!empty($errorMessage)) {
            Log::error($model->id . ': ' . $errorMessage);
            try {
                Request::editMessageReplyMarkup([
                    'chat_id' => explode(',', config('telegram.admins'))[0],
                    'message_id' => $model->message_id,
                    'reply_markup' => $model->getInlineKeyboard(),
                ]);
                Request::sendMessage([
                    'chat_id' => explode(',', config('telegram.admins'))[0],
                    'reply_to_message_id' => $model->message_id,
                    'text' => $errorMessage,
                    'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                ]);
            } catch (Exception $e) {
                Log::error($model->id . ': Error sending error message: ' . $e->getMessage());
            }

            return false;
        }

        return true;
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function publishPhoto(FlickrPhoto $model): void
    {
        if (!$this->isPublishingAllowed($model)) {
            $this->publish();

            return;
        }

        if (empty($model->source_url)) {
            $this->loadPhotoFile($model);
        }

        Log::info($model->id . ': Publishing Flickr photo');
        $response = Http::post(
            'https://maker.ifttt.com/trigger/flickr_photo/with/key/' . config('services.ifttt.webhook_key'),
            [
                'value1' => $model->getCaption(),
                'value2' => $model->source_url ?? $model->getFileUrl(),
            ]
        );

        if ($response->successful()) {
            $model->status = FlickrPhotoStatus::PUBLISHED;
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
                    $bluesky->post(['text' => $model->getCaption()], [['path' => $model->getFilePath()]]);
                } catch (Exception $e) {
                    Log::error($model->id . ': Bluesky post error: ' . $e->getMessage());
                }
            }
        } else {
            // TODO: Make wait and check that the photo is really not published

            Log::error($model->id . ': Flickr photo not published! ' . $response->body());
        }
    }

    /**
     * @param FlickrPhoto[] $models
     *
     * @return void
     */
    private function processPhotos(array $models): void
    {
        foreach ($models as $model) {
            if (empty($model->url)) {
                $this->loadPhotoInfo($model);
            }

            if (empty($model->status) || $model->status == FlickrPhotoStatus::CREATED) {
                $this->excludeByTags($model);
            }

            $model->refresh();
            if (
                empty($model->status)
                || $model->status == FlickrPhotoStatus::CREATED
                || $model->status == FlickrPhotoStatus::PENDING_REVIEW
            ) {
                if (empty($model->filename) || empty($model->classification)) {
                    $this->loadPhotoFile($model);
                }

                if (
                    (empty($model->classification) || !empty($model->classification['filename']))
                    && !empty($model->filename)
                ) {
                    $this->classifyPhoto($model);
                }

                if (!empty($model->classification)) {
                    $rejected = true;
                    foreach ($model->classification as $classification) {
                        if (isset(
                            self::TAGS[strtolower(str_replace(' ', '', $classification['label']))]
                        )) {
                            $rejected = false;

                            break;
                        }
                    }

                    if ($rejected && $model->status != FlickrPhotoStatus::PENDING_REVIEW) {
                        $this->rejectPhotoByClassification($model);
                    } else {
                        if (empty($model->publish_tags)) {
                            $this->preparePublishTags($model);
                        }

                        if (empty($model->publish_title)) {
                            $this->preparePublishTitle($model);
                        }
                    }

                    $model->refresh();
                    if (
                        empty($model->status)
                        || $model->status == FlickrPhotoStatus::CREATED
                        || $model->status == FlickrPhotoStatus::PENDING_REVIEW && empty($model->message_id)
                    ) {
                        $this->sendPhotoToReview($model);
                    }
                }
            }
        }
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function excludeByTags(FlickrPhoto $model): void
    {
        $hasTag = !empty(array_intersect_key(self::TAGS, array_flip($model->tags)));

        foreach ($model->tags as $tag) {
            foreach ($this->getExcludedTags() as $excludedTag) {
                if (
                    (!$hasTag && Str::contains($tag, $excludedTag, true))
                    || ($hasTag && $tag == $excludedTag)
                ) {
                    Log::info($model->id . ': Rejected by tag! ' . $excludedTag);
                    $this->rejectByTag($model);

                    return;
                }
            }
        }

        if (!$hasTag) {
           foreach ($this->getExcludedTags() as $excludedTag) {
               if (
                   Str::contains($model->title, $excludedTag, true)
                   || Str::contains($model->description, $excludedTag, true)
               ) {
                   Log::info($model->id . ': Rejected by title ot description! ' . $excludedTag);
                   $this->rejectByTag($model);

                   return;
               }
           }
        }
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function rejectByTag(FlickrPhoto $model): void
    {
        $model->status = FlickrPhotoStatus::REJECTED_BY_TAG;
        $model->save();
        $model->deleteFile();

        if ($model->message_id) {
            Request::deleteMessage([
                'chat_id' => explode(',', config('telegram.admins'))[0],
                'message_id' => $model->message_id,
            ]);
        }

        if (FlickrPhoto::where('owner', $model->owner)->whereIn('status', [
            FlickrPhotoStatus::PENDING_REVIEW,
            FlickrPhotoStatus::APPROVED,
            FlickrPhotoStatus::PUBLISHED,
        ])->exists()) {
            try {
                Request::sendMessage([
                    'chat_id' => explode(',', config('telegram.admins'))[0],
                    'text' => implode(' ', $model->tags) . "\n" . $model->url,
                    'reply_markup' => new InlineKeyboard([
                        ['text' => 'Remove Excluded Tag', 'switch_inline_query_current_chat' => 'deleteexcludedtag '],
                    ], [
                        ['text' => '❌Delete', 'callback_data' => 'flickr_delete ' . $model->id],
                        ['text' => '✅Review', 'callback_data' => 'flickr_review ' . $model->id],
                    ]),
                ]);
            } catch (Exception $e) {
                Log::error($model->id . ': Reject by tag message fail! ' . $e->getMessage());
            }
        }
    }

    /**
     * @return FlickrPhoto[]
     */
    private function loadPhotos(bool $byText = false): array
    {
        Log::info('Loading today Flick photos, byText: ' . json_encode($byText));
        $photos = [];
        for ($i = 0; $i < self::TEXT_TAGS_COUNT; $i++) {
            $searchData = [
                'min_upload_date' => now()->subDays(2)->timestamp,
                'sort' => 'date-posted-asc',
                'content_types' => '0',
                'license' => implode(',', array_keys(self::LICENSES)),
                'per_page' => 500,
            ];

            if ($byText) {
                $searchData['text'] = key(array_slice(self::TAGS, $i, 1));
                $searchData['min_upload_date'] = now()->subDays(8)->timestamp;
            } else {
                // Flickr limits search request by 20 tags
                $tags = array_keys(array_slice(self::TAGS, $i * 20, 20));
                if (empty($tags)) {
                    break;
                }

                $searchData['tags'] = implode(',', $tags);
            }

            $response = FlickrLaravelFacade::request('flickr.photos.search', $searchData);

            if ($response->getStatus() == 'ok') {
                $photos = array_merge($photos, array_column($response->photos['photo'], null, 'id'));
            }
        }

        Log::info('Got photos count: ' . count($photos));
        $models = [];
        foreach ($photos as $photo) {
            /** @var FlickrPhoto $model */
            $model = FlickrPhoto::query()->updateOrCreate(['id' => $photo['id']], [
                'secret' => $photo['secret'],
                'owner' => $photo['owner'],
                'title' => $photo['title'],
            ]);

            $models[$photo['id']] = $model;
        }

        return $models;
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function loadPhotoInfo(FlickrPhoto $model): void
    {
        $infoResponse = FlickrLaravelFacade::request('flickr.photos.getInfo', [
            'photo_id' => $model->id,
            'secret' => $model->secret,
        ]);

        if ($infoResponse->getStatus() == 'ok') {
            $model->owner_username = $infoResponse->photo['owner']['username'];
            $model->owner_realname = $infoResponse->photo['owner']['realname'];
            $model->description = $infoResponse->photo['description']['_content'];
            $tags = [];
            foreach ($infoResponse->photo['tags']['tag'] as $tag) {
                $tags[] = $tag['_content'];
            }
            $model->tags = $tags;
            $model->url = $infoResponse->photo['urls']['url'][0]['_content'];
            $model->posted_at = Carbon::createFromTimestamp($infoResponse->photo['dates']['posted']);
            $model->taken_at = Carbon::parse($infoResponse->photo['dates']['taken']);

            $model->save();
            Log::info($model->id . ': Loaded photo info: ' . json_encode($infoResponse->photo));
        }
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function loadPhotoFile(FlickrPhoto $model): void
    {
        if (empty($model->source_url) || empty($model->classification)) {
            $sizesResponse = FlickrLaravelFacade::request('flickr.photos.getSizes', [
                'photo_id' => $model->id,
            ]);

            if ($sizesResponse->getStatus() == 'ok') {
                for ($i = count($sizesResponse->sizes['size']) - 1; $i >= 0; $i--) {
                    $size = $sizesResponse->sizes['size'][$i];

                    if ($size['width'] < 2000 && $size['height'] < 2000 && empty($model->source_url)) {
                        $model->source_url = $size['source'];

                        if (!empty($model->classification)) {
                            break;
                        }
                    }

                    if ($size['width'] < 500 && $size['height'] < 500 && empty($model->classification)) {
                        $model->classification = ['filename' => $this->processFileSouce($size['source'])];

                        break;
                    }
                }

                Log::info($model->id . ': Loaded photo sizes: ' . json_encode($sizesResponse->sizes));
            }
        }

        if (empty($model->filename) && !empty($model->source_url)) {
            $model->filename = $this->processFileSouce($model->source_url);
        }
        $model->save();
    }

    /**
     * @param $source
     *
     * @return false|string|null
     */
    private function processFileSouce($source)
    {
        if (!empty($source)) {
            $sourceParts = explode('/', $source);
            $fileName = end($sourceParts);
            $path = Storage::putFileAs('public/flickr', $source, $fileName);
            if ($path) {
                return $fileName;
            }
        }

        return null;
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function classifyPhoto(FlickrPhoto $model): void
    {
        if (empty($model->classification['filename'])) {
            $image = array_values(unpack('C*', File::get($model->getFilePath())));
        } else {
            $filepath = storage_path('app/public/flickr/' . $model->classification['filename']);
            $image = array_values(unpack('C*', File::get($filepath)));
            $model->classification = null;
            File::delete($filepath);
        }

        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info($model->id . ': Classification: ' . $model->url);
                $classificationResponse = MyCloudflareAI::runModel([
                    'image' => $image,
                ],  'microsoft/resnet-50');

                Log::info($model->id . ': Classification result: ' . json_encode($classificationResponse));

                if (!empty($classificationResponse['result'])) {
                    $model->classification = $classificationResponse['result'];
                    $model->save();
                }
            } catch (Exception $e) {
                $model->classification = null;

                Log::error($model->id . ': Image classification fail: ' . $e->getMessage());
            }

            // Http requests to CloudflareAI remain in memory, so we need to trigger GC manually
            unset($classificationResponse);
            gc_collect_cycles();

            if (!empty($model->classification)) {
                break;
            }
        }
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function rejectPhotoByClassification(FlickrPhoto $model): void
    {
        $model->status = FlickrPhotoStatus::REJECTED_BY_CLASSIFICATION;
        $model->save();

        Request::sendPhoto([
            'chat_id' => explode(',', config('telegram.admins'))[0],
            'caption' => $model->title . "\n" . implode(' ', $model->tags),
            'photo' => $model->getFileUrl(),
            'reply_markup' => new InlineKeyboard([
                ['text' => '✅Review', 'callback_data' => 'flickr_review ' . $model->id],
                ['text' => 'Exclude Tag', 'switch_inline_query_current_chat' => 'excludetag '],
                ['text' => '❌Delete', 'callback_data' => 'flickr_delete ' . $model->id]
            ]),
        ]);
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function preparePublishTitle(FlickrPhoto $model): void
    {
        $title = $model->title;
        if (
            empty($title)
            || Str::wordCount($title) == 1 && (
                Str::contains($title, ['img', 'dsc', '_mg', 'dji', 'photo'], true)
                || Str::charAt($title,0) == 'P'
                || is_numeric(Str::charAt($title,0))
            )
        ) {
            $title = $model->description;
        }

        try {
            $translator = new Translator(config('deepl.key'));
            $model->publish_title = trim(
                (string)$translator->translateText($title, null, 'uk'),
                ".\n\r\t\v\0"
            );
            $model->save();
        } catch (Exception $e) {
            Log::error($model->id . ': Translation of ' . $model->title .  ' failed: ' . $e->getMessage());
        }

        if (empty($model->publish_title)) {
            $model->publish_title = Str::ucfirst(trim(explode(' ', $model->publish_tags)[0], '#'));
            $model->save();
        }
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function preparePublishTags(FlickrPhoto $model): void
    {
        $tags = [];
        foreach ($model->tags as $tag) {
            if (isset(self::TAGS[$tag])) {
                $tags[] = self::TAGS[$tag];
            }
        }
        if (empty($model->tags)) {
            $i = 0;
            foreach (self::TAGS as $tag => $tagValue) {
                if ($i == self::TEXT_TAGS_COUNT) {
                    break;
                }

                if (
                    Str::contains($model->title, $tag, true)
                    || Str::contains($model->description, $tag, true)
                ) {
                    $tags[] = $tagValue;
                }

                $i++;
            }
        }
        foreach ($model->classification as $classification) {
            $tag = strtolower(str_replace(' ', '', $classification['label']));
            if (isset(self::TAGS[$tag]) && $classification['score'] > 0.8) {
                $tags[] = self::TAGS[$tag];
            }
        }

        $tags = array_unique(explode(' ', implode(' ', $tags)));
        if (
            in_array('#ірбіс', $tags)
            && ($key = array_search('#леопард', $tags)) !== false
        ) {
            unset($tags[$key]);
        }
        if (
            count($tags) > 1
            && ($key = array_search('#пантера', $tags)) !== false
            && !in_array('#леопард', $tags)
            && !in_array('#ягуар', $tags)
        ) {
            unset($tags[$key]);
        }
        if (($key = array_search('', $tags)) !== false) {
            unset($tags[$key]);
        }

        $model->publish_tags = implode(' ', $tags);
        $model->save();
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function sendPhotoToReview(FlickrPhoto $model): void
    {
        $telegramResult = Request::sendPhoto([
            'chat_id' => explode(',', config('telegram.admins'))[0],
            'caption' => $model->getCaption(),
            'photo' => $model->getFileUrl(),
            'reply_markup' => $model->getInlineKeyboard(),
        ]);

        if ($telegramResult->isOk()) {
            $model->message_id = $telegramResult->getResult()->getMessageId();
            $model->status = FlickrPhotoStatus::PENDING_REVIEW;
            $model->save();
        }
    }

    /**
     * @param FlickrPhoto $model
     * @param Message $message
     *
     * @return void
     */
    public function approve(FlickrPhoto $model, Message $message): void
    {
        $model->status = FlickrPhotoStatus::APPROVED;
        $model->save();

        if (empty($model->filename)) {
            $this->loadPhotoFile($model);
        }

        Request::editMessageReplyMarkup([
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'reply_markup' => new InlineKeyboard([
                ['text' => '❌Cancel Approval', 'callback_data' => 'flickr_cancel ' . $model->id],
            ]),
        ]);
    }

    /**
     * @param FlickrPhoto $model
     * @param Message $message
     *
     * @return void
     */
    public function decline(FlickrPhoto $model, Message $message): void
    {
        $model->status = FlickrPhotoStatus::REJECTED_MANUALLY;
        $model->save();

        $this->delete($model, $message);
    }

    /**
     * @param FlickrPhoto $model
     * @param Message $message
     *
     * @return void
     */
    public function delete(FlickrPhoto $model, Message $message): void
    {
        $model->deleteFile();

        Request::deleteMessage([
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
        ]);
    }

    /**
     * @param FlickrPhoto $model
     * @param Message $message
     *
     * @return void
     */
    public function cancel(FlickrPhoto $model, Message $message): void
    {
        $model->status = FlickrPhotoStatus::PENDING_REVIEW;
        $model->save();

        Request::editMessageReplyMarkup([
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'reply_markup' => $model->getInlineKeyboard(),
        ]);
    }

    /**
     * @param FlickrPhoto $model
     * @param Message $message
     *
     * @return void
     */
    public function review(FlickrPhoto $model, Message $message): void
    {
        $model->status = FlickrPhotoStatus::PENDING_REVIEW;
        $model->save();

        Request::deleteMessage([
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
        ]);

        $this->processPhotos([$model]);
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return array
     */
    public function original(FlickrPhoto $model): array
    {
        return [
            'text' => Str::substr(
                $model->title . "\n" . implode(' ', $model->tags) . "\n" . $model->description,
                0,
                200
            ),
            'show_alert' => true,
        ];
    }
}
