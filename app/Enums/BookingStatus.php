<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/** Booking statuses from Schedule A7. */
enum BookingStatus: string implements HasLabel, HasColor, HasIcon
{
    case Inquiry = 'inquiry';
    case Quotation = 'quotation';
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inquiry => 'Inquiry',
            self::Quotation => 'Quotation',
            self::PendingPayment => 'Pending payment',
            self::Confirmed => 'Confirmed',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Inquiry => 'gray',
            self::Quotation => 'info',
            self::PendingPayment => 'warning',
            self::Confirmed => 'success',
            self::InProgress => 'primary',
            self::Completed => 'gray',
            self::Cancelled, self::NoShow => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Inquiry => 'heroicon-m-question-mark-circle',
            self::Quotation => 'heroicon-m-document-text',
            self::PendingPayment => 'heroicon-m-clock',
            self::Confirmed => 'heroicon-m-check-circle',
            self::InProgress => 'heroicon-m-play-circle',
            self::Completed => 'heroicon-m-flag',
            self::Cancelled => 'heroicon-m-x-circle',
            self::NoShow => 'heroicon-m-user-minus',
        };
    }

    /** Statuses that hold the car / yacht for those dates. */
    public static function blocking(): array
    {
        return [self::PendingPayment, self::Confirmed, self::InProgress];
    }

    /** Statuses where the customer owes the booking amount. */
    public static function billable(): array
    {
        return [self::PendingPayment, self::Confirmed, self::InProgress, self::Completed];
    }

    /** Statuses staff can pick by hand; In progress / Completed come from delivery and return. */
    public static function manual(): array
    {
        return [self::Inquiry, self::Quotation, self::PendingPayment, self::Confirmed, self::Cancelled, self::NoShow];
    }
}
