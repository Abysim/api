<?php

namespace App\Filament\Resources;

use App\Enums\FlickrPhotoStatus;
use App\Filament\Resources\FlickrPhotoResource\Pages;
use App\Http\Controllers\FlickrPhotoController;
use App\Models\FlickrPhoto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class FlickrPhotoResource extends Resource
{
    protected static ?string $model = FlickrPhoto::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Flickr Photos';

    public static function table(Table $table): Table
    {
        // Use unique values from FlickrPhotoController::TAGS without additional processing
        $allTags = array_unique(array_values(FlickrPhotoController::TAGS));
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('source_url')
                    ->label('Preview')
                    ->width(80)
                    ->height(60)
                    ->extraImgAttributes(fn (FlickrPhoto $record): array => [
                        'title' => $record->title,
                        'loading' => 'lazy',
                        'class' => 'w-20 h-15 object-fill transition-all cursor-pointer',
                        'onmouseover' =>
                            "this.style.position='fixed';".
                            "this.style.left='0';".
                            "this.style.top='0';".
                            "this.style.right='0';".
                            "this.style.bottom='0';".
                            "this.style.margin='auto';".
                            "this.style.zIndex='1000';".
                            "this.style.background='white';".
                            "this.style.boxShadow='0 4px 16px rgba(0,0,0,0.2)';".
                            "this.style.width='auto';".
                            "this.style.height='auto';".
                            "this.style.maxWidth='calc(100vw - 32px)';".
                            "this.style.maxHeight='calc(100vh - 32px)';",
                        'onmouseout' =>
                            "this.style.position='static';".
                            "this.style.left='';".
                            "this.style.top='';".
                            "this.style.right='';".
                            "this.style.bottom='';".
                            "this.style.margin='';".
                            "this.style.zIndex='auto';".
                            "this.style.background='none';".
                            "this.style.boxShadow='none';".
                            "this.style.width='80px';".
                            "this.style.height='60px';".
                            "this.style.maxWidth='none';".
                            "this.style.maxHeight='none';",
                        'onclick' =>
                            "if(this.style.position==='fixed'){event.preventDefault();event.stopPropagation();".
                            "this.style.position='static';".
                            "this.style.left='';".
                            "this.style.top='';".
                            "this.style.right='';".
                            "this.style.bottom='';".
                            "this.style.margin='';".
                            "this.style.zIndex='auto';".
                            "this.style.background='none';".
                            "this.style.boxShadow='none';".
                            "this.style.width='80px';".
                            "this.style.height='60px';".
                            "this.style.maxWidth='none';".
                            "this.style.maxHeight='none';".
                            // programmatically trigger mouseout to ensure state is reset
                            "if(typeof Event==='function'){var e=new Event('mouseout',{bubbles:true});this.dispatchEvent(e);}else if(document.createEvent){var e=document.createEvent('Event');e.initEvent('mouseout',true,true);this.dispatchEvent(e);}".
                            "}",
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
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(FlickrPhotoStatus::class)
                    ->default(FlickrPhotoStatus::PENDING_REVIEW->value),
            ])
            ->recordUrl(fn($record) => $record->url, true)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
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
                        ->visible(function (Tables\Contracts\HasTable $livewire): bool {
                            return ($livewire->getTableFilterState('status')['value'] ?? null)
                                === (string) FlickrPhotoStatus::PENDING_REVIEW->value;
                        }),
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
                        ->visible(function (Tables\Contracts\HasTable $livewire): bool {
                            return ($livewire->getTableFilterState('status')['value'] ?? null)
                                === (string) FlickrPhotoStatus::PENDING_REVIEW->value;
                        }),
                ]),
            ])
            ->contentFooter(view('filament.tables.partials.flickr-tags-datalist', ['allTags' => $allTags]));
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlickrPhotos::route('/'),
        ];
    }
}
