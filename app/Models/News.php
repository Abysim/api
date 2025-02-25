<?php

namespace App\Models;

use App\Enums\NewsStatus;
use App\Filament\Resources\NewsResource;
use App\Helpers\FileHelper;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Request;

/**
 * Class News
 * @package App\Models
 * @mixin Builder
 *
 * @property int $id
 * @property string $platform
 * @property string $external_id
 * @property Carbon $date
 * @property string $author
 * @property string $title
 * @property string $content
 * @property string[] $tags
 * @property string[] $species
 * @property string $link
 * @property string $source
 * @property string $language
 * @property string $media
 * @property string $filename
 * @property int $status
 * @property array $classification
 * @property string $publish_title
 * @property string $publish_content
 * @property string $publish_tags
 * @property int $message_id
 * @property string $published_url
 * @property Carbon $published_at
 * @property Carbon $posted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class News extends Model
{
    /**
     * @var string[]
     */
    protected $fillable = [
        'platform',
        'external_id',
        'date', 'author',
        'title',
        'content',
        'link',
        'source',
        'language',
        'media',
        'posted_at'
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'date' => 'date',
        'tags' => 'array',
        'species' => 'array',
        'status' => NewsStatus::class,
        'classification' => AsArrayObject::class,
        'published_at' => 'datetime',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getShortCaption(): string
    {
        return $this->date->format('d.m.Y') . ': '
            . $this->publish_title . "\n#новини "
            . $this->publish_tags . "\n"
            . $this->link;
    }

    public function getCaption(): string
    {
        $header = $this->date->format('d.m.Y') . ': ' . $this->publish_title . "\n\n";

        $footer = "\n\n#новини " . $this->publish_tags . "\n\n" . $this->link;

        $remainingLength = 1024 - Str::length($header) - Str::length($footer);

        if (Str::length($this->publish_content) > $remainingLength) {
            $content = Str::substr($this->publish_content, 0, $remainingLength - 1) . '…';
        } else {
            $content = $this->publish_content;
        }

        return $header . $content . $footer;
    }

    /**
     * @return string|null
     */
    public function getFileUrl(): ?string
    {
        if (empty($this->filename)) {
            return null;
        }

        return asset(Storage::url('news/' . $this->filename));
    }

    /**
     * @return InlineKeyboard
     */
    public function getInlineKeyboard(): InlineKeyboard
    {
        $firstLine = [];
        $firstLine[] = [
            'text' => 'Fix Title',
            'switch_inline_query_current_chat' => 'news_title ' . $this->id . ' ' . $this->publish_title,
        ];
        $firstLine[] = [
            'text' => 'Fix Image',
            'switch_inline_query_current_chat' => 'news_image ' . $this->id . ' ' . $this->media,
        ];
        $firstLine[] = [
            'text' => 'Fix Tags',
            'switch_inline_query_current_chat' => 'news_tags ' . $this->id . ' ' . $this->publish_tags,
        ];

        return new InlineKeyboard($firstLine, [
            ['text' => '✅Approve', 'callback_data' => 'news_approve ' . $this->id],
            ['text' => 'Fix Content', 'url' => NewsResource::getUrl('edit', ['record' => $this])],
            ['text' => '❌Decline', 'callback_data' => 'news_decline ' . $this->id],
        ]);
    }

    /**
     * @return void
     */
    public function deleteFile(): void
    {
        if (!empty($this->getFilePath())) {
            if (File::isFile($this->getFilePath())) {
                Log::info($this->id . ': Deleting news file: ' . $this->filename);
                File::delete($this->getFilePath());
            }

            $this->filename = null;
            $this->save();
        }
    }


    /**
     * @return string|null
     */
    public function getFilePath(): ?string
    {
        if (empty($this->filename)) {
            return null;
        }

        return storage_path('app/public/news/' . $this->filename);
    }

    public function loadMediaFile(): void
    {
        if (empty($this->filename) && !empty($this->media)) {
            try {
                $file = FileHelper::getUrl($this->media, true);
            } catch (Exception $e) {
                Log::error("$this->id: News media file error: " . $e->getMessage());
            }
            if (empty($file)) {
                Log::error("$this->id: News media file not found: $this->media");
                return;
            }

            $mime = FileHelper::getMimeType($file);
            if (!Str::startsWith($mime, 'image/')) {
                Log::error("$this->id: News media file is not an image: $this->media");
                return;
            }
            $extension = Str::after($mime, 'image/') ?? 'jpg';

            $hash = md5($file);
            $path = storage_path('app/public/news/' . $this->id . $hash . '.' . $extension);
            if (File::put($path, $file)) {
                $this->filename = $this->id . $hash . '.' . $extension;
                $this->save();
            } else {
                Log::error("$this->id: News media file not saved: $this->media");
            }
        }
    }

    protected static function booted(): void
    {
        static::unguard();

        static::updated(function (News $model) {
            if ($model->message_id && $model->status == NewsStatus::PENDING_REVIEW) {
                if (
                    $model->wasChanged('publish_title')
                    || $model->wasChanged('publish_content')
                    || $model->wasChanged('publish_tags')
                    || $model->wasChanged('date')
                ) {
                    Log::info($model->id . ': Updating news message: ' . $model->message_id);

                    Request::editMessageCaption([
                        'chat_id' => explode(',', config('telegram.admins'))[0],
                        'message_id' => $model->message_id,
                        'caption' => $model->getCaption(),
                        'reply_markup' => $model->getInlineKeyboard(),
                    ]);
                }

                if ($model->wasChanged('media')) {
                    $model->deleteFile();
                    $model->loadMediaFile();
                    if ($model->message_id) {
                        Request::editMessageMedia([
                            'chat_id' => explode(',', config('telegram.admins'))[0],
                            'message_id' => $model->message_id,
                            'media' => new InputMediaPhoto([
                                'type' => 'photo',
                                'media' => $model->getFileUrl(),
                            ]),
                        ]);
                    }
                }
            }
        });
    }
}
