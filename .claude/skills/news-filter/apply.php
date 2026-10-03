<?php
// Steps 4-5: applies review decisions through NewsController::offtopic() (status 9) or decline() (status 4), so
// captions update and review messages younger than 48h are deleted. Dry run unless --apply.
// --payload is base64 JSON: {"decisions": {"<id>": {"to": 4|9, "step": "...", "reason": "..."}}, "kept": [ids]}.
// Skips rows no longer pending and rows the user picked (cleaned, translated, auto, queued job). Each row is
// recorded in storage/app/news-filter/{run}.json before it changes, so a killed run can be re-run as is.
// "kept" ids are added to the run file's list.
require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, get_class($e) . ": {$e->getMessage()}\n");
    exit(1);
});

use App\Enums\NewsStatus;
use App\Http\Controllers\NewsController;
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
    preg_match('/^--(run|payload)=(.*)$/', $arg, $m) || quit("unknown argument: $arg");
    $opts[$m[1]] = $m[2];
}
$run = $opts['run'] ?? '';
$apply = $opts['apply'] ?? false;
$payload = json_decode(base64_decode($opts['payload'] ?? '', true) ?: '', true);
$isId = fn ($id) => (is_int($id) || is_string($id)) && preg_match('/^[1-9]\d*$/', (string) $id);
if (!preg_match('/^[\w.-]+$/', $run) || !is_array($payload) || !is_array($payload['decisions'] ?? null)
    || (array_key_exists('kept', $payload)
        && (!is_array($payload['kept']) || count(array_filter($payload['kept'], $isId)) !== count($payload['kept'])))) {
    quit('usage: --run=<id> --payload=<base64 json> [--apply]; "kept" is a list of ids');
}

$dir = storage_path('app/news-filter');
$file = "$dir/$run.json";
$load = function () use ($file, $run): array {
    if (!file_exists($file)) {
        return ['run' => $run, 'decisions' => [], 'kept' => []];
    }
    $data = json_decode(file_get_contents($file), true);

    return is_array($data) ? $data : quit("$file is not valid JSON, stopped");
};
$save = function (array $data) use ($dir, $file): void {
    is_dir($dir) || mkdir($dir, 0775, true);
    (file_put_contents("$file.tmp", json_encode($data, JSON_UNESCAPED_UNICODE)) !== false && rename("$file.tmp", $file))
        || quit("cannot write $file, stopped");
};

$controller = app(NewsController::class);
$targets = [NewsStatus::REJECTED_MANUALLY->value, NewsStatus::REJECTED_AS_OFF_TOPIC->value];

foreach ($payload['decisions'] as $id => $decision) {
    $to = is_array($decision) ? (int) ($decision['to'] ?? 0) : 0;
    if (!preg_match('/^[1-9]\d*$/', (string) $id) || !in_array($to, $targets, true)) {
        echo "#$id skipped (bad id or target status)\n";
        continue;
    }
    $step = is_string($decision['step'] ?? null) ? $decision['step'] : '';
    $reason = is_string($decision['reason'] ?? null) ? $decision['reason'] : '';

    $model = News::find((int) $id);
    if (!$model || $model->status !== NewsStatus::PENDING_REVIEW) {
        echo "#$id skipped (status " . ($model?->status?->value ?? 'missing') . ")\n";
        continue;
    }
    if ($model->is_content_cleaned || $model->is_translated || $model->is_auto
        || DB::table('jobs')->where('payload', 'like', "%i:$id;%")->exists()) {
        echo "#$id skipped (picked by the user: cleaned, translated or a job queued)\n";
        continue;
    }

    if (!$apply) {
        echo "#$id would go to $to: $reason\n";
        continue;
    }

    $data = $load();
    $data['decisions'][$id] = ['to' => $to, 'step' => $step, 'reason' => $reason];
    $save($data);

    try {
        $to === NewsStatus::REJECTED_AS_OFF_TOPIC->value ? $controller->offtopic($model) : $controller->decline($model);
    } catch (Throwable $e) {
        echo "#$id error: {$e->getMessage()}\n";
    }
    $model->refresh();
    if ($model->status === NewsStatus::PENDING_REVIEW) {
        $data = $load();
        unset($data['decisions'][$id]);
        $save($data);
        echo "#$id unchanged, removed from the run file\n";
        continue;
    }
    echo "#$id -> status {$model->status->value}, review message " . ($model->message_id ? 'kept' : 'deleted') . "\n";
    sleep(1);
}

if (array_key_exists('kept', $payload)) {
    $requested = array_values(array_unique(array_map('intval', $payload['kept'])));
    $existing = News::whereIn('id', $requested ?: [0])->pluck('id')->all();
    $data = $load();
    $decided = ($data['decisions'] ?? []) + ($apply ? [] : $payload['decisions']);
    $kept = array_values(array_diff(array_unique(array_merge($data['kept'] ?? [], $existing)), array_keys($decided)));
    $missing = array_diff($requested, $kept);
    if ($apply) {
        $data['kept'] = $kept;
        $save($data);
        echo 'run file: ' . count($data['decisions']) . ' decisions, ' . count($kept) . ' kept';
    } else {
        echo 'would keep: ' . (implode(',', array_diff($requested, $missing)) ?: 'none');
    }
    echo ($missing ? '; not kept (missing or declined): ' . implode(',', $missing) : '') . "\n";
}

$pending = News::where('status', NewsStatus::PENDING_REVIEW->value)->orderBy('id')->pluck('id');
echo "still pending: {$pending->count()}\n" . ($pending->isNotEmpty() ? $pending->implode(',') . "\n" : '');
