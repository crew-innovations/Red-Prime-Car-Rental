<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum LeadPriority: string implements HasLabel, HasColor, HasIcon
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cold = 'cold';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hot => 'Hot',
            self::Warm => 'Warm',
            self::Cold => 'Cold',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Hot => 'danger',
            self::Warm => 'warning',
            self::Cold => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Hot => 'heroicon-m-fire',
            self::Warm => 'heroicon-m-sun',
            self::Cold => 'heroicon-m-cloud',
        };
    }
}
