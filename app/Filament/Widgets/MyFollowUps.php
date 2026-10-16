<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Support\Phone;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** Open leads due for follow-up today or earlier, for the logged-in salesperson. */
class MyFollowUps extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'My follow-ups';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Lead::query()
                    ->open()
                    ->where('assigned_to', auth()->id())
                    ->whereNotNull('next_follow_up_at')
                    ->where('next_follow_up_at', '<=', today()->endOfDay())
                    ->with(['leadSource', 'interestedIn'])
            )
            ->defaultSort('next_follow_up_at')
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight('semibold')
                    ->description(fn (Lead $record): ?string => $record->phone),
                Tables\Columns\TextColumn::make('service_type')
                    ->label('Interest')
                    ->badge()
                    ->description(fn (Lead $record): ?string => $record->interestedIn?->name),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('next_follow_up_at')
                    ->label('Due')
                    ->since()
                    ->color(fn (Lead $record): string => $record->next_follow_up_at->isPast() ? 'danger' : 'warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('whatsapp')
                    ->label('')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->url(fn (Lead $record): ?string => Phone::whatsappUrl($record->whatsapp ?: $record->phone))
                    ->openUrlInNewTab()
                    ->visible(fn (Lead $record): bool => filled($record->whatsapp ?: $record->phone)),
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->url(fn (Lead $record): string => LeadResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('No follow-ups due')
            ->emptyStateDescription('You are all caught up.');
    }
}
