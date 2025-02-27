<?php

namespace App\Console\Commands;

use App\Http\Controllers\NewsController;
use Illuminate\Console\Command;

class NewsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'news {load?} {force?} {lang?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process news';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $controller = app(NewsController::class);
        $controller->process($this->argument('load') ?? true, $this->argument('force'), $this->argument('lang'));
    }
}
