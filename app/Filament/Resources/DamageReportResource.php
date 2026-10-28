<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DamageReportResource\Pages;
use App\Models\DamageReport;
use App\Support\Access;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Damage and issues reported by drivers. Staff look at the photos and decide;
 * any charge is added on the booking (return charges or a payment), not here.
 */
class DamageReportResource extends Resource
{
    protected static ?string $model = DamageReport::class;

    protected static ?string $slug = 'damage-reports';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Damage reports';

    protected static ?string $modelLabel = 'damage report';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return Access::allows('review-damage');
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

    public static function getNavigationBadge(): ?string
    {
        $open = DamageReport::where('status', 'open')->count();

        return $open ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['booking.bookable', 'booking.customer', 'reporter', 'reviewer', 'media']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Reported')->since()->sortable()
                    ->description(fn (DamageReport $record): ?string => $record->reporter?->name),
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (DamageReport $record): string => trim(($record->booking?->bookable?->name ?? '').' · '.($record->booking?->customer?->name ?? ''), ' ·'))
                    ->url(fn (DamageReport $record): ?string => $record->booking ? BookingResource::getUrl('edit', ['record' => $record->booking]) : null),
                Tables\Columns\TextColumn::make('description')->wrap()->limit(160),
                Tables\Columns\TextColumn::make('photos')
                    ->state(fn (DamageReport $record): int => $record->getMedia('photos')->count() + ($record->booking_job_id && $record->stage === 'collection' ? $record->booking?->getMedia('return')->count() ?? 0 : 0))
                    ->label('Photos')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => DamageReport::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => $state === 'open' ? 'danger' : 'success')
                    ->description(fn (DamageReport $record): ?string => $record->reviewer ? $record->reviewer->name.' · '.$record->reviewed_at?->format('d M H:i') : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(DamageReport::STATUSES)->default('open'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->iconButton()->icon('heroicon-o-photo')->tooltip('See photos')
                    ->modalHeading(fn (DamageReport $record): string => 'Damage · '.$record->booking?->reference)
                    ->modalSubmitAction(false)
                    ->modalContent(fn (DamageReport $record): HtmlString => self::photoGrid($record)),
                Tables\Actions\Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-o-check-badge')
                    ->color('primary')
                    ->authorize('review-damage')
                    ->visible(fn (DamageReport $record): bool => $record->status === 'open')
                    ->form([
                        Forms\Components\ToggleButtons::make('status')
                            ->label('Outcome')
                            ->options(['no_charge' => 'No charge', 'charged' => 'Charge added on the booking'])
                            ->colors(['no_charge' => 'gray', 'charged' => 'warning'])
                            ->inline()->required(),
                        Forms\Components\TextInput::make('charge_amount')
                            ->label('Charge to the customer')
                            ->numeric()->minValue(1)->prefix('AED')
                            ->required(fn (Forms\Get $get): bool => $get('status') === 'charged')
                            ->visible(fn (Forms\Get $get): bool => $get('status') === 'charged'),
                        Forms\Components\Textarea::make('review_note')->label('Note')->rows(2),
                        Forms\Components\Placeholder::make('how')->hiddenLabel()
                            ->content('An approved charge is filled in as "Damage" when the car is received back. If the return is already completed, it is added to the booking now and shows in To collect.'),
                    ])
                    ->action(function (DamageReport $record, array $data): void {
                        $record->update([
                            'status' => $data['status'],
                            'charge_amount' => $data['status'] === 'charged' ? round((float) $data['charge_amount'], 2) : null,
                            'review_note' => $data['review_note'] ?? null,
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);
                        $record->refresh()->applyChargeIfReturned();
                        Notification::make()->title('Review saved')
                            ->body($record->applied_at ? 'AED '.number_format((float) $record->charge_amount, 2).' added to '.$record->booking?->reference.'.' : null)
                            ->success()->send();
                    }),
            ])
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading('Nothing to review')
            ->emptyStateDescription('Damage reported by drivers appears here.');
    }

    protected static function photoGrid(DamageReport $record): HtmlString
    {
        $media = $record->getMedia('photos');
        if ($record->stage === 'collection' && $record->booking) {
            $media = $media->concat($record->booking->getMedia('return'));
        }
        $html = '<p style="margin:0 0 .75rem;white-space:pre-line">'.e($record->description).'</p><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.5rem">';
        foreach ($media as $m) {
            $url = e($m->getTemporaryUrl(now()->addMinutes(10)));
            $html .= '<a href="'.$url.'" target="_blank" rel="noopener"><img src="'.$url.'" alt="" style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:.5rem"></a>';
        }

        return new HtmlString($html.($media->isEmpty() ? '<span style="opacity:.7">No photos.</span>' : '').'</div>');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDamageReports::route('/'),
        ];
    }
}
