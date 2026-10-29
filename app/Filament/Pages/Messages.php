<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ChatPage;
use App\Support\Access;
use App\Support\Chat\Chat;
use Filament\Pages\Page;

/** Messages with customers and drivers (brief 28 Oct-2, Phase 17). Drivers see only their own. */
class Messages extends Page
{
    use ChatPage;

    protected static string $view = 'filament.pages.messages';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Messages';

    public static function canAccess(): bool
    {
        return in_array(Chat::role(), ['staff', 'driver'], true);
    }

    public static function getNavigationGroup(): ?string
    {
        return Access::isDriverOnly() ? null : static::$navigationGroup;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Chat::unread();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function isStaff(): bool
    {
        return Chat::role() === 'staff';
    }
}
