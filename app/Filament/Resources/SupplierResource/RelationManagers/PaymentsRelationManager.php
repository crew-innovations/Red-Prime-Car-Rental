<?php

namespace App\Filament\Resources\SupplierResource\RelationManagers;

use App\Models\SupplierPayment;
use App\Support\Access;
use App\Support\Suppliers\SupplierLedger;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::allows('manage-finance');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['allocations.bill.booking', 'creator']))
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->weight('semibold')->description(fn (SupplierPayment $p): ?string => $p->reference),
                Tables\Columns\TextColumn::make('date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('amount')->money('AED'),
                Tables\Columns\TextColumn::make('method')->formatStateUsing(fn (?string $state): string => \App\Enums\PaymentMethod::tryFrom((string) $state)?->getLabel() ?? (string) $state)->placeholder('—'),
                Tables\Columns\TextColumn::make('against')->label('Against')->wrap()
                    ->state(fn (SupplierPayment $p): string => $p->allocations->map(fn ($a) => ($a->transaction_id ? ($a->bill?->booking?->reference ?? 'Bill #'.$a->transaction_id) : 'Opening balance').' '.number_format((float) $a->amount, 2))->implode(' · ')),
                Tables\Columns\TextColumn::make('reversed_at')->label('Status')->badge()
                    ->formatStateUsing(fn ($state): string => 'Reversed')->placeholder('OK')->color('gray')
                    ->description(fn (SupplierPayment $p): ?string => $p->reverse_reason),
            ])
            ->actions([
                Tables\Actions\Action::make('reverse')->label('Reverse')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (SupplierPayment $p): bool => ! $p->reversed_at && Access::allows('manage-finance'))
                    ->modalDescription('Use this if the payment was recorded by mistake. The bills it settled become owed again.')
                    ->form([Forms\Components\TextInput::make('reason')->required()->maxLength(200)])
                    ->action(function (SupplierPayment $p, array $data) {
                        SupplierLedger::reverse($p, $data['reason']);
                        Notification::make()->title('Payment '.$p->number.' reversed')->success()->send();
                    }),
            ]);
    }
}
