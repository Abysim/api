<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Services\BigCatsService;
use Illuminate\Console\Command;

class RepublishArticleCommand extends Command
{
    protected $signature = 'article:republish {id}';

    protected $description = 'Re-publish (update) an already-published article to BigCats';

    public function handle(): int
    {
        $article = News::find($this->argument('id'));
        if (!$article) {
            $this->error('News not found: ' . $this->argument('id'));
            return self::FAILURE;
        }

        $result = (new BigCatsService())->publishArticle($article, true);
        $this->line(json_encode([
            'result' => $result,
            'published_url' => $article->fresh()->published_url,
        ], JSON_UNESCAPED_UNICODE));

        return $result === false ? self::FAILURE : self::SUCCESS;
    }
}
