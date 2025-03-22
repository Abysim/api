<?php
/**
 * @author Andrii Kalmus <andrii.kalmus@abysim.com>
 */

namespace App\Services;

use App\Helpers\FileHelper;
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
            'tags' => array_map(fn ($item) => trim($item, '#'), explode(' ', $news->publish_tags)) ,
        ];
        if (!empty($news->author)) {
            $data['author'] = $news->author;
        }
        $response = $this->request->post(config('services.bigcats.url') . 'news/create', $data)->json();

        if ($response['status'] == 'success') {
            if (!empty($response['url'])) {
                Log::info($news->id . ': published successfully to BigCats: ' . $response['url']);
                $news->published_url = $response['url'];
                $news->save();

                return $response['image'] ?? true;
            } else {
                Log::error($news->id . ': got empty URL at BigCats publishing');
            }
        } elseif (!empty(['errors'])) {
            Log::error($news->id . ': BigCats publishing error: ' . json_encode($response['errors']));
        } else {
            Log::error($news->id . ': BigCats publishing error: ' . json_encode($response));
        }

        return false;
    }
}
