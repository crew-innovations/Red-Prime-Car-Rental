<?php

namespace App\Filament\Concerns;

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared "show on website" controls for Cars, Yachts and Trips, so the three
 * resources behave the same way (Clause 4: the CRM controls the website).
 */
class WebsitePublishing
{
    /** Sidebar section on the edit form with the show/feature switches. */
    public static function formSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Website')
            ->description('Controls how this item appears on the public website.')
            ->icon('heroicon-o-globe-alt')
            ->schema([
                Forms\Components\Toggle::make('is_published')
                    ->label('Show on website')
                    ->helperText('Hidden items stay in the CRM but are not visible to customers.')
                    ->live(),
                Forms\Components\Toggle::make('is_featured')
                    ->label('Featured on homepage'),
            ]);
    }

    /** Public page address. $urlPrefix is the public path, e.g. "cars". */
    public static function slugField(string $urlPrefix): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('slug')
            ->label('Page address')
            ->prefix("/{$urlPrefix}/")
            ->maxLength(255)
            ->unique(ignoreRecord: true)
            ->alphaDash()
            ->helperText('Leave empty to create it from the name.');
    }

    /** SEO fields shown in the "Website & SEO" tab. */
    public static function seoFields(): array
    {
        return [
            Forms\Components\TextInput::make('meta_title')
                ->label('SEO title')
                ->maxLength(70)
                ->helperText('Shown in Google results. Best under 60 characters. Leave empty to use the name.'),
            Forms\Components\Textarea::make('meta_description')
                ->label('SEO description')
                ->maxLength(300)
                ->rows(3)
                ->helperText('Shown under the title in Google. Best around 150 characters.'),
        ];
    }

    public static function toggleColumn(): Tables\Columns\ToggleColumn
    {
        return Tables\Columns\ToggleColumn::make('is_published')
            ->label('Website')
            ->sortable()
            ->afterStateUpdated(fn (Model $record, bool $state) => Notification::make()
                ->title($state ? 'Now visible on the website' : 'Hidden from the website')
                ->success()
                ->send());
    }

    public static function tableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('toggleWebsite')
            ->label(fn (Model $record) => $record->is_published ? 'Hide from website' : 'Show on website')
            ->icon(fn (Model $record) => $record->is_published ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
            ->action(fn (Model $record) => $record->update(['is_published' => ! $record->is_published]))
            ->hidden(fn (Model $record) => method_exists($record, 'trashed') && $record->trashed());
    }

    /** @return array<Tables\Actions\BulkAction> */
    public static function bulkActions(): array
    {
        return [
            Tables\Actions\BulkAction::make('publish')
                ->label('Show on website')
                ->icon('heroicon-o-eye')
                ->action(fn (Collection $records) => $records->each->update(['is_published' => true]))
                ->deselectRecordsAfterCompletion(),
            Tables\Actions\BulkAction::make('unpublish')
                ->label('Hide from website')
                ->icon('heroicon-o-eye-slash')
                ->action(fn (Collection $records) => $records->each->update(['is_published' => false]))
                ->deselectRecordsAfterCompletion(),
        ];
    }

    public static function filter(): Tables\Filters\TernaryFilter
    {
        return Tables\Filters\TernaryFilter::make('is_published')
            ->label('On website')
            ->trueLabel('Visible')
            ->falseLabel('Hidden');
    }
}
