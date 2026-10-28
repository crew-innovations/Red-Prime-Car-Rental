<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Filament\Support\Enums\MaxWidth;
use Filament\Enums\ThemeMode;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('crm')
            ->databaseTransactions()
            ->login()
            ->passwordReset()
            ->brandName('Red Prime CRM')
            ->brandLogo(asset('images/logo-mark-light.png'))      // light theme
            ->darkModeBrandLogo(asset('images/logo-mark.png'))   // dark theme
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('favicon.png'))
            ->colors([
                'primary' => Color::hex('#d7141a'), // logo red
                'warning' => Color::hex('#d4a62a'), // logo gold
                // Neutral, slightly cool grays (the same scale in light and dark).
                'gray' => [
                    50 => '246, 247, 249',
                    100 => '238, 240, 243',
                    200 => '223, 226, 232',
                    300 => '197, 202, 211',
                    400 => '162, 170, 184',
                    500 => '122, 131, 148',
                    600 => '93, 101, 116',
                    700 => '70, 76, 88',
                    800 => '48, 54, 66',
                    900 => '27, 32, 41',
                    950 => '16, 18, 22',
                ],
            ])
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Dark) // dark by default, users can switch to light
            ->font('Poppins')
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            // The bell: new driver jobs, handovers, collections, damage and document reminders.
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->sidebarWidth('16.5rem')
            ->defaultAvatarProvider(\App\Support\InitialsAvatar::class)
            ->navigationGroups([
                NavigationGroup::make('Overview'),
                NavigationGroup::make('Sales'),
                NavigationGroup::make('Operations'),
                NavigationGroup::make('Pricing'),
                NavigationGroup::make('Finance'),
                NavigationGroup::make('Administration'),
            ])
            // Shortcuts to the most used list tabs.
            ->navigationItems([
                NavigationItem::make('Car readiness')
                    ->group('Operations')
                    ->sort(3)
                    ->icon('heroicon-o-sparkles')
                    ->url(fn (): string => \App\Filament\Resources\CarResource::getUrl('index', ['activeTab' => 'attention']))
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.cars.index') && request()->query('activeTab') === 'attention')
                    ->visible(fn (): bool => \App\Filament\Resources\CarResource::canViewAny()),
                NavigationItem::make('To collect')
                    ->group('Finance')
                    ->sort(3)
                    ->icon('heroicon-o-inbox-stack')
                    ->url(fn (): string => \App\Filament\Resources\TransactionResource::getUrl('index', ['activeTab' => 'outstanding']))
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.transactions.index') && request()->query('activeTab') === 'outstanding')
                    ->visible(fn (): bool => \App\Filament\Resources\TransactionResource::canViewAny()),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            // The dashboard is App\Filament\Pages\Dashboard (found by discoverPages).
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                \App\Filament\Resources\LeadResource\Widgets\LeadOverview::class,
            ])
            // Extra styling (dashboard banner, stat cards, lists). Plain CSS, versioned by file time.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString('<link rel="stylesheet" href="'.asset('css/crm.css').'?v='.@filemtime(public_path('css/crm.css')).'">'),
            )
            // Phones and tablets: start each page with the menu closed so it never covers the content.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): HtmlString => new HtmlString("<script>document.addEventListener('alpine:initialized',function(){var s=window.Alpine&&window.Alpine.store('sidebar');if(s&&s.isOpen&&window.innerWidth<1024){s.close();}});</script>"),
            )
            // Light / dark switch in the top bar (the same setting as in the user menu).
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn () => view('filament.theme-toggle'),
            )
            // Signed-in user card at the bottom of the sidebar.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn () => view('filament.sidebar-footer'),
            )
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
            ]);
    }
}
