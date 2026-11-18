<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\NotificationSettings;
use Filament\Pages\Page;

/** My notifications (4 Nov, 9.2): staff and drivers choose what reaches them, and how. */
class MyNotifications extends Page
{
    use NotificationSettings;

    protected static string $view = 'filament.notification-settings';

    protected static ?string $slug = 'my-notifications';

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'My notifications';

    protected static ?string $title = 'My notifications';

    protected static ?int $navigationSort = -18;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasCrmRole();
    }

    /** Drivers have it in their menu; staff reach it from the user menu (top right). */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user()?->isDriverOnly();
    }
}
