<?php

namespace App\Filament\Resources\FlickrPhotoResource\Pages;

use App\Filament\Resources\FlickrPhotoResource;
use App\Http\Controllers\FlickrPhotoController;
use App\Models\FlickrPhoto;
use Filament\Resources\Pages\ListRecords;

class ListFlickrPhotos extends ListRecords
{
    protected static string $resource = FlickrPhotoResource::class;

    public function approveRecord(string $recordKey): void
    {
        $record = FlickrPhoto::query()->find($recordKey);
        if (! $record) {
            return;
        }

        (new FlickrPhotoController())->approve($record);
    }

    public function declineRecord(string $recordKey): void
    {
        $record = FlickrPhoto::query()->find($recordKey);
        if (! $record) {
            return;
        }

        (new FlickrPhotoController())->decline($record);
    }

    public function reviewRecord(string $recordKey): void
    {
        $record = FlickrPhoto::query()->find($recordKey);
        if (! $record) {
            return;
        }

        (new FlickrPhotoController())->review($record);
    }

    public function translateTitleRecord(string $recordKey): void
    {
        $record = FlickrPhoto::query()->find($recordKey);
        if (! $record) {
            return;
        }
        (new FlickrPhotoController())->translateText($record, $record->publish_title ?: $record->title);
    }

    public function resetTitleRecord(string $recordKey): void
    {
        $record = FlickrPhoto::query()->find($recordKey);
        if (! $record) {
            return;
        }
        $record->publish_title = $record->title;
        $record->save();
    }
}
