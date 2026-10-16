<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/** Quotation statuses from Schedule A8 (Viewed is shown from viewed_at). */
enum QuotationStatus: string implements HasLabel, HasColor, HasIcon
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Negotiation = 'negotiation';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Negotiation => 'Negotiation',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'info',
            self::Negotiation => 'warning',
            self::Accepted => 'success',
            self::Rejected, self::Expired => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-m-pencil',
            self::Sent => 'heroicon-m-paper-airplane',
            self::Negotiation => 'heroicon-m-chat-bubble-left-right',
            self::Accepted => 'heroicon-m-hand-thumb-up',
            self::Rejected => 'heroicon-m-hand-thumb-down',
            self::Expired => 'heroicon-m-clock',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Negotiation], true);
    }
}
