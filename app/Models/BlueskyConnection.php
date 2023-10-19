<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
 */
class BlueskyConnection extends Model
{
    use HasFactory;


}
