<?php

namespace App\Filament\Resources;

use App\Enums\FlickrPhotoStatus;
use App\Filament\Resources\FlickrPhotoResource\Pages;
use App\Http\Controllers\FlickrPhotoController;
use App\Models\FlickrPhoto;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class FlickrPhotoResource extends Resource
{
    protected static ?string $model = FlickrPhoto::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Flickr Photos';

    private const THUMBNAIL_WIDTH = 80;
    private const THUMBNAIL_HEIGHT = 60;
    private const COLLAPSE_DELAY_MS = 50;

    private const EXPANDED_STYLES = [
        'position' => 'fixed',
        'left' => '0',
        'top' => '0',
        'right' => '0',
        'bottom' => '0',
        'margin' => 'auto',
        'zIndex' => '99999',
        'background' => 'white',
        'boxShadow' => '0 4px 16px rgba(0,0,0,0.2)',
        'width' => 'auto',
        'height' => 'auto',
        'maxWidth' => 'calc(100vw - 32px)',
        'maxHeight' => 'calc(100vh - 32px)',
        'objectFit' => 'contain',
    ];

    private const COLLAPSED_STYLES = [
        'position' => 'static',
        'left' => '',
        'top' => '',
        'right' => '',
        'bottom' => '',
        'margin' => '',
        'zIndex' => 'auto',
        'background' => 'none',
        'boxShadow' => 'none',
        'maxWidth' => 'none',
        'maxHeight' => 'none',
        'objectFit' => 'cover',
    ];

    public static function table(Table $table): Table
    {
        $allTags = array_unique(array_values(FlickrPhotoController::TAGS));

        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('source_url')
                    ->label('Preview')
                    ->width(self::THUMBNAIL_WIDTH)
                    ->height(self::THUMBNAIL_HEIGHT)
                    ->extraImgAttributes(fn (FlickrPhoto $record): array => [
                        'title' => $record->title,
                        'loading' => 'lazy',
                        'class' => 'object-cover transition-all cursor-pointer',
                        'onmouseover' => self::buildExpandScript(),
                        'onmouseout' => self::buildCollapseScript(),
                        'onclick' => self::buildClickScript(),
                    ]),
                Tables\Columns\TextInputColumn::make('publish_title')
                    ->label('Title')
                    ->rules(['max:255'])
                    ->extraInputAttributes(fn (FlickrPhoto $record): array => [
                        'title' => $record->title,
                        'class' => 'w-full',
                    ])
                    ->grow(),
                Tables\Columns\ViewColumn::make('title_actions')
                    ->label('')
                    ->disabledClick()
                    ->view('filament.tables.columns.title-actions'),
                Tables\Columns\TextInputColumn::make('publish_tags')
                    ->label('Tags')
                    ->rules(['max:255'])
                    ->extraInputAttributes(fn (FlickrPhoto $record): array => [
                        'title' => implode(', ', $record->tags ?? []),
                        'class' => 'max-w-xs',
                        'list' => 'flickr-tags-datalist',
                    ]),
                Tables\Columns\TextColumn::make('owner_username')
                    ->label('Author')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (FlickrPhotoStatus $state): string => match ($state) {
                        FlickrPhotoStatus::CREATED => 'info',
                        FlickrPhotoStatus::PENDING_REVIEW => 'warning',
                        FlickrPhotoStatus::APPROVED => 'success',
                        FlickrPhotoStatus::PUBLISHED => 'success',
                        default => 'danger',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ViewColumn::make('review_actions')
                    ->label('')
                    ->disabledClick()
                    ->view('filament.tables.columns.flickr-review-actions'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(FlickrPhotoStatus::class)
                    ->default(FlickrPhotoStatus::PENDING_REVIEW->value),
            ])
            ->recordUrl(fn (FlickrPhoto $record): string => $record->url, true)
            ->bulkActions([
                Tables\Actions\BulkAction::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $controller = new FlickrPhotoController();
                        foreach ($records as $record) {
                            $controller->approve($record);
                        }
                    })
                    ->deselectRecordsAfterCompletion()
                    ->visible(fn (Tables\Contracts\HasTable $livewire): bool =>
                        ($livewire->getTableFilterState('status')['value'] ?? null)
                            === (string) FlickrPhotoStatus::PENDING_REVIEW->value
                    ),
                Tables\Actions\BulkAction::make('decline')
                    ->label('Decline')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $controller = new FlickrPhotoController();
                        foreach ($records as $record) {
                            $controller->decline($record);
                        }
                    })
                    ->deselectRecordsAfterCompletion()
                    ->visible(fn (Tables\Contracts\HasTable $livewire): bool =>
                        ($livewire->getTableFilterState('status')['value'] ?? null)
                            === (string) FlickrPhotoStatus::PENDING_REVIEW->value
                    ),
                Tables\Actions\BulkAction::make('addTagToTitle')
                    ->label('Add Tag to Title')
                    ->icon('heroicon-o-tag')
                    ->color('info')
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            $record->addTagToTitle();
                        }

                        Notification::make()
                            ->title('Tag added to titles')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
;
    }

    private static function buildStyleAssignments(array $styles, string $target = 'this'): string
    {
        $assignments = [];
        foreach ($styles as $property => $value) {
            $assignments[] = "{$target}.style.{$property}='{$value}'";
        }

        return implode(';', $assignments);
    }

    private static function getCollapsedStyles(): array
    {
        return array_merge(self::COLLAPSED_STYLES, [
            'width' => self::THUMBNAIL_WIDTH . 'px',
            'height' => self::THUMBNAIL_HEIGHT . 'px',
        ]);
    }

    private static function buildExpandScript(): string
    {
        return 'clearTimeout(this.collapseTimer);' . self::buildStyleAssignments(self::EXPANDED_STYLES);
    }

    private static function buildCollapseScript(): string
    {
        $styles = self::buildStyleAssignments(self::getCollapsedStyles(), 'el');

        return "var el=this;clearTimeout(el.collapseTimer);el.collapseTimer=setTimeout(function(){{$styles}}," . self::COLLAPSE_DELAY_MS . ")";
    }

    private static function buildClickScript(): string
    {
        $styles = self::buildStyleAssignments(self::getCollapsedStyles());

        return "if(this.style.position==='fixed'){event.preventDefault();event.stopPropagation();clearTimeout(this.collapseTimer);{$styles}}";
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlickrPhotos::route('/'),
        ];
    }
}
