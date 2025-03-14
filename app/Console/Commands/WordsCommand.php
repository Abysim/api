<?php
/**
 * @author Andrii Kalmus <andrii.kalmus@abysim.com>
 */

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Models\News;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class WordsCommand extends Command
{
    protected $signature = 'words';

    protected $description = 'Search words stats description';

    public function handle(): void
    {
        $this->info('Starting');

        $publishedArticles = News::where(['language' => 'en', 'status' => NewsStatus::PUBLISHED])->get();
        $this->info('Getting rejected articles');
        $rejectedArticles = News::where('language', 'en')->whereIn('status', [2, 8])->get();

        $publishedWords = [];
        $rejectedWords = [];

        foreach ($publishedArticles as $model) {
            foreach ($this->getWords($model) as $word) {
                $publishedWords[$word] = ($publishedWords[$word] ?? 0) + 1;
            }
        }

        foreach ($publishedWords as $word => $count) {
            $publishedWords[$word] = $count / count($publishedArticles);

            if ($publishedWords[$word] < 0.4) {
                unset($publishedWords[$word]);
            }
        }

        foreach ($rejectedArticles as $model) {
            foreach ($this->getWords($model) as $word) {
                $rejectedWords[$word] = ($rejectedWords[$word] ?? 0) + 1;
            }
        }

        foreach ($rejectedWords as $word => $count) {
            $rejectedWords[$word] = $count / count($rejectedArticles);

            if ($rejectedWords[$word] < 0.2) {
                unset($rejectedWords[$word]);
            }
        }

        $wordsToReject = array_keys(array_diff_key($rejectedWords, $publishedWords));
        $wordsToPublish = array_keys(array_diff_key($publishedWords, $rejectedWords));

        $this->info('Words to publish: ' . implode(', ', $wordsToPublish));
        $this->error('Words to reject: ' . implode(', ', $wordsToReject));
    }

    private function getWords($model): array
    {
        return array_unique(array_map( fn ($word) => Str::lower($word), preg_split('/\s+/', preg_replace(
                '/[^\p{L}\p{N}\p{Zs}]/u',
                ' ',
                $model->title . ' ' . $model->content)
        )));
    }
}
