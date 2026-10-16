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
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New booking')->icon('heroicon-o-plus'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return BookingResource::getWidgets();
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'upcoming';
    }

    public function getTabs(): array
    {
        $today = fn (Builder $query) => $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->whereIn('status', [BookingStatus::Confirmed, BookingStatus::PendingPayment])->whereDate('start_at', today()))
            ->orWhere(fn (Builder $q) => $q->where('status', BookingStatus::InProgress)->where('end_at', '<=', now()->endOfDay())));

        return [
            'upcoming' => Tab::make('Upcoming')
                ->icon('heroicon-o-calendar')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [BookingStatus::Confirmed, BookingStatus::PendingPayment])->where('end_at', '>=', now()->startOfDay())),
            'today' => Tab::make('Out / back today')
                ->icon('heroicon-o-clock')
                ->badge(fn () => $today(Booking::query())->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $today($query)),
            'out' => Tab::make('With customer')
                ->icon('heroicon-o-key')
                ->badge(fn () => Booking::where('status', BookingStatus::InProgress)->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingStatus::InProgress)->orderBy('end_at')),
            'enquiries' => Tab::make('Enquiries & quotes')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [BookingStatus::Inquiry, BookingStatus::Quotation])),
            'balance' => Tab::make('Balance due')
                ->icon('heroicon-o-banknotes')
                ->badge(fn () => Booking::where('balance', '>', 0)->whereIn('status', [BookingStatus::InProgress, BookingStatus::Completed])->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('balance', '>', 0)->whereIn('status', BookingStatus::billable())),
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingStatus::Completed)->reorder('end_at', 'desc')),
            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [BookingStatus::Cancelled, BookingStatus::NoShow])),
            'all' => Tab::make('All'),
        ];
    }
}
