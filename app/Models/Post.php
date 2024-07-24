<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Class Forward
 * @package App\Models
 * @mixin Builder
 *
 * @property int $id
 * @property string $connection
 * @property int $connection_id
 * @property string $post_id
 * @property string $parent_post_id
 * @property string $root_post_id
 */
class Post extends Model
{
    use HasFactory;

    protected $fillable = ['connection', 'connection_id', 'post_id', 'parent_post_id', 'root_post_id'];

    /**
     * @param string $connection
     *
     * @return BelongsToMany
     */
    public function forwards(string $connection, $reverse = false): BelongsToMany
    {
        return $this
            ->belongsToMany(Post::class, 'post_forwards', 'from_id', 'to_id')
            ->where('connection', $connection)
            ->orderBy('post_id', $reverse ? 'ASC' : 'DESC');
    }


}
