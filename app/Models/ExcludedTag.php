<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class FlickrPhoto
 * @package App\Models
 * @mixin Builder
 *
 * @property int $id
 * @property string $name
 * @property Carbon $created_at
 */
class ExcludedTag extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    /**
     * @var string[]
     */
    protected $fillable = ['name'];
}
