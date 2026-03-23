<?php

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Jobs\AnalyzeNewsJob;
use App\Jobs\ApplyNewsAnalysisJob;
use App\Models\News;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ResumeOrphanedAnalysisCommand extends Command
{
    protected $signature = 'news:resume-orphaned';

    protected $description = 'Resume orphaned AnalyzeNewsJob and ApplyNewsAnalysisJob instances';

    public function handle(): void
    {
        // Analyze orphans: status=10, analysis empty (analyzer was running)
        $analyzeOrphaned = News::where('status', NewsStatus::BEING_PROCESSED)
            ->whereNull('analysis')
            ->where('updated_at', '<', now()->subSeconds(AnalyzeNewsJob::TIMEOUT))
            ->where('updated_at', '>', now()->subWeek())
            ->where('is_auto', true)
            ->get();

        foreach ($analyzeOrphaned as $article) {
            $cacheKey = AnalyzeNewsJob::CACHE_KEY_PREFIX . $article->id;
            $state = Cache::get($cacheKey);
            if ($state && !empty($state['pid'])) {
                if (function_exists('posix_kill') && posix_kill($state['pid'], 0)) {
                    $this->info("Skipping analyze article {$article->id} — worker PID {$state['pid']} still alive");
                    continue;
                }
            }

            $lockKey = 'orphan_dispatched_' . $article->id;
            if (Cache::has($lockKey)) {
                $this->info("Skipping article {$article->id} — already dispatched recently");
                continue;
            }

            AnalyzeNewsJob::dispatch($article->id);
            Cache::put($lockKey, true, 600);
            Log::warning("Orphan recovery: dispatched AnalyzeNewsJob for article {$article->id}");
            $this->info("Dispatched analyze resume for article {$article->id}");
        }

        // Apply orphans: status=10, analysis set (applier was running)
        $applyOrphaned = News::where('status', NewsStatus::BEING_PROCESSED)
            ->whereNotNull('analysis')
            ->where('updated_at', '<', now()->subSeconds(300))
            ->where('updated_at', '>', now()->subWeek())
            ->where('is_auto', true)
            ->get();

        foreach ($applyOrphaned as $article) {
            $cacheKey = ApplyNewsAnalysisJob::CACHE_KEY_PREFIX . $article->id;
            $state = Cache::get($cacheKey);
            if ($state && !empty($state['pid'])) {
                if (function_exists('posix_kill') && posix_kill($state['pid'], 0)) {
                    $this->info("Skipping apply article {$article->id} — worker PID {$state['pid']} still alive");
                    continue;
                }
            }

            $lockKey = 'orphan_dispatched_' . $article->id;
            if (Cache::has($lockKey)) {
                $this->info("Skipping article {$article->id} — already dispatched recently");
                continue;
            }

            ApplyNewsAnalysisJob::dispatch($article->id);
            Cache::put($lockKey, true, 600);
            Log::warning("Orphan recovery: dispatched ApplyNewsAnalysisJob for article {$article->id}");
            $this->info("Dispatched apply resume for article {$article->id}");
        }
    }
}
