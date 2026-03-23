<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\QueueWorkDynamic;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for the dynamic self-scaling queue worker.
 *
 * Strategy: extend QueueWorkDynamic to override external dependencies
 * (posix_kill, exec, queue:work --once) so we can test the PID tracking,
 * cap enforcement, and spawning logic in isolation.
 */
class QueueWorkDynamicTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Use array cache driver for atomic, in-memory testing
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_registers_pid_on_start(): void
    {
        $worker = $this->makeWorker(pid: 1000, alivePids: [], hasJob: false, maxTime: 0);
        $worker->runWorker();

        // Worker registered, then deregistered on exit (max-time=0 exits immediately)
        $this->assertContains(1000, $worker->registeredPids);
        $this->assertContains(1000, $worker->deregisteredPids);
    }

    public function test_exits_when_at_capacity(): void
    {
        // Pre-fill 4 alive workers
        Cache::put(QueueWorkDynamic::CACHE_KEY, [100, 101, 102, 103], 7500);

        $worker = $this->makeWorker(
            pid: 200,
            alivePids: [100, 101, 102, 103],
            hasJob: true,
            maxWorkers: 4
        );
        $worker->runWorker();

        // Should NOT have registered (at capacity)
        $this->assertEmpty($worker->registeredPids);
        $this->assertEmpty($worker->spawnedCommands);
        Log::shouldHaveReceived('info')->withArgs(fn($msg) => str_contains($msg, 'at capacity'));
    }

    public function test_spawns_replacement_when_job_found_and_under_cap(): void
    {
        $worker = $this->makeWorker(
            pid: 1000,
            alivePids: [], // only self after registering
            hasJob: true,
            maxWorkers: 4
        );
        $worker->runWorker();

        $this->assertCount(1, $worker->spawnedCommands);
        $this->assertStringContainsString('queue:work-dynamic', $worker->spawnedCommands[0]);
        $this->assertStringContainsString('--max-workers=4', $worker->spawnedCommands[0]);
    }

    public function test_does_not_spawn_when_at_cap(): void
    {
        // Register 3 other alive workers + self = 4
        Cache::put(QueueWorkDynamic::CACHE_KEY, [100, 101, 102], 7500);

        $worker = $this->makeWorker(
            pid: 200,
            alivePids: [100, 101, 102, 200], // all alive including self
            hasJob: true,
            maxWorkers: 4
        );
        $worker->runWorker();

        $this->assertEmpty($worker->spawnedCommands);
    }

    public function test_prunes_dead_pids_on_count(): void
    {
        // 3 PIDs in cache, but only 1 is alive
        Cache::put(QueueWorkDynamic::CACHE_KEY, [100, 101, 102], 7500);

        $worker = $this->makeWorker(
            pid: 200,
            alivePids: [102, 200], // 100 and 101 are dead
            hasJob: true,
            maxWorkers: 4
        );
        $worker->runWorker();

        // Should have spawned (only 2 alive: 102 + self)
        $this->assertCount(1, $worker->spawnedCommands);

        // Cache should be pruned — dead PIDs removed
        $pids = Cache::get(QueueWorkDynamic::CACHE_KEY, []);
        $this->assertNotContains(100, $pids);
        $this->assertNotContains(101, $pids);
    }

    public function test_exits_after_processing_one_job(): void
    {
        $worker = $this->makeWorker(pid: 1000, alivePids: [], hasJob: true);
        $worker->runWorker();

        $this->assertEquals(1, $worker->jobsProcessed);
    }

    public function test_exits_on_max_time_when_idle(): void
    {
        $worker = $this->makeWorker(pid: 1000, alivePids: [], hasJob: false, maxTime: 0);
        $worker->runWorker();

        $this->assertEquals(0, $worker->jobsProcessed);
        Log::shouldHaveReceived('info')->withArgs(fn($msg) => str_contains($msg, 'max-time'));
    }

    public function test_deregisters_pid_on_exit(): void
    {
        $worker = $this->makeWorker(pid: 1000, alivePids: [], hasJob: true);
        $worker->runWorker();

        $pids = Cache::get(QueueWorkDynamic::CACHE_KEY, []);
        $this->assertNotContains(1000, $pids);
    }

    public function test_passes_options_to_spawned_worker(): void
    {
        $worker = $this->makeWorker(
            pid: 1000,
            alivePids: [],
            hasJob: true,
            maxWorkers: 3,
            maxTime: 30,
        );
        $worker->runWorker();

        $cmd = $worker->spawnedCommands[0] ?? '';
        $this->assertStringContainsString('--max-workers=3', $cmd);
        $this->assertStringContainsString('--max-time=30', $cmd);
    }

    private function makeWorker(
        int $pid = 1000,
        array $alivePids = [],
        bool $hasJob = false,
        int $maxWorkers = 4,
        int $maxTime = 55,
    ): TestableQueueWorkDynamic {
        $worker = new TestableQueueWorkDynamic($pid, $alivePids, $hasJob);
        $worker->setLaravel($this->app);

        // Simulate artisan option parsing
        $worker->testOptions = [
            'max-workers' => $maxWorkers,
            'max-time' => $maxTime,
            'sleep' => 0,  // no sleeping in tests
            'memory' => 512, // high limit so it doesn't trigger
        ];

        Log::spy();

        return $worker;
    }
}

/**
 * Testable subclass that overrides external dependencies.
 */
class TestableQueueWorkDynamic extends QueueWorkDynamic
{
    public array $spawnedCommands = [];
    public array $registeredPids = [];
    public array $deregisteredPids = [];
    public int $jobsProcessed = 0;
    public array $testOptions = [];

    private int $fakePid;
    private array $fakeAlivePids;
    private bool $fakeHasJob;
    private bool $jobConsumed = false;

    public function __construct(int $pid, array $alivePids, bool $hasJob)
    {
        // Don't call parent — we override everything
        $this->fakePid = $pid;
        $this->fakeAlivePids = $alivePids;
        $this->fakeHasJob = $hasJob;

        $this->setName('queue:work-dynamic');
    }

    public function runWorker(): int
    {
        // Bypass Symfony input parsing — inject options directly
        return $this->handle();
    }

    public function option($key = null)
    {
        if ($key === null) {
            return $this->testOptions;
        }
        return $this->testOptions[$key] ?? null;
    }

    protected function getCurrentPid(): int
    {
        return $this->fakePid;
    }

    protected function isProcessAlive(int $pid): bool
    {
        return in_array($pid, $this->fakeAlivePids);
    }

    protected function execInBackground(string $cmd): void
    {
        $this->spawnedCommands[] = $cmd;
    }

    protected function hasAvailableJob(): bool
    {
        return $this->fakeHasJob && !$this->jobConsumed;
    }

    protected function registerWorker(int $pid, int $maxWorkers): bool
    {
        $result = parent::registerWorker($pid, $maxWorkers);
        if ($result) {
            $this->registeredPids[] = $pid;
            // Add self to alive PIDs so subsequent count checks see us
            if (!in_array($pid, $this->fakeAlivePids)) {
                $this->fakeAlivePids[] = $pid;
            }
        }
        return $result;
    }

    protected function deregisterWorker(int $pid): void
    {
        parent::deregisterWorker($pid);
        $this->deregisteredPids[] = $pid;
    }

    public function call($command, array $arguments = [])
    {
        // Simulate queue:work --once processing a job
        $this->jobsProcessed++;
        $this->jobConsumed = true;
        return 0;
    }
}
