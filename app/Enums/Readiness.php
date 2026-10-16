<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a car / yacht is physically ready to go out. Separate from the
 * booking-driven status (Available / Reserved / Rented) and from Maintenance.
 */
enum Readiness: string implements HasLabel, HasColor, HasIcon
{
    case Ready = 'ready';
    case Cleaning = 'cleaning';
    case Inspection = 'inspection';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Cleaning => 'Needs cleaning',
            self::Inspection => 'Needs inspection',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Cleaning, self::Inspection => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Ready => 'heroicon-m-check-badge',
            self::Cleaning => 'heroicon-m-sparkles',
            self::Inspection => 'heroicon-m-magnifying-glass',
        };
    }
}
