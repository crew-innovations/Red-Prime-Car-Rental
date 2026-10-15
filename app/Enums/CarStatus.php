<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CarStatus: string implements HasLabel, HasColor
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Rented = 'rented';
    case Maintenance = 'maintenance';
    case Unavailable = 'unavailable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Reserved => 'Reserved',
            self::Rented => 'Rented',
            self::Maintenance => 'Maintenance',
            self::Unavailable => 'Unavailable',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Reserved => 'warning',
            self::Rented => 'info',
            self::Maintenance => 'danger',
            self::Unavailable => 'gray',
        };
    }
}
