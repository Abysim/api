<?php

namespace App\Jobs;

use App\Enums\NewsStatus;
use App\Http\Controllers\NewsController;
use App\Models\News;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReloadNewsMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 120;

    public function __construct(public int $id)
    {
    }

    public function handle(): void
    {
        $model = News::find($this->id);

        if (!$model || !in_array($model->status, [NewsStatus::REJECTED_MANUALLY, NewsStatus::REJECTED_AS_OFF_TOPIC])) {
            return;
        }

        $originalStatus = $model->status;

        $model->loadMediaFile();

        if (empty($model->filename)) {
            Log::error("$this->id: ReloadNewsMediaJob media download failed: $model->media");
            return;
        }

        app(NewsController::class)->sendNewsToReviewPublic($model);

        // Protect against sendNewsToReview setting FAILED status on Telegram failure
        if ($model->status === NewsStatus::FAILED) {
            $model->status = $originalStatus;
            $model->save();
            Log::error("$this->id: ReloadNewsMediaJob Telegram send failed, status reset to {$originalStatus->name}");
        }
    }
}
