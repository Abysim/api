<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * @mixin Builder
 *
 * @property int $id
 * @property string $date
 * @property int $fetch_raw_http
 * @property int $fetch_jina
 * @property int $fetch_scrapedo
 * @property int $fetch_scraperapi
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DailyStat extends Model
{
    protected $fillable = ['date'];

    public const FIELDS = ['fetch_raw_http', 'fetch_jina', 'fetch_scrapedo', 'fetch_scraperapi'];

    public static function cacheKey(string $field, ?string $date = null): string
    {
        return 'daily_stats:' . $field . ':' . ($date ?? now()->format('Y-m-d'));
    }

    public static function flushCache(): void
    {
        $date = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');
        $stat = null;

        foreach (self::FIELDS as $field) {
            $key = self::cacheKey($field, $date);
            $count = (int) Cache::get($key);
            if ($count > 0) {
                try {
                    $stat ??= self::firstOrCreate(['date' => $date]);
                    $stat->increment($field, $count);
                    Cache::decrement($key, $count);
                } catch (\Throwable $e) {
                    Log::error("Failed to flush daily stat {$field}: " . $e->getMessage());
                }
            }

            Cache::forget(self::cacheKey($field, $yesterday));
        }
    }
}
