<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\DepositPreauth;
use App\Support\Access;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * "Card holds" on a booking (4 Nov, 4.4): a deposit held on the customer's card by the POS or the
 * gateway. Only the reference and the last 4 digits: never the card number, expiry or CVC. A hold
 * is not money received until it is captured.
 */
class PreauthsRelationManager extends RelationManager
{
    protected static string $relationship = 'preauths';

    protected static ?string $title = 'Card holds';

    protected static ?string $icon = 'heroicon-o-credit-card';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! Access::isDriverOnly() && Access::allows('record-payments');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->columns(3)->schema([
            Forms\Components\TextInput::make('provider')->label('Through')->datalist(['POS terminal', 'Stripe', 'Paymob', 'PayPal'])->required()->maxLength(40),
            Forms\Components\TextInput::make('reference')->label('Authorisation reference')->required()->maxLength(80),
            Forms\Components\TextInput::make('card_last4')->label('Card: last 4 digits')->length(4)->regex('/^\d{4}$/')
                ->helperText('Only the last 4. Never type the full card number, expiry or CVC.'),
            Forms\Components\TextInput::make('amount')->numeric()->minValue(1)->prefix('AED')->required(),
            Forms\Components\DatePicker::make('held_on')->default(today())->required(),
            Forms\Components\DatePicker::make('expires_on')->label('Hold expires on')->helperText('Card holds usually end after 7–30 days.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('held_on')->date('d M Y'),
                Tables\Columns\TextColumn::make('provider')->description(fn (DepositPreauth $r) => $r->reference.($r->card_last4 ? ' · •••• '.$r->card_last4 : '')),
                Tables\Columns\TextColumn::make('amount')->money('AED'),
                Tables\Columns\TextColumn::make('expires_on')->date('d M Y')->placeholder('—')
                    ->color(fn (DepositPreauth $r) => $r->status === 'held' && $r->expires_on?->lte(today()->addDays(2)) ? 'danger' : null),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => DepositPreauth::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'held' => 'warning', 'captured' => 'success', default => 'gray' })
                    ->description(fn (DepositPreauth $r) => $r->captured_amount ? 'Captured AED '.number_format((float) $r->captured_amount, 2) : null),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Add card hold')
                    ->mutateFormDataUsing(fn (array $data) => $data + ['status' => 'held', 'created_by' => auth()->id()]),
            ])
            ->actions([
                Tables\Actions\Action::make('capture')->label('Capture')->icon('heroicon-o-banknotes')
                    ->visible(fn (DepositPreauth $r) => $r->status === 'held' && Access::allows('manage-finance'))
                    ->modalDescription('Take money from the hold (done in the POS / gateway first). It becomes deposit money on the booking.')
                    ->fillForm(fn (DepositPreauth $r) => ['amount' => $r->amount])
                    ->form(fn (DepositPreauth $r) => [Forms\Components\TextInput::make('amount')->numeric()->minValue(1)->maxValue((float) $r->amount)->prefix('AED')->required()])
                    ->action(function (DepositPreauth $r, array $data) {
                        $t = $r->booking->transactions()->create([
                            'type' => TransactionType::Income, 'category' => 'deposit', 'date' => today(), 'amount' => round((float) $data['amount'], 2),
                            'status' => PaymentStatus::Paid, 'method' => 'card', 'reference' => $r->reference, 'description' => 'Card hold captured · '.$r->booking->reference,
                        ]);
                        $r->update(['status' => 'captured', 'captured_amount' => $data['amount'], 'transaction_id' => $t->id]);
                        AuditLog::note($r->booking, 'deposit', 'Card hold '.$r->reference.' captured: AED '.number_format((float) $data['amount'], 2));
                        Notification::make()->title('Captured')->success()->send();
                    }),
                Tables\Actions\Action::make('release')->label('Released')->icon('heroicon-o-lock-open')->color('gray')
                    ->visible(fn (DepositPreauth $r) => $r->status === 'held')->requiresConfirmation()
                    ->modalDescription('The hold was released in the POS / gateway, so the customer gets the money back on the card.')
                    ->action(function (DepositPreauth $r) {
                        $r->update(['status' => 'released']);
                        AuditLog::note($r->booking, 'deposit', 'Card hold '.$r->reference.' released');
                    }),
            ]);
    }
}
