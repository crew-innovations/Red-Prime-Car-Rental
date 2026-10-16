<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ActivityType: string implements HasLabel, HasColor, HasIcon
{
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Meeting = 'meeting';
    case Note = 'note';

    public function getLabel(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Meeting => 'Meeting',
            self::Note => 'Note',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Call => 'info',
            self::WhatsApp => 'success',
            self::Email => 'gray',
            self::Meeting => 'warning',
            self::Note => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Call => 'heroicon-m-phone',
            self::WhatsApp => 'heroicon-m-chat-bubble-left-right',
            self::Email => 'heroicon-m-envelope',
            self::Meeting => 'heroicon-m-user-group',
            self::Note => 'heroicon-m-pencil-square',
        };
    }
}
