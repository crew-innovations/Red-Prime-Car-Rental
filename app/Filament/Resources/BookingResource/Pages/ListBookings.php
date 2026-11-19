<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListBookings extends ListRecords
{
    use \App\Filament\Concerns\SimpleTabs; // 6 Nov (S4): main tabs + "More views"

    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Support\Exports\TableExport::action(),
            Actions\CreateAction::make()->label('New booking')->icon('heroicon-o-plus'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return BookingResource::getWidgets();
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'today';
    }

    public function getTabs(): array
    {
        $today = fn (Builder $query) => $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->whereIn('status', [BookingStatus::Confirmed, BookingStatus::PendingPayment])->whereDate('start_at', today()))
            ->orWhere(fn (Builder $q) => $q->where('status', BookingStatus::InProgress)->where('end_at', '<=', now()->endOfDay())));

        $own = fn () => \App\Support\Access::scopeOwn(Booking::query(), 'booking');

        return $this->simpleTabs([
            // 19 Nov (item 7): a booking just made always shows here, whatever its dates or status.
            'new' => Tab::make('Just added')
                ->icon('heroicon-o-sparkles')
                ->badge(fn () => $own()->where('created_at', '>=', today())->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7))->reorder('created_at', 'desc')),
            'upcoming' => Tab::make('Upcoming')
                ->icon('heroicon-o-calendar')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [BookingStatus::Confirmed, BookingStatus::PendingPayment])->where('end_at', '>=', now()->startOfDay())),
            'today' => Tab::make('Today')
                ->icon('heroicon-o-clock')
                ->badge(fn () => $today($own())->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $today($query)),
            'out' => Tab::make('With customer')
                ->icon('heroicon-o-key')
                ->badge(fn () => $own()->where('status', BookingStatus::InProgress)->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingStatus::InProgress)->orderBy('end_at')),
            'late' => Tab::make('Late returns')
                ->icon('heroicon-o-exclamation-triangle')
                ->badge(fn () => $own()->where('status', BookingStatus::InProgress)->where('end_at', '<', now())->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingStatus::InProgress)->where('end_at', '<', now())->reorder('end_at')),
            'waiting' => Tab::make('Waiting for payment')
                ->badge(fn () => $own()->where('status', BookingStatus::PendingPayment)->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingStatus::PendingPayment)->reorder('start_at')),
            'enquiries' => Tab::make('Enquiries & quotes')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [BookingStatus::Inquiry, BookingStatus::Quotation])),
            'balance' => Tab::make('Balance due')
                ->icon('heroicon-o-banknotes')
                ->badge(fn () => $own()->where('balance', '>', 0)->whereIn('status', [BookingStatus::InProgress, BookingStatus::Completed])->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('balance', '>', 0)->whereIn('status', BookingStatus::billable())),
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingStatus::Completed)->reorder('end_at', 'desc')),
            // 4 Nov (Phase 4): completed with a deposit still held and the hold period over.
            ...(\App\Support\Access::allows('manage-finance') ? ['deposits' => Tab::make('Deposits to settle')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('bookings.id', \App\Support\Deposits\Settlement::dueQuery()->select('bookings.id')))] : []),
            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [BookingStatus::Cancelled, BookingStatus::NoShow])),
            'all' => Tab::make('All'),
        ], ['today', 'new', 'upcoming', 'out', 'waiting', 'all'], ['late']);
    }
}
