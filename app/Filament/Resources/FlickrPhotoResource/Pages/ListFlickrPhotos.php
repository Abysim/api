<?php

namespace App\Filament\Resources\FlickrPhotoResource\Pages;

use App\Filament\Resources\FlickrPhotoResource;
use App\Http\Controllers\FlickrPhotoController;
use App\Models\FlickrPhoto;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListFlickrPhotos extends ListRecords
{
    protected static string $resource = FlickrPhotoResource::class;

    public function getFooter(): ?View
    {
        $allTags = array_unique(array_values(FlickrPhotoController::TAGS));

        return view('filament.tables.partials.flickr-tags-datalist', ['allTags' => $allTags]);
    }

    protected function findRecordOrFail(string $recordKey): ?FlickrPhoto
    {
        if (!ctype_digit($recordKey)) {
            Notification::make()
                ->title('Invalid record ID')
                ->danger()
                ->send();

            return null;
        }

        /** @var FlickrPhoto|null $record */
        $record = FlickrPhoto::query()->find($recordKey);

        if (!$record) {
            Notification::make()
                ->title('Record not found')
                ->danger()
                ->send();

            return null;
        }

        return $record;
    }

    public function approveRecord(string $recordKey): void
    {
        if (!$record = $this->findRecordOrFail($recordKey)) {
            return;
        }

        (new FlickrPhotoController())->approve($record);

        Notification::make()
            ->title('Photo approved successfully')
            ->success()
            ->send();
    }

    public function declineRecord(string $recordKey): void
    {
        if (!$record = $this->findRecordOrFail($recordKey)) {
            return;
        }

        (new FlickrPhotoController())->decline($record);

        Notification::make()
            ->title('Photo declined successfully')
            ->success()
            ->send();
    }

    public function reviewRecord(string $recordKey): void
    {
        if (!$record = $this->findRecordOrFail($recordKey)) {
            return;
        }

        (new FlickrPhotoController())->review($record);

        Notification::make()
            ->title('Photo sent for review')
            ->success()
            ->send();
    }

    public function translateTitleRecord(string $recordKey): void
    {
        if (!$record = $this->findRecordOrFail($recordKey)) {
            return;
        }

        (new FlickrPhotoController())->translateText($record, $record->publish_title ?: $record->title);

        Notification::make()
            ->title('Title translated successfully')
            ->success()
            ->send();
    }

    public function resetTitleRecord(string $recordKey): void
    {
        if (!$record = $this->findRecordOrFail($recordKey)) {
            return;
        }

        $record->publish_title = $record->title;
        $record->save();

        Notification::make()
            ->title('Title reset to original')
            ->success()
            ->send();
    }
}
