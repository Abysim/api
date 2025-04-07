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
 * @property NewsStatus $status
 * @property array $classification
 * @property bool $is_translated
 * @property bool $is_auto
 * @property string $analysis
 * @property int $analysis_count
 * @property bool $is_deep
 * @property bool $is_deepest
 * @property string $publish_title
 * @property string $publish_content
 * @property string $publish_tags
 * @property string $original_title
 * @property string $original_content
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
            . (($this->language == 'uk' || empty($this->published_url)) ? $this->link : $this->published_url);
    }

    public function getCaption(): string
    {
        $link = $this->status == NewsStatus::PENDING_REVIEW
            ? NewsResource::getUrl('edit', ['record' => $this])
            : $this->link;

        $header = $this->date->format('d.m.Y') . ': ' . $this->publish_title . "\n\n";

        $footer = "\n\n#новини " . $this->publish_tags . "\n\n" . $link;

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
            'text' => '🛠️Title',
            'switch_inline_query_current_chat' => 'news_title ' . $this->id . ' ' . $this->publish_title,
        ];
        $firstLine[] = [
            'text' => '🛠️Image',
            'switch_inline_query_current_chat' => 'news_image ' . $this->id . ' ' . $this->media,
        ];
        $firstLine[] = [
            'text' => '🛠️Tags',
            'switch_inline_query_current_chat' => 'news_tags ' . $this->id . ' ' . $this->publish_tags,
        ];

        $secondLine = [];
        $reset = [
            'text' => '🔄Reset (' . ($this->is_deep ? '🏁' : '🏴') . $this->analysis_count . ')',
            'callback_data' => 'news_reset ' . $this->id
        ];
        if ($this->language == 'uk' || $this->is_translated && $this->is_deepest) {
            $secondLine[] = ['text' => '✅Approve', 'callback_data' => 'news_approve ' . $this->id];
        } else {
            $secondLine[] = $reset;
        }
        $secondLine[] = ['text' => '🔗Original', 'url' => $this->link];
        if ($this->status !== NewsStatus::BEING_PROCESSED) {
            $secondLine[] = ['text' => '❌Decline', 'callback_data' => 'news_decline ' . $this->id];
            $secondLine[] = ['text' => '❌Off-topic', 'callback_data' => 'news_offtopic ' . $this->id];
        }

        $thirdLine = [];
        if ($this->language != 'uk' && $this->status != NewsStatus::BEING_PROCESSED) {
            if (!$this->is_translated) {
                $thirdLine[] = ['text' => '🌐Translate', 'callback_data' => 'news_translate ' . $this->id];
                $thirdLine[] = ['text' => '🔁Auto Translate', 'callback_data' => 'news_auto ' . $this->id];
            } elseif ($this->status == NewsStatus::PENDING_REVIEW) {
                if (empty($this->analysis)) {
                    $thirdLine[] = [
                        'text' => ($this->is_deep ? '🔬' : '🧪') . 'Analyze (' . $this->analysis_count . ')',
                        'callback_data' => 'news_analyze ' . $this->id
                    ];
                } elseif (!$this->is_deepest) {
                    $thirdLine[] = [
                        'text' => ($this->is_deep ? '🧬' : '📝') . 'Apply (' . $this->analysis_count . ')',
                        'callback_data' => 'news_apply ' . $this->id
                    ];
                    $thirdLine[] = ['text' => '🔍Analysis', 'callback_data' => 'news_analysis ' . $this->id];
                }
                if (!$this->is_deep && !$this->is_deepest) {
                    $thirdLine[] = ['text' => '🏴Deep', 'callback_data' => 'news_deep ' . $this->id];
                } elseif (!$this->is_deepest) {
                    $thirdLine[] = ['text' => '🏁Deepest', 'callback_data' => 'news_deepest ' . $this->id];
                }
            }

            if (empty($thirdLine) && $this->is_translated) {
                $thirdLine[] = $reset;
                $thirdLine[] = [
                    'text' => '🔄Counter',
                    'callback_data' => 'news_counter ' . $this->id
                ];
                $thirdLine[] = [
                    'text' => '🔄Translation',
                    'callback_data' => 'news_translation ' . $this->id
                ];
            }
        }

        return new InlineKeyboard($firstLine, $secondLine, $thirdLine);
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
            if ($model->message_id) {
                if (
                    $model->wasChanged('publish_title')
                    || $model->wasChanged('publish_content')
                    || $model->wasChanged('publish_tags')
                    || $model->wasChanged('date')
                    || $model->wasChanged('status')
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
                    $model->syncOriginal();
                    $model->discardChanges();
                    $model->deleteFile();
                    $model->loadMediaFile();
                    Log::info($model->id . ': Updating news media: ' . ($model->media ?? $model->getFileUrl()));
                    if ($model->message_id) {
                        $response = Request::editMessageMedia([
                            'chat_id' => explode(',', config('telegram.admins'))[0],
                            'message_id' => $model->message_id,
                            'reply_markup' => $model->getInlineKeyboard(),
                            'media' => new InputMediaPhoto([
                                'caption' => $model->getCaption(),
                                'type' => 'photo',
                                'media' => $model->media ?? $model->getFileUrl(),
                            ]),
                        ]);

                        if (!$response->isOk()) {
                            Log::error($model->id . ': Failed to send media: ' . $response->getDescription());
                        }
                    }
                }

                if (!$model->is_auto) {
                    if ($model->wasChanged('is_translated') && $model->is_translated) {
                        Request::sendMessage([
                            'chat_id' => explode(',', config('telegram.admins'))[0],
                            'reply_to_message_id' => $model->message_id,
                            'text' => 'Translation completed',
                            'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                        ]);
                    }

                    if ($model->wasChanged('analysis')) {
                        if (!empty($model->analysis)) {
                            Request::sendMessage([
                                'chat_id' => explode(',', config('telegram.admins'))[0],
                                'reply_to_message_id' => $model->message_id,
                                'text' => $model->analysis,
                                'reply_markup' => new InlineKeyboard([
                                    [
                                        'text' => '❌Delete',
                                        'callback_data' => 'delete'
                                    ]
                                ]),
                            ]);
                        } elseif (!$model->wasChanged('is_deep')) {
                            Request::sendMessage([
                                'chat_id' => explode(',', config('telegram.admins'))[0],
                                'reply_to_message_id' => $model->message_id,
                                'text' => 'Analysis applied',
                                'reply_markup' => new InlineKeyboard([
                                    [
                                        'text' => '❌Delete',
                                        'callback_data' => 'delete'
                                    ]
                                ]),
                            ]);
                        }
                    }

                    if (
                        $model->wasChanged('analysis')
                        || $model->wasChanged('is_deepest')
                        || $model->wasChanged('is_deep')
                        || $model->wasChanged('status')
                    ) {
                        $model->updateReplyMarkup();
                    }
                } elseif ($model->wasChanged('is_auto')) {
                    $model->updateReplyMarkup();
                }
            }

            if (
                $model->language != 'uk'
                && !$model->is_translated
                && ($model->wasChanged('publish_title') || $model->wasChanged('publish_content'))
            ) {
                $model->syncOriginal();
                $model->discardChanges();
                $model->original_title = $model->publish_title;
                $model->original_content = $model->publish_content;
                $model->save();
            }

            $model->syncOriginal();
            $model->discardChanges();
        });
    }

    public function updateReplyMarkup(): void
    {
        if (!$this->message_id) {
            return;
        }

        Request::editMessageReplyMarkup([
            'chat_id' => explode(',', config('telegram.admins'))[0],
            'message_id' => $this->message_id,
            'reply_markup' => $this->getInlineKeyboard(),
        ]);
    }
}
