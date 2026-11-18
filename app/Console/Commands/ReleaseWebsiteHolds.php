<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Website bookings not paid in time (Business settings › Website bookings: hours to pay) are
 * cancelled, so their dates show as free everywhere. Even before this runs, an expired hold
 * no longer blocks anything (Booking::scopeNotExpired). Needs the server cron.
 */
class ReleaseWebsiteHolds extends Command
{
    protected $signature = 'crm:release-website-holds';

    protected $description = 'Cancel website bookings that were not paid in time';

    public function handle(): int
    {
        $n = 0;
        Booking::query()
            ->where('status', BookingStatus::PendingPayment)
            ->whereNotNull('hold_until')
            ->where('hold_until', '<=', now())
            ->where(fn ($q) => $q->whereNull('paid_amount')->orWhere('paid_amount', '<=', 0))
            ->orderBy('id')
            ->each(function (Booking $booking) use (&$n) {
                try {
                    $hours = $booking->created_at ? (int) round($booking->created_at->diffInHours($booking->hold_until)) : null;
                    $booking->status = BookingStatus::Cancelled;
                    $booking->cancel_reason = 'Not paid within '.($hours ? $hours.' hours' : 'the time allowed').' (website booking)';
                    $booking->save();
                    AuditLog::note($booking, 'status', 'Cancelled by the system: website booking not paid in time.');
                    $n++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        $this->info("{$n} website booking(s) cancelled.");

        return self::SUCCESS;
    }
}
