<?php

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Jobs\AnalyzeNewsJob;
use App\Models\News;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ResumeOrphanedAnalysisCommand extends Command
{
    protected $signature = 'news:resume-orphaned';

    protected $description = 'Resume AnalyzeNewsJob instances killed by the hoster';

    public function handle(): void
    {
        $orphaned = News::where('status', NewsStatus::BEING_PROCESSED)
            ->where('updated_at', '<', now()->subSeconds(AnalyzeNewsJob::TIMEOUT))
            ->where('updated_at', '>', now()->subWeek())
            ->where('is_auto', true)
            ->get();

        foreach ($orphaned as $article) {
            $cacheKey = AnalyzeNewsJob::CACHE_KEY_PREFIX . $article->id;
            $state = Cache::get($cacheKey);
            if ($state && !empty($state['pid'])) {
                if (function_exists('posix_kill') && posix_kill($state['pid'], 0)) {
                    $this->info("Skipping article {$article->id} — worker PID {$state['pid']} still alive");
                    continue;
                }
            }

            AnalyzeNewsJob::dispatch($article->id);
            $this->info("Dispatched resume for article {$article->id}");
        }
    }
}
