<?php

namespace App\Filament\Resources\QuotationResource\RelationManagers;

use App\Enums\QuotationStatus;
use App\Filament\Resources\QuotationResource;
use App\Models\Customer;
use App\Models\Quotation;
use App\Support\Access;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** "Quotations" tab on customers and leads. */
class QuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';

    protected static ?string $title = 'Quotations';

    protected static ?string $icon = 'heroicon-o-document-text';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! Access::isDriverOnly();
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->quotations()->whereIn('status', [QuotationStatus::Sent, QuotationStatus::Negotiation, QuotationStatus::Accepted])->whereNull('booking_id')->count();

        return $count ? (string) $count : null;
    }

    public function isReadOnly(): bool
    {
        return ! Access::allows('manage-quotations');
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('number')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Quote')->weight('semibold'),
                Tables\Columns\TextColumn::make('bookable.name')->label('Car / yacht / trip')->placeholder('-')
                    ->description(fn (Quotation $record): ?string => $record->start_at?->format('d M').($record->end_at ? ' → '.$record->end_at->format('d M') : '')),
                Tables\Columns\TextColumn::make('total')->money('AED')->alignEnd(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')->placeholder('-'),
                Tables\Columns\TextColumn::make('valid_until')->label('Valid until')->date('d M Y'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['bookable', 'booking']))
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('new')
                    ->label('Create quotation')
                    ->icon('heroicon-o-plus')
                    ->authorize('manage-quotations')
                    ->url(fn (): string => QuotationResource::getUrl('create', $owner instanceof Customer ? ['customer' => $owner->id] : ['lead' => $owner->id])),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->iconButton()
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->tooltip('Open quotation')
                    ->url(fn (Quotation $record): string => QuotationResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading('No quotations yet');
    }
}
