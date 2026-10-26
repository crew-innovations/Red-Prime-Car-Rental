<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use App\Filament\Resources\BookingResource;
use App\Models\BookingJob;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Deliveries and collections of this driver, with who assigned them and when (read only). */
class JobsRelationManager extends RelationManager
{
    protected static string $relationship = 'jobs';

    protected static ?string $title = 'Jobs';

    protected static ?string $icon = 'heroicon-o-truck';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['booking.bookable', 'booking.customer', 'assigner']))
            ->defaultSort('scheduled_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('scheduled_at')->label('When')->dateTime('D d M, H:i')->sortable(),
                Tables\Columns\TextColumn::make('type')->state(fn (BookingJob $record): string => $record->typeLabel())->badge()->color('gray'),
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')
                    ->description(fn (BookingJob $record): string => trim(($record->booking?->bookable?->name ?? '').' · '.($record->booking?->customer?->name ?? ''), ' ·')),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->state(fn (BookingJob $record): string => $record->statusLabel())
                    ->color(fn (BookingJob $record): string => $record->statusColor()),
                Tables\Columns\TextColumn::make('assigned_at')->label('Assigned')
                    ->since()
                    ->description(fn (BookingJob $record): ?string => $record->assigner?->name)
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(BookingJob::STATUSES),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->iconButton()
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->tooltip('Open booking')
                    ->url(fn (BookingJob $record): ?string => $record->booking ? BookingResource::getUrl('edit', ['record' => $record->booking]) : null),
            ])
            ->emptyStateHeading('No jobs yet');
    }
}
