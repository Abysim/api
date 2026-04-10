<?php

namespace App\Filament\Resources\NewsResource\Pages;

use App\Filament\Resources\NewsResource;
use App\Enums\NewsStatus;
use App\Http\Controllers\NewsController;
use App\Models\News;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListNews extends ListRecords
{
    protected static string $resource = NewsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function findRecordOrFail(string $recordKey): ?News
    {
        if (!ctype_digit($recordKey)) {
            Notification::make()
                ->title('Invalid record ID')
                ->danger()
                ->send();

            return null;
        }

        /** @var News|null $record */
        $record = News::query()->find($recordKey);

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

        try {
            (app(NewsController::class))->approve($record);

            Notification::make()
                ->title('Article approved')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Approve failed: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function declineRecord(string $recordKey): void
    {
        if (!$record = $this->findRecordOrFail($recordKey)) {
            return;
        }

        try {
            (app(NewsController::class))->decline($record);

            Notification::make()
                ->title('Article declined')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Decline failed: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function offtopicRecord(string $recordKey): void
    {
        if (!$record = $this->findRecordOrFail($recordKey)) {
            return;
        }

        try {
            (app(NewsController::class))->offtopic($record);

            Notification::make()
                ->title('Article marked off-topic')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Off-topic failed: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function publishRecord(string $recordKey): void
    {
        if (!$record = $this->findRecordOrFail($recordKey)) {
            return;
        }

        try {
            (app(NewsController::class))->publishArticle($record);

            $record->refresh();
            if ($record->status === NewsStatus::PUBLISHED) {
                Notification::make()
                    ->title('Article published')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Article not published to BigCats')
                    ->danger()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Publish failed: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function sendToReviewRecord(string $recordKey): void
    {
        if (!$record = $this->findRecordOrFail($recordKey)) {
            return;
        }

        try {
            (app(NewsController::class))->cancel($record);

            Notification::make()
                ->title('Article sent back to review')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Send to review failed: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function restoreRecord(string $recordKey): void
    {
        if (!$record = $this->findRecordOrFail($recordKey)) {
            return;
        }

        try {
            $result = (app(NewsController::class))->restore($record);

            if ($result === true) {
                Notification::make()
                    ->title('Article restored to review')
                    ->success()
                    ->send();
            } elseif ($result === 'queued') {
                Notification::make()
                    ->title('Restore queued — media is being re-downloaded')
                    ->info()
                    ->send();
            } else {
                Notification::make()
                    ->title('Restore failed')
                    ->danger()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Restore failed: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
