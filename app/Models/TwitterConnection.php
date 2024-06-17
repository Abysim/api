<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class TwitterConnection
 * @package App\Models
 * @mixin Builder
 *
 * @property int $id
 * @property string $handle
 * @property string $token
 * @property string $secret
 */
class TwitterConnection extends Model
{
    use HasFactory;

    protected $fillable = ['id', 'handle', 'token', 'secret'];
}
