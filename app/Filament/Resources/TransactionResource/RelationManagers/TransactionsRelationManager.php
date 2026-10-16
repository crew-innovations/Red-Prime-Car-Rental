<?php

namespace App\Filament\Resources\TransactionResource\RelationManagers;

use App\Filament\Resources\TransactionResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Transaction;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * "Accounts" tab on cars, yachts, trips and customers: the money in and out
 * for that record. The link back to the record is filled in automatically.
 */
class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Accounts';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->transactions()->count();

        return $count ? (string) $count : null;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return $ownerRecord instanceof Booking ? 'Payments' : 'Accounts';
    }

    protected function isCustomer(): bool
    {
        return $this->getOwnerRecord() instanceof Customer;
    }

    protected function isBooking(): bool
    {
        return $this->getOwnerRecord() instanceof Booking;
    }

    public function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema(TransactionResource::formSchema(
                withRelated: ! $this->isCustomer() && ! $this->isBooking(),
                withCustomer: ! $this->isCustomer() && ! $this->isBooking(),
            ));
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(function (): string {
                $t = Transaction::totals($this->getOwnerRecord()->transactions()->getQuery());

                if ($this->isBooking()) {
                    $b = $this->getOwnerRecord()->fresh() ?? $this->getOwnerRecord();

                    return 'Received AED '.number_format((float) $b->paid_amount).' · balance AED '.number_format((float) $b->balance).' · deposit held AED '.number_format($b->depositHeld());
                }

                return $this->isCustomer()
                    ? 'Total paid AED '.number_format($t['received']).' · still to collect AED '.number_format($t['outstanding'])
                    : 'Revenue AED '.number_format($t['received']).' · expenses AED '.number_format($t['expenses']).' · net AED '.number_format($t['net']);
            })
            ->columns(TransactionResource::tableColumns(withRelated: $this->isCustomer()))
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'related']))
            ->defaultSort('date', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label($this->isCustomer() || $this->isBooking() ? 'Record payment' : 'Add income or expense')
                    ->icon('heroicon-o-plus')
                    ->modalWidth('4xl'),
            ])
            ->actions([
                TransactionResource::markPaidAction(),
                Tables\Actions\EditAction::make()->iconButton()->modalWidth('4xl'),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ])
            ->emptyStateHeading($this->isCustomer() ? 'No payments yet' : 'No income or expenses yet')
            ->emptyStateDescription($this->isCustomer()
                ? 'Payments from this customer will appear here.'
                : 'Record rental income and costs like fuel, Salik and repairs to see this item\'s profit.');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
