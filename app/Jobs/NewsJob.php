<?php
/**
 * @author Andrii Kalmus <andrii.kalmus@abysim.com>
 */

namespace App\Jobs;

use App\Http\Controllers\NewsController;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class NewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 2400;

    public function __construct(
        private readonly ?bool $load = false,
        private readonly ?bool $force = false,
        private readonly ?string $lang = null
    ) {}

    /**
     * @throws Exception
     */
    public function handle(): void
    {
        $controller = app(NewsController::class);
        $controller->process($this->load ?? false, $this->force ?? false, $this->lang ?? null);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NewsJob failed: ' . $exception->getMessage());
    }
}
