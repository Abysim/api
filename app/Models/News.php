<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class News
 * @package App\Models
 * @mixin Builder
 *
 * @property int $id
 * @property string $platform
 * @property string $external_id
 * @property string $date
 * @property string $author
 * @property string $title
 * @property string $content
 * @property string[] $tags
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
 * @property Carbon $published_at
 * @property Carbon $posted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class News extends Model
{

}
