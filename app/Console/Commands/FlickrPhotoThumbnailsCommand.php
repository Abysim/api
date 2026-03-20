<?php

namespace App\Console\Commands;

use App\Enums\FlickrPhotoStatus;
use App\Models\FlickrPhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use JeroenG\Flickr\FlickrLaravelFacade;

class FlickrPhotoThumbnailsCommand extends Command
{
    protected $signature = 'flickr-photo:thumbnails';

    protected $description = 'Populate thumbnail data for photos missing it';

    public function handle()
    {
        $total = FlickrPhoto::whereNull('thumbnail_url')
            ->whereIn('status', [FlickrPhotoStatus::APPROVED, FlickrPhotoStatus::PUBLISHED])
            ->count();

        $this->info("Found {$total} photos without thumbnail data.");

        $success = 0;
        $failed = 0;

        FlickrPhoto::whereNull('thumbnail_url')
            ->whereIn('status', [FlickrPhotoStatus::APPROVED, FlickrPhotoStatus::PUBLISHED])
            ->chunkById(100, function ($photos) use (&$success, &$failed) {
                foreach ($photos as $photo) {
                    try {
                        $sizesResponse = FlickrLaravelFacade::request('flickr.photos.getSizes', [
                            'photo_id' => $photo->id,
                        ]);

                        if ($sizesResponse->getStatus() != 'ok') {
                            $this->warn("{$photo->id}: Failed to get sizes from Flickr");
                            $failed++;
                            continue;
                        }

                        if ($photo->applyThumbnailFromSizes($sizesResponse->sizes['size'])) {
                            $photo->save();
                            $this->line("{$photo->id}: {$photo->thumbnail_width}x{$photo->thumbnail_height} - {$photo->thumbnail_url}");
                            $success++;
                        } else {
                            $this->warn("{$photo->id}: No suitable thumbnail size found");
                            $failed++;
                        }
                    } catch (\Exception $e) {
                        $this->error("{$photo->id}: {$e->getMessage()}");
                        Log::error("{$photo->id}: Thumbnail backfill error: {$e->getMessage()}");
                        $failed++;
                    }
                }
            });

        $this->info("Done. Success: {$success}, Failed: {$failed}");
    }
}
