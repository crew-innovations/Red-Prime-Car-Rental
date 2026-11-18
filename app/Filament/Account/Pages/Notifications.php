<?php

namespace App\Filament\Account\Pages;

use App\Filament\Concerns\NotificationSettings;
use Filament\Pages\Page;

/** Customer account › Notifications (4 Nov, 9.2): what we tell the customer, and how. */
class Notifications extends Page
{
    use NotificationSettings;

    protected static string $view = 'filament.notification-settings';

    protected static ?string $slug = 'notifications';

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $title = 'Notifications';

    protected static ?int $navigationSort = 8;
}
