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
 * @property int $account_id
 * @property string $url
 * @property string $handle
 * @property string $client_id
 * @property string $client_secret
 * @property string $token
 * @property string $cat
 */
class FediverseConnection extends Model
{
    use HasFactory;

    protected $fillable = ['account_id', 'url', 'handle', 'client_id', 'client_secret', 'token', 'cat'];
}
