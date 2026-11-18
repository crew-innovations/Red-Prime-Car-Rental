<?php

namespace App\Filament\Resources;

use App\Enums\PaymentMethod;
use App\Filament\Resources\TollAccountResource\Pages;
use App\Models\TollAccount;
use App\Models\TollTrip;
use App\Support\Access;
use App\Support\Tolls\Tolls;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Fines & tolls › Toll accounts (4 Nov, 3): Salik / Darb balance, top-ups (a transfer, not a cost), low-balance alert. */
class TollAccountResource extends Resource
{
    protected static ?string $model = TollAccount::class;

    protected static ?string $slug = 'toll-accounts';

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Fines & tolls';

    protected static ?string $navigationLabel = 'Toll accounts';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-fines');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->columns(2)->schema([
            Forms\Components\Select::make('toll_type')->label('Type')->options(TollTrip::TYPES)->required()->native(false),
            Forms\Components\TextInput::make('name')->required()->maxLength(120)->placeholder('Salik company account'),
            Forms\Components\TextInput::make('account_no')->label('Account number')->maxLength(60),
            Forms\Components\TextInput::make('low_balance')->label('Warn when the balance is below')->numeric()->minValue(0)->prefix('AED'),
            Forms\Components\TextInput::make('balance')->label('Balance now')->numeric()->prefix('AED')->helperText('Only when you add the account; after that it changes with top-ups and statements.')
                ->visibleOn('create'),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->description(fn (TollAccount $r) => (TollTrip::TYPES[$r->toll_type] ?? $r->toll_type).($r->account_no ? ' · '.$r->account_no : '')),
                Tables\Columns\TextColumn::make('balance')->money('AED')->color(fn (TollAccount $r) => $r->isLow() ? 'danger' : null)
                    ->description(fn (TollAccount $r) => $r->isLow() ? 'Low: top up' : ($r->low_balance !== null ? 'Alert below AED '.number_format((float) $r->low_balance, 2) : null)),
                Tables\Columns\TextColumn::make('movements_max_date')->label('Last movement')->max('movements', 'date')->date('d M Y')->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('topup')->label('Top up')->icon('heroicon-o-plus-circle')
                    ->visible(fn () => Access::allows('manage-finance'))
                    ->modalDescription('Money moved to the toll account. It is not a cost: the trips are.')
                    ->form([
                        Forms\Components\TextInput::make('amount')->numeric()->minValue(1)->prefix('AED')->required(),
                        Forms\Components\Select::make('method')->options(PaymentMethod::class)->required()->native(false),
                        Forms\Components\DatePicker::make('date')->default(today())->required(),
                        Forms\Components\TextInput::make('note')->maxLength(200),
                    ])
                    ->action(function (TollAccount $r, array $data) {
                        Tolls::topUp($r, (float) $data['amount'], $data['method'] instanceof \BackedEnum ? $data['method']->value : $data['method'], $data['note'] ?? null, $data['date']);
                        Notification::make()->title('Top-up recorded')->success()->send();
                    }),
                Tables\Actions\Action::make('usage')->label('Statement usage')->icon('heroicon-o-minus-circle')->color('gray')
                    ->modalDescription('Usage from a statement that was not imported as trips. Imports lower the balance by themselves.')
                    ->form([
                        Forms\Components\TextInput::make('amount')->numeric()->minValue(0.5)->prefix('AED')->required(),
                        Forms\Components\TextInput::make('note')->required()->maxLength(200),
                    ])
                    ->action(function (TollAccount $r, array $data) {
                        Tolls::usage($r, (float) $data['amount'], $data['note']);
                        Notification::make()->title('Saved')->success()->send();
                    }),
                Tables\Actions\Action::make('history')->label('Movements')->icon('heroicon-o-list-bullet')->color('gray')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (TollAccount $r) => new \Illuminate\Support\HtmlString(
                        '<table class="rp-simple-table"><thead><tr><th>Date</th><th>What</th><th>Amount</th><th>Balance</th></tr></thead><tbody>'
                        .$r->movements()->limit(50)->get()->map(fn ($m) => '<tr><td>'.e($m->date->format('d M Y')).'</td><td>'.e(ucfirst($m->kind).($m->note ? ' · '.$m->note : '')).'</td><td>'.e(number_format((float) $m->amount, 2)).'</td><td>'.e(number_format((float) $m->balance_after, 2)).'</td></tr>')->implode('')
                        .'</tbody></table>')),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTollAccounts::route('/')];
    }
}
