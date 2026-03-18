<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ClearNewsCacheCommand extends Command
{
    protected $signature = 'news:clear-url-cache';

    protected $description = 'Clear cached news article URLs (free_news_seen_url entries)';

    public function handle(): int
    {
        $cachePath = storage_path('framework/cache/data');

        if (!is_dir($cachePath)) {
            $this->warn('Cache directory not found: ' . $cachePath);
            return 1;
        }

        $deleted = 0;

        foreach (File::allFiles($cachePath) as $file) {
            $content = @file_get_contents($file->getPathname());
            // URL seen cache stores boolean true: file content is "{timestamp}b:1;"
            if ($content !== false && preg_match('/^\d+b:1;$/', $content)) {
                unlink($file->getPathname());
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} cached news URL entries.");

        return 0;
    }
}
