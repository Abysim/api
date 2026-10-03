<?php
// Read-only. Shows what happened after a run (default: the latest run that got past step 1): how its kept items
// ended and which of its declines were reversed. Statuses the loader or pipeline set, and kept items that a later
// run cleared as old or declined, are listed apart.
require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, get_class($e) . ": {$e->getMessage()}\n");
    exit(1);
});

use App\Enums\NewsStatus;
use App\Models\News;

function quit(string $message): never
{
    fwrite(STDERR, "$message\n");
    exit(1);
}

$run = null;
foreach (array_slice($argv, 1) as $arg) {
    preg_match('/^--run=([\w.-]+)$/', $arg, $m) || quit('usage: [--run=<id>]');
    $run = $m[1];
}

// Newest first: run ids start with the UTC date and time. Runs newer than the one shown only say which ids they
// cleared or declined.
$files = glob(storage_path('app/news-filter') . '/*.json') ?: [];
rsort($files);
$data = null;
$later = [];
foreach ($files as $file) {
    $candidate = json_decode(file_get_contents($file), true);
    is_array($candidate) || quit("$file is not valid JSON");
    if ($run !== null ? basename($file, '.json') === $run : !empty($candidate['decisions']) || !empty($candidate['kept'])) {
        $data = $candidate;
        break;
    }
    $laterRun = basename($file, '.json');
    foreach ($candidate['old']['ids'] ?? [] as $id) {
        $later[$id] = "cleared as old by run $laterRun";
    }
    foreach (array_keys($candidate['decisions'] ?? []) as $id) {
        $later[$id] = "declined by run $laterRun";
    }
}
if (!is_array($data)) {
    $run === null || quit("no valid run file $run");
    exit("no run file yet\n");
}

$decisions = $data['decisions'] ?? [];
$kept = $data['kept'] ?? [];
echo 'run ' . ($data['run'] ?? $run) . ': ' . count($decisions) . ' decisions, ' . count($kept) . " kept\n";
if (!empty($data['old']['ids'])) {
    $moved = News::whereIn('id', $data['old']['ids'])->where('status', '!=', NewsStatus::REJECTED_MANUALLY->value)->count();
    echo 'step 1: ' . count($data['old']['ids']) . " moved to 4, $moved of them no longer at 4\n";
}

$approved = [NewsStatus::APPROVED, NewsStatus::PUBLISHED, NewsStatus::BEING_PROCESSED];
$userRejected = [NewsStatus::REJECTED_MANUALLY, NewsStatus::REJECTED_AS_OFF_TOPIC];
$groups = [];
foreach (News::whereIn('id', $kept ?: [0])->orderBy('id')->get(['id', 'status', 'title']) as $model) {
    $status = $model->status;
    $byLaterRun = in_array($status, $userRejected, true) ? ($later[$model->id] ?? null) : null;
    $group = match (true) {
        in_array($status, $approved, true) => 'kept, user approved or translating',
        $status === NewsStatus::PENDING_REVIEW => 'kept, still pending',
        $byLaterRun !== null => 'kept, then cleared or declined by a later run',
        in_array($status, $userRejected, true) => 'kept, user rejected',
        default => 'kept, then changed by the loader or pipeline',
    };
    $groups[$group][] = "#{$model->id} status {$status->value}" . ($byLaterRun ? " ($byLaterRun)" : '')
        . ' | ' . mb_substr($model->title, 0, 100);
}

foreach (News::whereIn('id', array_keys($decisions) ?: [0])->orderBy('id')->get(['id', 'status', 'title']) as $model) {
    $status = $model->status;
    if (in_array($status, $userRejected, true)) {
        continue;
    }
    $group = in_array($status, [NewsStatus::PENDING_REVIEW, ...$approved], true)
        ? 'declined by the skill, reversed by user'
        : 'declined by the skill, then changed by the loader or pipeline';
    $step = $decisions[$model->id]['step'] ?? '';
    $groups[$group][] = "#{$model->id} status {$status->value} ($step) | " . mb_substr($model->title, 0, 100);
}

foreach ($groups as $group => $lines) {
    echo "\n$group: " . count($lines) . "\n" . implode("\n", $lines) . "\n";
}
