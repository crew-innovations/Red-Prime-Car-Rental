<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use App\Models\BookingCharge;
use App\Support\Access;
use App\Support\Charges\ChargeLines;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** "Charges" tab on a booking (4 Nov, C.1): fines, tolls and fees added after the price. Voided, never deleted. */
class ChargeLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'chargeLines';

    protected static ?string $title = 'Fines, tolls & charges';

    protected static ?string $icon = 'heroicon-o-receipt-percent';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! Access::isDriverOnly() && (Access::allows('manage-fines') || Access::allows('manage-finance') || Access::allows('record-payments'));
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $n = $ownerRecord->chargeLines()->whereNull('voided_at')->count();

        return $n ? (string) $n : null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Added')->date('d M Y'),
                Tables\Columns\TextColumn::make('label')->wrap()->description(fn (BookingCharge $r) => $r->voided_at ? 'Voided: '.$r->void_reason : null),
                Tables\Columns\TextColumn::make('nature')->label('Counts as')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'pass_through' ? 'Passed on (not revenue)' : 'Revenue')
                    ->color(fn ($state) => $state === 'pass_through' ? 'gray' : 'success'),
                Tables\Columns\TextColumn::make('amount')->money('AED')
                    ->extraAttributes(fn (BookingCharge $r) => $r->voided_at ? ['style' => 'text-decoration: line-through; opacity: .6'] : []),
                Tables\Columns\TextColumn::make('invoice.number')->label('Invoice')->placeholder('Not invoiced'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('add')->label('Add charge')->icon('heroicon-o-plus')
                    ->visible(fn () => Access::allows('manage-finance'))
                    ->modalDescription('For a charge that is not a fine or a toll (those come from Fines & tolls). It needs a reason and is kept in the history.')
                    ->form([
                        Forms\Components\Select::make('type')->options(['damage' => 'Damage', 'other' => 'Other charge'])->default('other')->required()->native(false),
                        Forms\Components\TextInput::make('label')->label('What for')->required()->maxLength(200),
                        Forms\Components\TextInput::make('amount')->numeric()->minValue(1)->prefix('AED')->required(),
                    ])
                    ->action(function (array $data) {
                        ChargeLines::add($this->getOwnerRecord(), $data['type'], $data['label'], (float) $data['amount']);
                        Notification::make()->title('Charge added')->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('void')->label('Void')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (BookingCharge $r) => ! $r->voided_at && Access::allows('manage-finance'))
                    ->form([Forms\Components\TextInput::make('reason')->required()->maxLength(200)])
                    ->action(function (BookingCharge $r, array $data) {
                        try {
                            ChargeLines::void($r, $data['reason']);
                            Notification::make()->title('Charge voided')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
            ]);
    }
}
