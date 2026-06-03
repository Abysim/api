<?php
/**
 * @author Andrii Kalmus <andrii.kalmus@abysim.com>
 */

namespace App\Services;

use App\Helpers\FileHelper;
use App\Models\FlickrPhoto;
use App\Models\News;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BigCatsService
{
    private PendingRequest $request;

    public function __construct()
    {
        $this->request = Http::asJson()->withToken(config('services.bigcats.key'));
    }

    public function publishNews(News $news): bool|string
    {
        if (empty($news->filename)) {
            $news->loadMediaFile();
        }
        if (empty($news->filename)) {
            return false;
        }

        $data = [
            'date' => $news->date,
            'title' => $news->publish_title,
            'content' => trim($news->publish_content),
            'image' => $news->getFileUrl() ?? $news->media,
            'image_caption' => FileHelper::generateImageCaption($news->getFilePath(), 'uk', true),
            'publish_tags' => $news->publish_tags,
            'is_original' => $news->language != 'uk',
            'source_url' => $news->link,
            'source_name' => $news->source,
            'tags' => self::parseTags($news->publish_tags),
        ];
        if (!empty($news->author)) {
            $data['author'] = $news->author;
        }
        $response = $this->request->post(config('services.bigcats.url') . 'news/create', $data)->json();

        if (!empty($response['status']) && $response['status'] == 'success') {
            if (!empty($response['url'])) {
                Log::info($news->id . ': published successfully to BigCats: ' . $response['url']);
                $news->published_url = $response['url'];
                $news->save();

                return $response['image'] ?? true;
            } else {
                Log::error($news->id . ': got empty URL at BigCats publishing');
            }
        } elseif (!empty($response['errors'])) {
            Log::error($news->id . ': BigCats publishing error: ' . json_encode($response['errors']));
        } else {
            Log::error($news->id . ': BigCats publishing error: ' . json_encode($response));
        }

        return false;
    }

    public function publishArticle(News $article, bool $update = false): bool|string
    {
        if (empty($article->publish_tags)) {
            return false;
        }
        if (empty($article->filename)) {
            $article->loadMediaFile();
        }
        if (empty($article->filename)) {
            return false;
        }

        $data = [
            'title' => $article->publish_title,
            'content' => trim($article->publish_content),
            'image' => $article->getFileUrl() ?? $article->media,
            'image_caption' => FileHelper::generateImageCaption($article->getFilePath(), 'uk', true),
            'source_url' => $article->link,
            'source_name' => $article->source,
            'tags' => self::parseTags($article->publish_tags),
        ];
        if ($update) {
            $data['update'] = true;
        }
        $response = $this->request->post(config('services.bigcats.url') . 'articles/create', $data)->json();

        if (!empty($response['status']) && $response['status'] == 'success') {
            if (!empty($response['url'])) {
                Log::info($article->id . ': article published successfully to BigCats: ' . $response['url']);
                $article->published_url = $response['url'];
                $article->save();

                return $response['image'] ?? true;
            } else {
                Log::error($article->id . ': got empty URL at BigCats article publishing');
            }
        } elseif (!empty($response['errors'])) {
            Log::error($article->id . ': BigCats article publishing error: ' . json_encode($response['errors']));
        } else {
            Log::error($article->id . ': BigCats article publishing error: ' . json_encode($response));
        }

        return false;
    }

    private static function parseTags(string $tags): array
    {
        return array_map(fn ($item) => trim($item, '#'), explode(' ', $tags));
    }

    public function publishPhoto(FlickrPhoto $model): bool
    {
        $data = [
            'name' => $model->publish_title,
            'author_name' => $model->owner_realname ?: $model->owner_username,
            'flickr_link' => $model->url,
            'thumbnail_url' => $model->thumbnail_url,
            'thumbnail_width' => $model->thumbnail_width,
            'thumbnail_height' => $model->thumbnail_height,
            'tags' => self::parseTags($model->publish_tags),
        ];
        $response = $this->request->post(config('services.bigcats.url') . 'photos/create', $data)->json();

        if (!empty($response['status']) && $response['status'] == 'success') {
            Log::info($model->id . ': published successfully to BigCats');

            return true;
        } elseif (!empty($response['errors'])) {
            Log::error($model->id . ': BigCats photo publishing error: ' . json_encode($response['errors']));
        } else {
            Log::error($model->id . ': BigCats photo publishing error: ' . json_encode($response));
        }

        return false;
    }
}
