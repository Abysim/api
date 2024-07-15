<?php

namespace App\Console\Commands;

use App\Http\Controllers\FlickrPhotoController;
use Illuminate\Console\Command;

class FlickrPhotoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flickr-photo';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process Flickr photo posting';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $controller = new FlickrPhotoController();
        $controller->process();
    }
}
