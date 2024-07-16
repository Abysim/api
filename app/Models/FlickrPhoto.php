<?php

namespace App\Models;

use App\Enums\FlickrPhotoStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Longman\TelegramBot\Entities\InlineKeyboard;

/**
 * Class FlickrPhoto
 * @package App\Models
 * @mixin Builder
 *
 * @property int $id
 * @property string $secret
 * @property string $owner
 * @property string $owner_username
 * @property string $owner_realname
 * @property string $title
 * @property string $description
 * @property string[] $tags
 * @property string $url
 * @property string $filename
 * @property FlickrPhotoStatus $status
 * @property array $classification
 * @property string $publish_title
 * @property string $publish_tags
 * @property int $message_id
 * @property Carbon $published_at
 * @property Carbon $taken_at
 * @property Carbon $posted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FlickrPhoto extends Model
{
    use HasFactory;

    /**
     * @var string[]
     */
    protected $fillable = ['id', 'secret', 'owner', 'title'];

    /**
     * @var string[]
     */
    protected $casts = [
        'tags' => 'array',
        'status' => FlickrPhotoStatus::class,
        'classification' => AsArrayObject::class,
        'published_at' => 'datetime',
        'taken_at' => 'datetime',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return InlineKeyboard
     */
    public function getInlineKeyboard(): InlineKeyboard
    {
        return new InlineKeyboard([
            [
                'text' => 'Fix Title',
                'switch_inline_query_current_chat' => 'flickr_title ' . $this->id . ' ' . $this->publish_title,
            ],
            [
                'text' => 'Fix Tags',
                'switch_inline_query_current_chat' => 'flickr_tags ' . $this->id . ' ' . $this->publish_tags,
            ],
        ], [
            ['text' => '✅Approve', 'callback_data' => 'flickr_approve ' . $this->id],
            ['text' => 'Original', 'callback_data' => 'flickr_original ' . $this->id],
            ['text' => '❌Decline', 'callback_data' => 'flickr_decline ' . $this->id],
        ]);
    }

    /**
     * @return string
     */
    public function getCaption(): string
    {
        $owner = $this->owner_realname ?: $this->owner_username;

        return "$this->publish_title 📷$owner\n#світлина $this->publish_tags\n$this->url";
    }

    /**
     * @return string|null
     */
    public function getFilePath(): ?string
    {
        if (empty($this->filename)) {
            return null;
        }

        return  storage_path('app/public/flickr/' . $this->filename);
    }

    /**
     * @return string|null
     */
    public function getFileUrl(): ?string
    {
        if (empty($this->filename)) {
            return null;
        }

        return asset(Storage::url('flickr/' . $this->filename));
    }

    /**
     * @return void
     */
    public function deleteFile(): void
    {
        if (!empty($this->getFilePath())) {
            if (File::isFile($this->getFilePath())) {
                File::delete($this->getFilePath());
            }

            $this->filename = null;
            $this->save();
        }
    }
}
