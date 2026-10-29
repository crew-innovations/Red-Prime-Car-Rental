<?php

namespace App\Filament\Account\Pages;

use App\Filament\Concerns\ChatPage;
use App\Support\Access;
use App\Support\Chat\Chat;
use App\Support\CustomerArea;
use Filament\Pages\Page;

/** The customer's messages with the RED PRIME team (never shows the team's internal notes). */
class Messages extends Page
{
    use ChatPage;

    protected static string $view = 'account.messages';

    protected static ?string $slug = 'messages';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Messages';

    protected static ?string $title = 'Messages';

    protected static ?int $navigationSort = 5;

    public ?string $newFor = '';

    public static function canAccess(): bool
    {
        return Access::isCustomer();
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Chat::unread();

        return $n ? (string) $n : null;
    }

    public function isStaff(): bool
    {
        return false;
    }

    public function bookingsToAsk()
    {
        return CustomerArea::bookings()->orderByDesc('start_at')->limit(30)->get(['id', 'reference', 'start_at', 'bookable_type', 'bookable_id']);
    }

    /** Start (or open) a conversation, about a booking or in general. */
    public function start(): void
    {
        $b = $this->newFor ? CustomerArea::bookings()->find((int) $this->newFor) : null;
        abort_if($this->newFor && ! $b, 404);
        $this->open(Chat::threadForCustomer($b)->id);
    }
}
