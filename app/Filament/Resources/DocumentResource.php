<?php

namespace App\Filament\Resources;

use App\Enums\DocumentStatus;
use App\Filament\Resources\DocumentResource\Pages;
use App\Filament\Support\DocumentActions;
use App\Models\CustomerDocument;
use App\Support\Access;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * All customer documents in one list, so staff can review new uploads
 * without opening each customer. Upload and request links stay on the
 * customer and booking pages (Schedule A11).
 */
class DocumentResource extends Resource
{
    protected static ?string $model = CustomerDocument::class;

    protected static ?string $slug = 'documents';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Documents';

    protected static ?string $modelLabel = 'document';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return Access::allows('review-documents');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $count = static::getEloquentQuery()->where('status', DocumentStatus::Received)->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Documents to review';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'booking']))
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->weight('medium')
                    ->url(fn (CustomerDocument $record): ?string => $record->customer && ! $record->customer->trashed()
                        ? CustomerResource::getUrl('edit', ['record' => $record->customer_id]) : null),
                Tables\Columns\TextColumn::make('type')
                    ->formatStateUsing(fn (?string $state): string => \App\Support\Options::documentLabel($state))
                    ->badge()
                    ->color('gray')
                    ->description(fn (CustomerDocument $record): ?string => $record->original_name),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->description(fn (CustomerDocument $record): ?string => $record->review_note),
                Tables\Columns\TextColumn::make('expires_on')
                    ->label('Expires')
                    ->date('d M Y')
                    ->sortable()
                    ->placeholder('-')
                    ->color(fn (CustomerDocument $record): ?string => $record->expires_on?->isPast() ? 'danger' : null),
                Tables\Columns\TextColumn::make('booking.reference')
                    ->label('Booking')
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('uploaded_via')
                    ->label('From')
                    ->formatStateUsing(fn (string $state): string => match ($state) { 'link' => 'Customer link', 'account' => 'Customer account', default => 'Staff' })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->since()
                    ->sortable()
                    ->dateTimeTooltip('d M Y, H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(fn (): array => \App\Support\Options::documentTypes()),
                Tables\Filters\SelectFilter::make('uploaded_via')
                    ->label('From')
                    ->options(['link' => 'Customer link', 'account' => 'Customer account', 'staff' => 'Staff']),
            ])
            ->actions(DocumentActions::reviewActions())
            ->emptyStateIcon('heroicon-o-identification')
            ->emptyStateHeading('No documents here')
            ->emptyStateDescription('Documents arrive from customer upload links or are uploaded on the customer page.');
    }

    public static function getEloquentQuery(): Builder
    {
        return \App\Support\Access::scopeOwn(parent::getEloquentQuery(), 'document');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
        ];
    }
}
