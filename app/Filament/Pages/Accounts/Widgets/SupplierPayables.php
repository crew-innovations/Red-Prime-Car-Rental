<?php

namespace App\Filament\Pages\Accounts\Widgets;

use App\Filament\Resources\SupplierResource;
use App\Models\Supplier;
use App\Support\Suppliers\SupplierLedger;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** Accounts › To pay: what RED PRIME owes each supplier (29 Oct, 17). */
class SupplierPayables extends BaseWidget
{
    protected static ?int $sort = 6;

    protected static ?string $heading = 'To pay suppliers';

    public static function canView(): bool
    {
        return \App\Support\Access::allows('manage-finance') && Supplier::query()->exists();
    }

    public function table(Table $table): Table
    {
        $owed = Supplier::query()->get()->mapWithKeys(fn (Supplier $s) => [$s->id => SupplierLedger::balance($s)])->filter(fn ($b) => abs($b['balance']) > 0.004 || $b['waiting_approval'] > 0);

        return $table
            ->query(Supplier::query()->whereIn('id', $owed->keys()->all() ?: [0]))
            ->description('Opening balance + approved bills − payments · total AED '.number_format($owed->sum('balance'), 2))
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Supplier')->weight('semibold')
                    ->description(fn (Supplier $s): ?string => $s->payment_terms),
                Tables\Columns\TextColumn::make('bills')->label('Open bills')
                    ->state(fn (Supplier $s): int => SupplierLedger::openBills($s)->count()),
                Tables\Columns\TextColumn::make('waiting')->label('Waiting approval')
                    ->state(fn (Supplier $s): float => $owed[$s->id]['waiting_approval'] ?? 0)->money('AED')
                    ->color(fn ($state): ?string => $state > 0 ? 'warning' : null),
                Tables\Columns\TextColumn::make('owe')->label('We owe')->alignEnd()->weight('bold')
                    ->state(fn (Supplier $s): float => $owed[$s->id]['balance'] ?? 0)->money('AED'),
            ])
            ->recordUrl(fn (Supplier $s): string => SupplierResource::getUrl('edit', ['record' => $s]))
            ->emptyStateHeading('Nothing owed to suppliers');
    }
}
