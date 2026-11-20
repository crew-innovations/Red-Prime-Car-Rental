<?php

namespace App\Providers\Filament;

use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The customer's account area (/account) inside the same application: same
 * users table, login system, database and design as the CRM. Customers only
 * ever see their own bookings, quotations, documents and payments.
 */
class AccountPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('account')
            ->path('account')
            ->login()
            ->passwordReset(\App\Filament\Auth\RequestPasswordReset::class)
            ->profile(\App\Filament\Account\Pages\AccountProfile::class, isSimple: false)
            ->brandName('Red Prime · My account')
            ->brandLogo(asset('images/logo-mark-light.png'))
            ->darkModeBrandLogo(asset('images/logo-mark.png'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('favicon.png'))
            ->colors([
                'primary' => Color::hex('#d7141a'),
                'warning' => Color::hex('#d4a62a'),
                'gray' => [
                    50 => '246, 247, 249', 100 => '238, 240, 243', 200 => '223, 226, 232', 300 => '197, 202, 211',
                    400 => '162, 170, 184', 500 => '122, 131, 148', 600 => '93, 101, 116', 700 => '70, 76, 88',
                    800 => '48, 54, 66', 900 => '27, 32, 41', 950 => '16, 18, 22',
                ],
            ])
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Light)
            ->font('Poppins')
            ->maxContentWidth(MaxWidth::FiveExtraLarge)
            ->topNavigation()
            ->defaultAvatarProvider(\App\Support\InitialsAvatar::class)
            ->discoverPages(in: app_path('Filament/Account/Pages'), for: 'App\\Filament\\Account\\Pages')
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString('<link rel="stylesheet" href="'.asset('css/crm.css').'?v='.@filemtime(public_path('css/crm.css')).'">'),
            )
            // Installed app and push notifications (4 Nov, brief 9 / 16).
            // 20 Nov (item 12): "Help" on every page of the customer account.
            ->renderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, fn (): HtmlString => \App\Support\Help::button())
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => \App\Support\Push\PanelHooks::head())
            ->renderHook(PanelsRenderHook::BODY_END, fn (): HtmlString => \App\Support\Push\PanelHooks::body())
            // The bell for customers too (4 Nov, 9.1).
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\CustomerDocumentsFirst::class, // 19 Nov: documents before the rest of the account
            ]);
    }
}
