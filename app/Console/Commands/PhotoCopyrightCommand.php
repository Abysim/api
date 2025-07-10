<?php
/**
 * @author Andrii Kalmus <andrii.kalmus@abysim.com>
 */

namespace App\Console\Commands;

use App\Http\Controllers\FlickrPhotoController;
use Illuminate\Console\Command;

class PhotoCopyrightCommand extends Command
{
    protected $signature = 'photo:copyright';

    protected $description = 'Copyright filter';

    public function handle(): void
    {
        $controller = new FlickrPhotoController();
        $controller->copyright();
    }
}
