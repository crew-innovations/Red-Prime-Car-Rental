<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
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
                'gray' => Color::Zinc,
            ])
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Dark) // dark by default, users can switch to light
            ->font('Poppins')
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Sales'),
                NavigationGroup::make('Accounts'),
                NavigationGroup::make('Fleet'),
                NavigationGroup::make('Website'),
                NavigationGroup::make('Settings')->collapsed(),
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
