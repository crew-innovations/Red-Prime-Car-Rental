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
            Actions\Action::make('newContract')
                ->label('New contract')
                ->icon('heroicon-o-plus')
                ->authorize('manage-contracts')
                ->modalDescription('Contracts are made from a car booking. Pick the booking; make a new booking first if there is none.')
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
