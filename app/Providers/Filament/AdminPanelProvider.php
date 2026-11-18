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
            ->passwordReset(\App\Filament\Auth\RequestPasswordReset::class)
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
            ->userMenuItems([
                \Filament\Navigation\MenuItem::make()->label('My notifications')->icon('heroicon-o-bell-alert')
                    ->url(fn (): string => \App\Filament\Pages\MyNotifications::getUrl()),
            ])
            ->sidebarWidth('16.5rem')
            ->defaultAvatarProvider(\App\Support\InitialsAvatar::class)
            // 6 Nov (S4 / C.1): one short menu per role (Admin, Agent, Accounts, Driver). Every screen
            // keeps its address and its own permission check; see App\Support\Menu.
            ->navigation(fn (\Filament\Navigation\NavigationBuilder $builder): \Filament\Navigation\NavigationBuilder => \App\Support\Menu::build($builder))
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
            // 6 Nov (S4): "More views" on lists with extra tabs (App\Filament\Concerns\SimpleTabs).
            ->renderHook(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABS_END, fn (): HtmlString => new HtmlString(
                '<button type="button" class="fi-tabs-item rp-tab-more" onclick="document.body.classList.toggle(\'rp-tabs-open\')">'
                .'<span class="rp-tab-more-show">More views ▾</span><span class="rp-tab-more-hide">Fewer views ▴</span></button>'))
            // 6 Nov (S4 / C.2): "?" with three lines of help on the main pages (lang/en/help.php).
            ->renderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, fn (): HtmlString => \App\Support\Help::button())
            // Installed app and push notifications (4 Nov, brief 9 / 16).
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => \App\Support\Push\PanelHooks::head())
            ->renderHook(PanelsRenderHook::BODY_END, fn (): HtmlString => \App\Support\Push\PanelHooks::body())
            // Driver phone (4 Nov, 15): bottom navigation, the phone action queue and the texts it shows.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => auth()->user()?->isDriverOnly()
                ? new HtmlString('<script>window.RP_DRIVER_TEXT='.json_encode(['saved' => __('driver.saved', [], \App\Support\Drivers\DriverLang::for(auth()->user())), 'sent' => __('driver.sent', [], \App\Support\Drivers\DriverLang::for(auth()->user())), 'queued' => __('driver.queued', [], \App\Support\Drivers\DriverLang::for(auth()->user())), 'not_saved' => __('driver.not_saved', [], \App\Support\Drivers\DriverLang::for(auth()->user()))], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG).';</script><script src="'.asset('js/driver.js').'?v='.@filemtime(public_path('js/driver.js')).'"></script>')
                : new HtmlString(''))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => auth()->user()?->isDriverOnly() ? view('filament.driver.bottom-nav') : '')
            // 5 Nov (S3): "Accept" on a new-lead notice in the bell.
            ->renderHook(PanelsRenderHook::BODY_END, fn () => auth()->check() && ! auth()->user()->isDriverOnly()
                ? \Illuminate\Support\Facades\Blade::render('@livewire(\App\Livewire\LeadClaimListener::class)') : '')
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
