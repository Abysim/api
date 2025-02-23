<?php
/**
 * @author Andrii Kalmus <andrii.kalmus@abysim.com>
 */

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Models\News;
use App\Services\BigCatsService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PublishNewsCommand extends Command
{
    protected $signature = 'publish:news';

    protected $description = 'Publish unpublished new to the website';

    /**
     * @throws Exception
     */
    public function handle(): void
    {
        $news = News::query()
            ->where('status', NewsStatus::PUBLISHED)
            ->whereNull('published_url')
            ->orderBy('published_at')
            ->get();

        $service = new BigCatsService();
        foreach ($news as $model) {
            $result = $service->publishNews($model);
            if ($result) {
                $this->info($model->id . ': News published successfully');
            } else {
                $this->error($model->id . ': News publishing failed');
            }
        }
    }
}
