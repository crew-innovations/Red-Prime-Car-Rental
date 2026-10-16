<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasLabel, HasColor, HasIcon
{
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Pending = 'pending';
    case Overdue = 'overdue';
    case Refunded = 'refunded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::PartiallyPaid => 'Partially paid',
            self::Pending => 'Pending',
            self::Overdue => 'Overdue',
            self::Refunded => 'Refunded',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::PartiallyPaid => 'warning',
            self::Pending => 'info',
            self::Overdue => 'danger',
            self::Refunded => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Paid => 'heroicon-m-check-circle',
            self::PartiallyPaid => 'heroicon-m-adjustments-horizontal',
            self::Pending => 'heroicon-m-clock',
            self::Overdue => 'heroicon-m-exclamation-triangle',
            self::Refunded => 'heroicon-m-arrow-uturn-left',
        };
    }
}
