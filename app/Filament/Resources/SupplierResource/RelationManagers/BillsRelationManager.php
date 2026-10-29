<?php

namespace App\Filament\Resources\SupplierResource\RelationManagers;

use App\Models\Transaction;
use App\Support\Access;
use App\Support\Suppliers\SupplierLedger;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The supplier's bills (expense rows). Reverse a wrong one in Accounts › Transactions. */
class BillsRelationManager extends RelationManager
{
    protected static string $relationship = 'bills';

    protected static ?string $title = 'Bills';

    protected static ?string $icon = 'heroicon-o-document-text';

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
            ->modifyQueryUsing(fn ($query) => $query->where('type', 'expense')->with('booking'))
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')->placeholder('—')
                    ->url(fn (Transaction $t): ?string => $t->booking_id ? \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $t->booking_id]) : null),
                Tables\Columns\TextColumn::make('description')->wrap()->limit(70)->description(fn (Transaction $t): ?string => $t->notes ? \Illuminate\Support\Str::limit($t->notes, 90) : null),
                Tables\Columns\TextColumn::make('amount')->money('AED'),
                Tables\Columns\TextColumn::make('owed')->label('Still owed')->state(fn (Transaction $t): float => $t->reversed_at ? 0 : SupplierLedger::outstanding($t))->money('AED'),
                Tables\Columns\TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (Transaction $t): string => match (true) {
                        (bool) $t->reversed_at => 'Reversed',
                        $t->approval_status === 'pending' => 'Waiting for approval',
                        $t->approval_status === 'rejected' => 'Rejected',
                        default => $t->status?->getLabel() ?? '',
                    })
                    ->color(fn (string $state): string => match ($state) { 'Paid' => 'success', 'Reversed', 'Rejected' => 'gray', 'Waiting for approval' => 'warning', default => 'danger' }),
            ])
            ->actions([
                Tables\Actions\Action::make('open')->label('Open')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Transaction $t): string => \App\Filament\Resources\TransactionResource::getUrl('edit', ['record' => $t])),
            ]);
    }
}
