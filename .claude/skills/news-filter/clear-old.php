<?php
// Step 1: PENDING_REVIEW news created more than --days ago (default 7) -> REJECTED_MANUALLY. Counts only, unless
// --apply. Builder update, so captions are not edited. Items the user picked (cleaned, translated, auto, queued
// job) are left alone. The ids are added to storage/app/news-filter/{run}.json before the update, for undo.
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

function quit(string $message): never
{
    fwrite(STDERR, "$message\n");
    exit(1);
}

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $opts['apply'] = true;
        continue;
    }
    preg_match('/^--(days|run)=(.*)$/', $arg, $m) || quit("unknown argument: $arg");
    $opts[$m[1]] = $m[2];
}
$days = $opts['days'] ?? '7';
$run = $opts['run'] ?? '';
$apply = $opts['apply'] ?? false;
if (!preg_match('/^[1-9]\d*$/', $days) || ($apply && !preg_match('/^[\w.-]+$/', $run))) {
    quit('usage: [--days=N] [--run=<id> --apply]');
}

$pending = NewsStatus::PENDING_REVIEW->value;
$cutoff = now()->subDays((int) $days)->toDateTimeString();
// Plain rows, not models: about a fifth of the memory for thousands of old items.
$old = News::where('status', $pending)->where('created_at', '<', $cutoff)->orderBy('id')->toBase()
    ->get(['id', 'is_content_cleaned', 'is_translated', 'is_auto']);
$jobs = DB::table('jobs')->pluck('payload');
$picked = $old->filter(fn ($m) => $m->is_content_cleaned || $m->is_translated || $m->is_auto
    || $jobs->contains(fn ($payload) => str_contains($payload, "i:{$m->id};")))->pluck('id');
$ids = $old->pluck('id')->diff($picked)->values()->all();
echo "older than $days days (created before $cutoff): {$old->count()}, picked by the user and left: {$picked->count()}"
    . ($picked->isNotEmpty() ? ' (' . $picked->implode(',') . ')' : '') . "\n";

if (!$apply || !$ids) {
    exit;
}

$dir = storage_path('app/news-filter');
$file = "$dir/$run.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : ['run' => $run, 'decisions' => [], 'kept' => []];
is_array($data) || quit("$file is not valid JSON, nothing changed");
$data['old'] = [
    'cutoff' => $cutoff,
    'from' => $pending,
    'to' => NewsStatus::REJECTED_MANUALLY->value,
    'ids' => array_values(array_unique(array_merge($data['old']['ids'] ?? [], $ids))),
];
is_dir($dir) || mkdir($dir, 0775, true);
(file_put_contents("$file.tmp", json_encode($data, JSON_UNESCAPED_UNICODE)) !== false && rename("$file.tmp", $file))
    || quit("cannot write $file, nothing changed");

$updated = News::whereIn('id', $ids)
    ->where('status', $pending)
    ->where('is_content_cleaned', false)
    ->where('is_translated', false)
    ->where('is_auto', false)
    ->update(['status' => NewsStatus::REJECTED_MANUALLY->value]);
echo "updated: $updated\n";
echo 'still pending: ' . News::where('status', $pending)->count() . "\n";
