<?php

namespace App\Http\Controllers;

use App\Enums\FlickrPhotoStatus;
use App\Models\FlickrPhoto;
use App\MyCloudflareAI;
use DeepL\Translator;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JeroenG\Flickr\FlickrLaravelFacade;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Request;

/**
 * Class FlickrPhotoController
 * @package App\Http\Controllers
 */
class FlickrPhotoController extends Controller
{
    private const TAGS = [
        'lion' => '#лев',
        'lioness' => '#лев',
        'whitelion' => '#лев #білийлев',
        'tiger' => '#тигр',
        'tigress' => '#тигр',
        'whitetiger' => '#тигр #білийтигр',
        'panther' => '#пантера',
        'snowleopard' => '#ірбіс',
        'blackleopard' => '#пантера #леопард',
        'leopard' => '#леопард',
        'leopardess' => '#леопард',
        'jaguar' => '#ягуар',
        'blackjaguar' => '#пантера #ягуар',
        'cheetah' => '#гепард',
        'ocelot' => '#оцелот',
        'lynx' => '#рись',
        'puma' => '#пума',
        'cougar' => '#пума',
        'caracal' => '#каракал',
        'serval' => '#сервал',

        'snep' => '#ірбіс',
        'schneeleopard' => '#ірбіс',
        'irbis' => '#ірбіс',
        'panthera' => '#пантера',
        'lions' => '#лев',
        'lionesses' => '#лев',
        'whitelions' => '#лев #білийлев',
        'tigers' => '#тигр',
        'tigresses' => '#тигр',
        'whitetigers' => '#тигр #білийтигр',
        'panthers' => '#пантера',
        'snowleopards' => '#ірбіс',
        'blackleopards' => '#пантера #леопард',
        'leopards' => '#леопард',
        'leopardesses' => '#леопард',
        'jaguars' => '#ягуар',
        'blackjaguars' => '#пантера #ягуар',
        'cheetahs' => '#гепард',
        'ocelots' => '#оцелот',
        'lynxes' => '#рись',

        'pumas' => '#пума',
        'cougars' => '#пума',
        'caracals' => '#каракал',
        'servals' => '#сервал',
        'sneps' => '#ірбіс',
        'irbises' => '#ірбіс',
        'pantheras' => '#пантера',
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
    ];

    private const EXCLUDED_TAGS = [
        'car',
        'art',
        'auto',
        'train',
        'corporation',
        'coach',
        'mural',
        'publicart',
        'transformers',
        'cars',
        'vehicle',
        'pawprints',
        'pawprint',
        'fursuit',
        'disneyland',
        'bird',
        'museum',
        'ford',
        'liquor',
        'statue',
        'cabriolet',
        'city',
        'sculture',
        'monkey',
        'slug',
        'scouts',
        'scout',
        'ubisoft',
        'ai',
    ];

    private const LOAD_TIME = '16:00:00';

    private const PUBLISH_INTERVAL_MINUTES = 1404;

    private const MAX_DAILY_PUBLISH_COUNT = 4;

    /**
     * @return void
     */
    public function process(): void
    {
        Log::info('Processing Flickr photos');
        $this->publish();

        if (now()->format('H:i:s') >= self::LOAD_TIME) {
            $models = $this->loadPhotos();
        }

        if (empty($models)) {
            $models = FlickrPhoto::query()->where('status', FlickrPhotoStatus::CREATED)->get();
        }

        $this->processCreatedPhotos($models);

        $this->deletePhotoFiles();
    }

