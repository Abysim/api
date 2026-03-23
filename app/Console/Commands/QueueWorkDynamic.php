<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QueueWorkDynamic extends Command
{
    protected $signature = 'queue:work-dynamic
        {--max-workers=4 : Maximum concurrent workers}
        {--max-time=55 : Seconds to wait idle before exiting}
        {--sleep=3 : Seconds between queue polls}
        {--memory=100 : Memory limit in MB}';

    protected $description = 'Self-scaling queue worker: spawns replacements when busy, exits when idle';

    const CACHE_KEY = 'queue:dynamic:pids';
    const LOCK_KEY = 'queue:dynamic:pids:lock';
    const CACHE_TTL = 7500; // longest job timeout (7200) + buffer

    public function handle(): int
    {
        $maxWorkers = (int) $this->option('max-workers');
        $maxTime = (int) $this->option('max-time');
        $sleep = (int) $this->option('sleep');
        $memoryLimit = (int) $this->option('memory');
        $startTime = time();
        $pid = $this->getCurrentPid();

        if (!$this->registerWorker($pid, $maxWorkers)) {
            Log::info("[queue:dynamic] Worker {$pid} exiting: at capacity ({$maxWorkers})");
            return 0;
        }

        Log::info("[queue:dynamic] Worker {$pid} started");

        try {
            while (true) {
                if (time() - $startTime >= $maxTime) {
                    Log::info("[queue:dynamic] Worker {$pid} exiting: max-time ({$maxTime}s)");
                    break;
                }

                if (memory_get_usage(true) / 1024 / 1024 >= $memoryLimit) {
                    Log::info("[queue:dynamic] Worker {$pid} exiting: memory ({$memoryLimit}MB)");
                    break;
                }

                if (!$this->hasAvailableJob()) {
                    sleep($sleep);
                    continue;
                }

                // Job found — spawn replacement if under cap
                // Note: TOCTOU race exists between hasAvailableJob() and queue:work --once.
                // Another worker may claim the job first; the spawn is still safe (extras exit at cap).
                $count = $this->getAliveCount();
                if ($count < $maxWorkers) {
                    $this->spawnWorker();
                    Log::info("[queue:dynamic] Worker {$pid} spawned replacement, count: " . ($count + 1) . "/{$maxWorkers}");
                } else {
                    Log::info("[queue:dynamic] Worker {$pid} at cap, no spawn ({$count}/{$maxWorkers})");
                }

                // Process exactly one job
                $this->call('queue:work', ['--once' => true]);
                Log::info("[queue:dynamic] Worker {$pid} finished job, exiting");
                break;
            }
        } finally {
            $this->deregisterWorker($pid);
        }

        return 0;
    }

    protected function hasAvailableJob(): bool
    {
        return DB::table('jobs')
            ->where('queue', 'default')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->exists();
    }

    protected function registerWorker(int $pid, int $maxWorkers): bool
    {
        $registered = false;

        $this->withPidLock(function () use ($pid, $maxWorkers, &$registered) {
            $pids = $this->getAlivePids();

            if (count($pids) >= $maxWorkers) {
                return;
            }

            $pids[] = $pid;
            Cache::put(self::CACHE_KEY, $pids, self::CACHE_TTL);
            $registered = true;
        });

        return $registered;
    }

    protected function deregisterWorker(int $pid): void
    {
        $this->withPidLock(function () use ($pid) {
            $pids = $this->getAlivePids();
            $pids = array_values(array_filter($pids, fn($p) => $p !== $pid));
            Cache::put(self::CACHE_KEY, $pids, self::CACHE_TTL);
        });
    }

    protected function getAliveCount(): int
    {
        $count = 0;

        $this->withPidLock(function () use (&$count) {
            $pids = $this->getAlivePids();
            Cache::put(self::CACHE_KEY, $pids, self::CACHE_TTL);
            $count = count($pids);
        });

        return $count;
    }

    /**
     * Get PIDs that are still alive, pruning dead ones.
     */
    protected function getAlivePids(): array
    {
        $pids = Cache::get(self::CACHE_KEY, []);

        return array_values(array_filter($pids, fn($pid) => $this->isProcessAlive($pid)));
    }

    protected function isProcessAlive(int $pid): bool
    {
        return function_exists('posix_kill') && posix_kill($pid, 0);
    }

    protected function withPidLock(callable $callback): void
    {
        try {
            Cache::lock(self::LOCK_KEY, 5)->block(5, $callback);
        } catch (LockTimeoutException) {
            Log::warning('[queue:dynamic] Failed to acquire PID lock');
        }
    }

    protected function spawnWorker(): void
    {
        $cmd = sprintf(
            'php %s %s --max-workers=%d --max-time=%d --sleep=%d --memory=%d > /dev/null 2>&1 &',
            base_path('artisan'),
            $this->getName(),
            $this->option('max-workers'),
            $this->option('max-time'),
            $this->option('sleep'),
            $this->option('memory')
        );

        $this->execInBackground($cmd);
    }

    protected function execInBackground(string $cmd): void
    {
        exec($cmd);
    }

    protected function getCurrentPid(): int
    {
        return getmypid();
    }
}
