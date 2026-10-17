<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\Availability;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * When a car / yacht is put into maintenance or switched off while it still has
 * bookings coming up, staff are warned (and it is logged). Bookings are never
 * moved or cancelled automatically.
 */
trait WarnsAboutBookings
{
    public static function bootWarnsAboutBookings(): void
    {
        static::updated(function (Model $item) {
            if (! $item->wasChanged('status') || ! in_array($item->status?->value, ['maintenance', 'unavailable'], true)) {
                return;
            }

            $holds = Availability::upcomingHolds($item);
            if ($holds->isEmpty()) {
                return;
            }

            $list = $holds->take(5)->map(fn ($b) => $b->reference.' ('.$b->start_at->format('d M').'–'.$b->end_at->format('d M').')')->implode(', ');
            AuditLog::note($item, 'conflict', 'Marked '.$item->status->getLabel().' with bookings still ahead: '.$list);

            Notification::make()
                ->title($item->name.' has bookings coming up')
                ->body('It is now '.$item->status->getLabel().', but these bookings still need it: '.$list.'. Nothing was changed on them; please arrange another car / yacht or new dates.')
                ->warning()
                ->persistent()
                ->send();
        });
    }
}