    /**
     * @return void
     */
    public function deletePhotoFiles(): void
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
                FlickrPhotoStatus::REJECTED_BY_CLASSIFICATION,
                FlickrPhotoStatus::REJECTED_MANUALLY,
            ])
            ->whereNotNull('filename')
            ->get();

        foreach ($models as $model) {
            $model->deleteFile();
        }
    }

    /**
     * @return void
     */
    public function publish(): void
    {
        /** @var FlickrPhoto $lastPublishedPhoto */
        $lastPublishedPhoto = FlickrPhoto::query()->latest('published_at')->first();

        $pendingPublishSize = FlickrPhoto::query()->where('status', FlickrPhotoStatus::APPROVED)->count();
        $dailyPublishCount = min(self::MAX_DAILY_PUBLISH_COUNT, max($pendingPublishSize - 1, 1));
        $publishInterval = intdiv(self::PUBLISH_INTERVAL_MINUTES, $dailyPublishCount);

        if (
            empty($lastPublishedPhoto->published_at)
            || now()->subMinutes($publishInterval)->gt($lastPublishedPhoto->published_at)
        ) {
            Log::info('Publishing Flickr photos');
            /** @var FlickrPhoto $photoToPublish */
            $photoToPublish = FlickrPhoto::query()
                ->where('status', FlickrPhotoStatus::APPROVED)
                ->oldest('updated_at')
                ->first();

            if (!empty($photoToPublish)) {
                $this->publishPhoto($photoToPublish);
            }
        }
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function publishPhoto(FlickrPhoto $model): void
    {
        Log::info($model->id . ': Publishing Flickr photo');
        $response = Http::post(
            'https://maker.ifttt.com/trigger/flickr_photo/with/key/' . config('services.ifttt.webhook_key'),
            [
                'value1' => $model->getCaption(),
                'value2' => $model->getFileUrl(),
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
        } else {
            Log::error($model->id . ': Flickr photo not published!');
        }
    }

    /**
     * @param FlickrPhoto[]|Builder[]|Collection $models
     *
     * @return void
     */
    private function processCreatedPhotos(array|Collection $models): void
    {
        foreach ($models as $model) {
            if (empty($model->tags)) {
                $this->loadPhotoTags($model);
            }

            foreach ($model->tags as $tag) {
                if (in_array($tag, self::EXCLUDED_TAGS)) {
                    $model->status = FlickrPhotoStatus::REJECTED_BY_TAG;
                    $model->save();
                    $model->deleteFile();

                    break;
                }
            }

            $model->refresh();
            if (empty($model->status) || $model->status == FlickrPhotoStatus::CREATED) {
                if (empty($model->filename)) {
                    $this->loadPhotoFile($model);
                }

                if (empty($model->classification) && !empty($model->filename)) {
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

                    if ($rejected) {
                        $this->rejectPhotoByClassification($model);
                    } else {
                        if (empty($model->publish_title)) {
                            $this->preparePublishTitle($model);
                        }

                        if (empty($model->publish_tags)) {
                            $this->preparePublishTags($model);
                        }
                    }

                    $model->refresh();
                    if (empty($model->status) || $model->status == FlickrPhotoStatus::CREATED) {
                        $this->sendPhotoToReview($model);
                    }
                }
            }
        }
    }

    /**
     * @return FlickrPhoto[]
     */
    private function loadPhotos(): array
    {
        Log::info('Loading today Flick photos');
        $photos = [];
        for ($i = 0; $i < 8; $i++) {
            // Flickr limits search request by 20 tags
            $tags = array_keys(array_slice(self::TAGS, $i * 20, 20));
            if (empty($tags)) {
                break;
            }

            $response = FlickrLaravelFacade::request('flickr.photos.search', [
                'tags' => implode(',', $tags),
                'min_upload_date' => now()->subDays(2)->timestamp,
                'sort' => 'interestingness-desc',
                'content_types' => '0',
                'license' => '1,2,3,4,5,6,7,9,10',
                'per_page' => 500,
            ]);

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

            $models[] = $model;
        }

        return $models;
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function loadPhotoTags(FlickrPhoto $model): void
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
        }
    }

    /**
     * @param FlickrPhoto $model
     *
     * @return void
     */
    private function loadPhotoFile(FlickrPhoto $model): void
    {
        $sizesResponse = FlickrLaravelFacade::request('flickr.photos.getSizes', [
            'photo_id' => $model->id,
        ]);

        if ($sizesResponse->getStatus() == 'ok') {
            for ($i = count($sizesResponse->sizes['size']) - 1; $i >= 0; $i--) {
                $size = $sizesResponse->sizes['size'][$i];

                if ($size['width'] < 1800 && $size['height'] < 1800) {
                    $source = $size['source'];

                    break;
                }
            }

            if (!empty($source)) {
                $sourceParts = explode('/', $source);
                $fileName = end($sourceParts);
                $path = Storage::putFileAs('public/flickr', $source, $fileName);
                if ($path) {
                    $model->filename = $fileName;
                    $model->save();
                }
            }
        }
    }

    /**
     * @param $model
     *
     * @return void
     */
    private function classifyPhoto(FlickrPhoto $model): void
    {
        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info($model->id . ': Classification: ' . $model->url);
                $classificationResponse = MyCloudflareAI::runModel([
                    'image' => array_values(unpack('C*', File::get($model->getFilePath()))),
                ],  'microsoft/resnet-50');

                Log::info($model->id . ': Classification result: ' . json_encode($classificationResponse));

                if (!empty($classificationResponse['result'])) {
                    $model->classification = $classificationResponse['result'];
                    $model->save();
                }
            } catch (Exception $e) {
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
            'caption' => implode(' ', $model->tags),
            'photo' => $model->getFileUrl(),
            'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
        ]);

        $model->deleteFile();
    }

    /**
     * @param $model
     *
     * @return void
     */
    private function preparePublishTitle($model): void
    {
        try {
            $translator = new Translator(config('deepl.key'));
            $model->publish_title = trim(
                (string)$translator->translateText($model->title, null, 'uk'),
                ".\n\r\t\v\0"
            );
            $model->save();
        } catch (Exception $e) {
            Log::error($model->id . ': Translation of ' . $model->title .  ' failed: ' . $e->getMessage());
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
}
