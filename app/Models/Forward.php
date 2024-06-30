<?php

namespace App\Models;

use App\Bluesky;
use App\Fediverse;
use App\Friendica;
use App\Twitter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Forward
 * @package App\Models
 * @mixin Builder
 *
 * @property int $id
 * @property string $fromConnection
 * @property int $fromId
 * @property string $toConnection
 * @property int $toId
 */
class Forward extends Model
{
    use SoftDeletes;

    public const CONNECTIONS = [
        'bluesky' => Bluesky::class,
        'twitter' => Twitter::class,
        'friendica' => Friendica::class,
        'fediverse' => Fediverse::class,
    ];

    use HasFactory;
}
