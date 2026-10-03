<?php
// Read-only. Prints JSON with every PENDING_REVIEW news item in full, plus the start of news approved, published
// or being processed in the last 21 days, for duplicate checks. `queued` = a queued or running job names the item.
require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, get_class($e) . ": {$e->getMessage()}\n");
    exit(1);
});

use App\Enums\NewsStatus;
use App\Models\News;
use Illuminate\Support\Facades\DB;

$jobs = DB::table('jobs')->pluck('payload');

$pending = News::select([
        'id', 'created_at', 'date', 'platform', 'language', 'species', 'source', 'link', 'title', 'publish_title',
        'content', 'is_translated', 'is_content_cleaned', 'is_auto', 'message_id',
    ])
    ->where('status', NewsStatus::PENDING_REVIEW->value)
    ->orderBy('id')
    ->get()
    ->map(fn (News $m) => [
        'id' => $m->id,
        'created_at' => (string) $m->created_at,
        'date' => (string) $m->date,
        'platform' => $m->platform,
        'language' => $m->language,
        'species' => $m->species,
        'source' => $m->source,
        'link' => $m->link,
        'title' => $m->title,
        'publish_title' => $m->publish_title,
        'content' => $m->content,
        'is_translated' => (bool) $m->is_translated,
        'is_content_cleaned' => (bool) $m->is_content_cleaned,
        'is_auto' => (bool) $m->is_auto,
        'queued' => $jobs->contains(fn ($payload) => str_contains($payload, "i:{$m->id};")),
        'message_id' => $m->message_id,
    ]);

// Only the first 2000 characters of content: the sheet needs a lede, and full bodies of a few hundred
// articles would push the process towards the hosting process killer.
// BEING_PROCESSED counts only with a review message: new items hold that status while they are classified,
// before review.
$recent = News::select(['id', 'status', 'created_at', 'title', 'publish_title', 'link'])
    ->selectRaw('LEFT(content, 2000) AS lede')
    ->where(fn ($q) => $q
        ->whereIn('status', [NewsStatus::APPROVED->value, NewsStatus::PUBLISHED->value])
        ->orWhere(fn ($q) => $q->where('status', NewsStatus::BEING_PROCESSED->value)->whereNotNull('message_id')))
    ->where('updated_at', '>', now()->subDays(21))
    ->orderBy('id')
    ->get()
    ->map(fn (News $m) => [
        'id' => $m->id,
        'status' => $m->status->value,
        'created_at' => (string) $m->created_at,
        'title' => $m->title,
        'publish_title' => $m->publish_title,
        'host' => parse_url((string) $m->link, PHP_URL_HOST),
        'lede' => preg_replace('/\s+/u', ' ', strip_tags((string) $m->lede)),
    ]);

echo json_encode(['pending' => $pending, 'recent' => $recent], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
