<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class BlueskyConnection
 * @package App\Models
 * @mixin Builder
 *
 * @property int $id
 * @property string $did
 * @property string $handle
 * @property string $secret
 * @property string $password
 * @property string $jwt
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $refresh
 */
class BlueskyConnection extends Model
{
    use HasFactory;


}
