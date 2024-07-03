<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Forward
 * @package App\Models
 * @mixin Builder
 *
 * @property int $id
 * @property int $from_id
 * @property int $to_id
 */
class PostForward extends Model
{
    use HasFactory;

    protected $fillable = ['from_id', 'to_id'];
}
