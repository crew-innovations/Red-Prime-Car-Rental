<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum DriveMode: string implements HasLabel, HasColor, HasIcon
{
    case SelfDrive = 'self_drive';
    case WithDriver = 'with_driver';

    public function getLabel(): string
    {
        return match ($this) {
            self::SelfDrive => 'Self-drive',
            self::WithDriver => 'With driver',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SelfDrive => 'gray',
            self::WithDriver => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::SelfDrive => 'heroicon-m-key',
            self::WithDriver => 'heroicon-m-user',
        };
    }
}
