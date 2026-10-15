<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum YachtStatus: string implements HasLabel, HasColor
{
    case Available = 'available';
    case Booked = 'booked';
    case Maintenance = 'maintenance';
    case Unavailable = 'unavailable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Booked => 'Booked',
            self::Maintenance => 'Maintenance',
            self::Unavailable => 'Unavailable',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Booked => 'info',
            self::Maintenance => 'danger',
            self::Unavailable => 'gray',
        };
    }
}
