<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Widgets\Widget;

/** Cars / yachts going out and coming back today (Schedule A10). */
class TodayOperations extends Widget
{
    protected static string $view = 'filament.widgets.today-operations';

    protected static ?int $sort = 5;

    protected static ?string $pollingInterval = '60s';

    protected function getViewData(): array
    {
        $map = fn (Booking $b, bool $out) => [
            'time' => ($out ? $b->start_at : $b->end_at)->format('H:i'),
            'late' => ! $out && $b->end_at->isPast(),
            'lateDay' => ! $out && $b->end_at->isBefore(today()) ? $b->end_at->format('d M') : null,
            'title' => $b->bookable?->name ?? $b->service_type?->getLabel(),
            'sub' => collect([$b->customer?->name, $b->drive_mode?->getLabel(), $b->pickup_location])->filter()->implode(' · '),
            'balance' => (float) $b->balance,
            'url' => BookingResource::getUrl('edit', ['record' => $b]),
        ];

        $out = Booking::with(['bookable', 'customer'])
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::PendingPayment])
            ->whereDate('start_at', today())
            ->orderBy('start_at')
            ->limit(8)
            ->get()
            ->map(fn (Booking $b) => $map($b, true));

        $back = Booking::with(['bookable', 'customer'])
            ->where('status', BookingStatus::InProgress)
            ->where('end_at', '<=', now()->endOfDay())
            ->orderBy('end_at')
            ->limit(8)
            ->get()
            ->map(fn (Booking $b) => $map($b, false));

        return [
            'out' => $out,
            'back' => $back,
            'allUrl' => BookingResource::getUrl('index', ['activeTab' => 'today']),
        ];
    }
}
