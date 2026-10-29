<?php

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Concerns\GuardsSave;
use App\Filament\Resources\SupplierResource;
use App\Models\Supplier;
use App\Support\Access;
use App\Support\Suppliers\SupplierLedger;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class EditSupplier extends EditRecord
{
    use GuardsSave;

    protected static string $resource = SupplierResource::class;

    protected static string $view = 'filament.resources.supplier.edit';

    public function balance(): array
    {
        return SupplierLedger::balance($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        /** @var Supplier $s */
        $s = $this->getRecord();
        $aed = fn ($v) => 'AED '.number_format((float) $v, 2);

        return [
            Actions\Action::make('pay')
                ->label('Record payment')
                ->icon('heroicon-o-banknotes')
                ->authorize('manage-finance')
                ->modalWidth('2xl')
                ->modalDescription(fn (): string => 'Owed now: '.$aed(SupplierLedger::balance($s)['balance']).'. A payment settles bills; it is not a second expense.')
                ->form(function () use ($s, $aed): array {
                    $lines = ['opening' => 'Opening balance · '.$aed(SupplierLedger::openingOutstanding($s)).' owed'];
                    if (SupplierLedger::openingOutstanding($s) <= 0) {
                        unset($lines['opening']);
                    }
                    foreach (SupplierLedger::openBills($s) as $b) {
                        $lines[$b->id] = $b->date->format('d M Y').' · '.($b->booking?->reference ?? '#'.$b->id).' · '.$aed($b->outstanding).' owed';
                    }

                    return [
                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\TextInput::make('amount')->numeric()->prefix('AED')->minValue(0.01)->required()->live(onBlur: true)
                                ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('lines', collect(SupplierLedger::suggest($s, (float) $state))->map(fn ($v, $k) => ['line' => (string) $k, 'amount' => $v])->values()->all())),
                            Forms\Components\DatePicker::make('date')->default(today())->maxDate(today())->required(),
                            Forms\Components\Select::make('method')->options(PaymentMethod::class)->default(PaymentMethod::BankTransfer->value)->native(false),
                        ]),
                        Forms\Components\TextInput::make('reference')->placeholder('Bank reference / cheque no.')->maxLength(120),
                        Forms\Components\Repeater::make('lines')->label('Against')->addActionLabel('Add a bill')->defaultItems(0)->columns(3)->minItems(1)
                            ->helperText('Filled oldest first when you type the amount. Change it if needed.')
                            ->schema([
                                Forms\Components\Select::make('line')->hiddenLabel()->options($lines)->required()->columnSpan(2)->distinct(),
                                Forms\Components\TextInput::make('amount')->hiddenLabel()->numeric()->prefix('AED')->minValue(0.01)->required(),
                            ]),
                        Forms\Components\Textarea::make('notes')->rows(2),
                    ];
                })
                ->action(function (array $data) use ($s) {
                    $alloc = [];
                    foreach ((array) ($data['lines'] ?? []) as $l) {
                        $alloc[$l['line']] = round(($alloc[$l['line']] ?? 0) + (float) $l['amount'], 2);
                    }
                    try {
                        $p = SupplierLedger::pay($s, (float) $data['amount'], (string) $data['date'], $alloc, $data['method'] instanceof \BackedEnum ? $data['method']->value : ($data['method'] ?? null), $data['reference'] ?? null, $data['notes'] ?? null);
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->persistent()->send();

                        return;
                    }
                    Notification::make()->title('Payment '.$p->number.' recorded')->success()->send();
                    $this->redirect(SupplierResource::getUrl('edit', ['record' => $s]));
                }),
            Actions\Action::make('bill')
                ->label('Add bill')
                ->icon('heroicon-o-document-plus')
                ->color('gray')
                ->authorize('manage-finance')
                ->modalDescription('For a supplier invoice not made by the CRM (e.g. a monthly rental). Bills from completed bookings are added automatically.')
                ->form([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('amount')->numeric()->prefix('AED')->minValue(0.01)->required(),
                        Forms\Components\DatePicker::make('date')->default(today())->required(),
                        Forms\Components\DatePicker::make('due_date')->label('Due'),
                        Forms\Components\TextInput::make('reference')->label('Supplier invoice no.')->maxLength(120),
                    ]),
                    Forms\Components\TextInput::make('description')->required()->maxLength(150)->placeholder('November monthly rental'),
                    Forms\Components\Select::make('booking_id')->label('Booking (optional)')
                        ->options(fn () => \App\Models\Booking::query()->where('supplier_id', $s->id)->whereNull('supplier_bill_id')->latest('start_at')->limit(100)->pluck('reference', 'id')->all())
                        ->searchable(),
                ])
                ->action(function (array $data) use ($s) {
                    try {
                        SupplierLedger::addBill($s, (float) $data['amount'], (string) $data['date'], (string) $data['description'], $data['booking_id'] ? (int) $data['booking_id'] : null, $data['due_date'] ?? null, $data['reference'] ?? null);
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                        return;
                    }
                    Notification::make()->title('Bill added')->success()->send();
                    $this->redirect(SupplierResource::getUrl('edit', ['record' => $s]));
                }),
            Actions\Action::make('statement')
                ->label('Statement')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->authorize('manage-finance')
                ->modalWidth('4xl')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.resources.supplier.statement', ['s' => $s, 'st' => SupplierLedger::statement($s)])),
        ];
    }
}
