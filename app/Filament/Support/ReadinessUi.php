<?php

namespace App\Filament\Support;

use App\Enums\Readiness;
use App\Models\Car;
use App\Models\Yacht;
use App\Support\BookingFlow;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;

/** "Ready / needs cleaning / needs inspection" for cars and yachts after a return. */
class ReadinessUi
{
    public static function column(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('readiness')
            ->label('Ready?')
            ->badge()
            ->tooltip(fn ($record): ?string => $record->readiness_note)
            ->sortable()
            ->toggleable();
    }

    public static function filter(): Tables\Filters\SelectFilter
    {
        return Tables\Filters\SelectFilter::make('readiness')
            ->label('Ready?')
            ->options(Readiness::class);
    }

    /** Cleaning / inspection done: the car or yacht can be handed over again. */
    public static function markReady(string $class)
    {
        return $class::make('markReady')
            ->label('Mark ready')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->authorize('operate-fleet')
            ->visible(fn (Car|Yacht $record): bool => $record->readiness !== Readiness::Ready)
            ->modalHeading(fn (Car|Yacht $record): string => 'Mark '.$record->name.' ready')
            ->modalDescription(fn (Car|Yacht $record): string => trim(($record->readiness?->getLabel() ?? '').($record->readiness_note ? ': '.$record->readiness_note : '')).'. Once ready it can be handed over again.')
            ->modalWidth('md')
            ->form([
                Forms\Components\TextInput::make('note')
                    ->label('What was done')
                    ->placeholder('e.g. Cleaned, inspected, no damage')
                    ->maxLength(255),
            ])
            ->action(function (Car|Yacht $record, array $data) {
                BookingFlow::markReady($record, $data['note'] ?: 'Marked ready');
                Notification::make()->title($record->name.' is ready')->success()->send();
            });
    }
}
