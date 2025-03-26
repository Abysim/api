<?php

namespace App\Filament\Resources;

use App\Enums\NewsStatus;
use App\Filament\Resources\NewsResource\Pages;
use App\Models\News;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NewsResource extends Resource
{
    protected static ?string $model = News::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('publish_title')
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->default(null),
                Forms\Components\Textarea::make('publish_content')
                    ->disableGrammarly()
                    ->rows(24)
                    ->columnSpanFull(),
                Forms\Components\Section::make('Information')
                    ->columns(['default' => 2, 'md' => 3])
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\DatePicker::make('date')
                            ->required(),
                        Forms\Components\TextInput::make('publish_tags')
                            ->maxLength(255)
                            ->default(null),
                        Forms\Components\TextInput::make('media')
                            ->maxLength(1024)
                            ->default(null)
                            ->columnSpan(['default' => 2, 'md' => 1]),
                        Forms\Components\TextInput::make('source')
                            ->maxLength(255)
                            ->hiddenLabel(),
                        Forms\Components\TextInput::make('link')
                            ->maxLength(1024)
                            ->hiddenLabel(),
                        Forms\Components\Toggle::make('is_auto')
                            ->inlineLabel(),
                        Forms\Components\Toggle::make('is_translated')
                            ->inlineLabel(),
                        Forms\Components\Toggle::make('is_deep')
                            ->inlineLabel(),
                        Forms\Components\Toggle::make('is_deepest')
                            ->inlineLabel(),
                        Forms\Components\Textarea::make('analysis')
                            ->disableGrammarly()
                            ->rows(12)
                            ->columnSpanFull(),
                    ]),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('date')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('publish_title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('publish_tags')
                    ->searchable(),
                Tables\Columns\TextColumn::make('posted_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', NewsStatus::PENDING_REVIEW);
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
            'index' => Pages\ListNews::route('/'),
            'create' => Pages\CreateNews::route('/create'),
            'edit' => Pages\EditNews::route('/{record}/edit'),
        ];
    }
}
