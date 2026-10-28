<?php

namespace App\Filament\Pages\Accounts\Widgets;

use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Filament\Resources\TransactionResource;
use App\Models\Transaction;
use App\Support\Phone;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** Money still to collect, oldest due first. */
class OutstandingPayments extends BaseWidget
{
    protected static ?int $sort = 5;

    protected static ?string $heading = 'Payments to collect';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Transaction::query()
                    ->where('type', TransactionType::Income)
                    ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Overdue, PaymentStatus::PartiallyPaid])
                    ->counting()
                    ->with(['customer', 'related'])
            )
            ->description('Pending, overdue and partly paid')
            ->defaultSort('due_date')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Customer')
                    ->weight('semibold')
                    ->placeholder('-')
                    ->description(fn (Transaction $record): string => $record->description ?: $record->categoryLabel()),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due')
                    ->date('d M')
                    ->placeholder('-')
                    ->color(fn (Transaction $record): ?string => $record->due_date?->isPast() ? 'danger' : null),
                Tables\Columns\TextColumn::make('amount')
                    ->label('To collect')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn (Transaction $record): string => 'AED '.number_format((float) $record->amount - (float) ($record->status === PaymentStatus::PartiallyPaid ? $record->amount_paid : 0))),
            ])
            ->actions([
                Tables\Actions\Action::make('whatsapp')
                    ->label('')
                    ->tooltip('Send a reminder on WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->url(fn (Transaction $record): ?string => Phone::whatsappUrl(
                        $record->customer?->whatsapp ?: $record->customer?->phone,
                        \App\Support\Options::message('msg_payment_reminder', ['name' => $record->customer?->name, 'amount' => number_format((float) $record->amount)])
                    ))
                    ->openUrlInNewTab()
                    ->visible(fn (Transaction $record): bool => filled($record->customer?->whatsapp ?: $record->customer?->phone)),
                TransactionResource::markPaidAction(),
            ])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('Nothing to collect')
            ->emptyStateDescription('All income is fully paid.');
    }
}
