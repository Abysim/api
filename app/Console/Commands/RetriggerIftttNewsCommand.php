<?php

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Helpers\SentenceHasher;
use App\Models\News;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RetriggerIftttNewsCommand extends Command
{
    protected $signature = 'news:retrigger-ifttt
                            {--since= : Only articles published at/after this date (YYYY-MM-DD or full datetime). Required.}
                            {--delay=45 : Seconds to wait between fires (paces IFTTT/Telegram/X rate limits)}
                            {--limit= : Cap the number of articles fired (safety valve for a test run)}
                            {--dry-run : List what would fire without sending anything}';

    protected $description = 'Re-fire the IFTTT "news" webhook for already-PUBLISHED articles (channel backfill after an IFTTT outage). Does NOT touch BigCats, Bluesky, status, or DB.';

    public function handle(): int
    {
        $since = $this->option('since');
        if (empty($since)) {
            $this->error('--since is required (e.g. --since=2026-06-11). Refusing to backfill the entire history.');
            return self::FAILURE;
        }

        $key = config('services.ifttt.webhook_key');
        if (empty($key) && !$this->option('dry-run')) {
            $this->error('IFTTT webhook key is empty (services.ifttt.webhook_key). Aborting.');
            return self::FAILURE;
        }

        $delay = (int) $this->option('delay');
        $dry = (bool) $this->option('dry-run');

        $query = News::where('status', NewsStatus::PUBLISHED)
            ->where('platform', '!=', 'article')
            ->where('published_at', '>=', $since)
            ->orderBy('published_at');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $items = $query->get();
        $total = $items->count();

        if ($total === 0) {
            $this->info("No PUBLISHED articles since {$since}. Nothing to re-fire.");
            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . "{$total} article(s) to re-fire since {$since}.");
        if (!$dry) {
            $this->warn(sprintf('Estimated time at %ds/article: ~%d min. Press Ctrl-C now to abort.', $delay, (int) ceil($total * $delay / 60)));
        }

        $fired = 0;
        $failed = 0;
        foreach ($items as $i => $model) {
            // Reconstruct the exact publishNews() payload, minus the BigCats $image
            // (already on BigCats) — fall back to media, then the stored file URL.
            if (empty($model->media) && empty($model->filename)) {
                $model->loadMediaFile();
            }

            $value1 = $model->getShortCaption();
            $value2 = $model->media ?: $model->getFileUrl();
            $value3 = SentenceHasher::truncateAtSentence(trim(Str::replace(
                ['### ', '## ', '# ', '---'],
                '',
                Str::of(Str::inlineMarkdown($model->publish_content))->stripTags()
            )), 20000);

            $label = sprintf('[%d/%d] #%d %s | %s',
                $i + 1, $total, $model->id, optional($model->published_at)->format('Y-m-d H:i'),
                Str::limit((string) $model->publish_title, 60)
            );

            if ($dry) {
                $this->line($label . (empty($value2) ? '  ⚠ no image' : ''));
                continue;
            }

            $response = Http::post(
                'https://maker.ifttt.com/trigger/news/with/key/' . $key,
                ['value1' => $value1, 'value2' => $value2, 'value3' => $value3]
            );

            Log::info($model->id . ': retrigger-ifttt news ' . $response->status());

            if ($response->successful()) {
                $fired++;
                $this->line($label . '  ✓');
            } else {
                $failed++;
                $this->error($label . '  ✗ ' . $response->status());
            }

            if ($i < $total - 1) {
                sleep($delay);
            }
        }

        if ($dry) {
            $this->info("[DRY RUN] {$total} article(s) would be re-fired. Re-run without --dry-run to send.");
        } else {
            $this->info("Done. Fired {$fired}, failed {$failed}.");
        }

        return self::SUCCESS;
    }
}
