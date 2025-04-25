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
 * @property Carbon $date
 * @property int $total_tokens
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiUsage extends Model
{
    protected $casts = [
        'date' => 'date',
    ];
}
