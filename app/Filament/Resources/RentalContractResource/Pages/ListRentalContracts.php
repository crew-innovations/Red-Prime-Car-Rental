<?php

namespace App\Filament\Resources\RentalContractResource\Pages;

use App\Enums\BookingStatus;
use App\Filament\Resources\RentalContractResource;
use App\Models\Booking;
use App\Models\RentalContract;
use App\Support\Contracts\Contracts;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ListRentalContracts extends ListRecords
{
    use \App\Filament\Concerns\SimpleTabs;

    protected static string $resource = RentalContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 19 Nov (suggestion 6): paper contracts from before the CRM.
            Actions\Action::make('oldContract')
                ->label('Enter old contract')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('gray')
                ->authorize('change-booking-status')
                ->modalWidth('3xl')
                ->modalDescription('A paper contract from before the CRM. It is saved as a completed rental and a closed contract, with the scan attached. It does not hold the car.')
                ->form([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('customer_id')->label('Customer')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => \App\Models\Customer::query()->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->limit(30)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => \App\Models\Customer::find($value)?->name)
                            ->tap(fn (Forms\Components\Select $select) => \App\Filament\Concerns\CustomerDetails::picker($select)), // 20 Nov: same customer form
                        Forms\Components\Select::make('car_id')->label('Car')->required()->searchable()
                            ->options(fn () => \App\Models\Car::withTrashed()->orderBy('name')->get()->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->registration_number ? ' · '.$c->registration_number : '')])->all()),
                        Forms\Components\TextInput::make('paper_number')->label('Number on the paper contract')->required()->maxLength(60),
                        Forms\Components\Select::make('drive_mode')->label('Rental type')->options(['self_drive' => 'Self-drive', 'with_driver' => 'With driver'])->default('self_drive')->native(false)->selectablePlaceholder(false),
                        Forms\Components\DateTimePicker::make('start_at')->label('Pick-up')->native(false)->seconds(false)->required()->maxDate(now()),
                        Forms\Components\DateTimePicker::make('end_at')->label('Return')->native(false)->seconds(false)->required()->maxDate(now()),
                        Forms\Components\TextInput::make('km_out')->label('Odometer out')->numeric()->suffix('km'),
                        Forms\Components\TextInput::make('km_in')->label('Odometer in')->numeric()->suffix('km'),
                        Forms\Components\TextInput::make('total')->label('Total on the contract')->numeric()->minValue(0)->prefix('AED')->required(),
                        Forms\Components\TextInput::make('paid')->label('Paid')->numeric()->minValue(0)->prefix('AED'),
                        Forms\Components\Select::make('method')->label('Paid by')->options(\App\Enums\PaymentMethod::class)->native(false),
                    ]),
                    Forms\Components\FileUpload::make('scan')->label('Scan / photo of the paper contract')->disk('private')->directory('contract-files')->visibility('private')
                        ->acceptedFileTypes(['image/*', 'application/pdf'])->multiple()->maxFiles(10)->maxSize(10240),
                    Forms\Components\Textarea::make('notes')->rows(2)->maxLength(1000),
                ])
                ->action(function (array $data) {
                    try {
                        $c = Contracts::enterOld($data);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

                        return;
                    }
                    $this->redirect(RentalContractResource::getUrl('view', ['record' => $c]));
                }),
            Actions\Action::make('fromBooking')
                ->label('From a booking')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->authorize('manage-contracts')
                ->modalDescription('Make the contract for a car booking that already exists.')
                ->form([
                    Forms\Components\Select::make('booking_id')->label('Car booking')->required()->searchable()
                        ->options(fn () => RentalContractResource::bookingsWithoutContract())
                        ->helperText('Bookings waiting for payment, confirmed or with the customer, without a contract yet.'),
                ])
                ->action(function (array $data) {
                    try {
                        $c = Contracts::make(Booking::findOrFail($data['booking_id']));
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

                        return;
                    }
                    $this->redirect(RentalContractResource::getUrl('view', ['record' => $c]));
                }),
            // 19 Nov: a new contract on its own screen (customer, car, dates, price, drivers); the booking is made with it.
            Actions\CreateAction::make()->label('New contract')->icon('heroicon-o-plus'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }

    public function getTabs(): array
    {
        $own = fn () => RentalContractResource::getEloquentQuery()->withoutTrashed();
        $dueToday = fn (Builder $query) => $query->where('status', 'open')->whereHas('booking', fn ($b) => $b->where('end_at', '<=', now()->endOfDay()));

        return $this->simpleTabs([
            'open' => Tab::make('Open')->icon('heroicon-o-key')
                ->badge(fn () => $own()->where('status', 'open')->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'open')),
            'drafts' => Tab::make('Drafts / to sign')->icon('heroicon-o-pencil')
                ->badge(fn () => $own()->where('status', 'draft')->count() ?: null)->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft')),
            'due' => Tab::make('Due back today')->icon('heroicon-o-clock')
                ->badge(fn () => $dueToday($own())->count() ?: null)->badgeColor('danger')
                ->modifyQueryUsing($dueToday),
            'closed' => Tab::make('Closed')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'closed')),
            'company' => Tab::make('Company')->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'company')),
            'monthly' => Tab::make('Monthly')->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'monthly')),
            'cancelled' => Tab::make('Cancelled')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),
            'all' => Tab::make('All'),
        ], ['open', 'drafts', 'due', 'closed', 'all'], ['due']);
    }
}
