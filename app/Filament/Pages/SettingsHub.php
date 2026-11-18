<?php

namespace App\Filament\Pages;

use App\Filament\Resources as R;
use Filament\Pages\Page;

/**
 * Settings (6 Nov, S4 / C.1): one page that lists every settings screen in a few plain groups,
 * instead of 19 items in the menu. Each card only shows the screens the person may open.
 */
class SettingsHub extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?string $slug = 'settings';

    protected static string $view = 'filament.pages.settings-hub';

    /** [title, one line, screens] */
    public static function groups(): array
    {
        return [
            ['Everyday settings', 'Company, VAT, advance, discount limit, fees and lead times: the ones you change.', [BasicSettings::class]],
            ['Automatic messages', 'What the CRM sends by itself: reminders when a customer does not answer, and the first reply to a chat on Instagram, Facebook or WhatsApp.', [FollowUpSettings::class, ChatReplySettings::class]],
            ['Prices', 'Daily, weekly and monthly prices, special rates, extras and limousine rates.', [RateCard::class, R\PriceRuleResource::class, R\PriceExtraResource::class, PriceCheck::class, R\TransferServiceResource::class, R\VehicleCategoryResource::class, R\TransferRateResource::class, R\TransferFixedRouteResource::class]],
            ['Team', 'Who can sign in and what each role can do.', [R\UserResource::class]],
            ['Lists', 'Choices staff pick from, like where a lead came from.', [R\LeadSourceResource::class]],
            ['Website', 'Sections, offers, reviews and blog posts on redprimerentals.com.', [R\CategoryResource::class, WebsiteContent::class, R\BlogPostResource::class]],
            ['Email', 'Sending accounts, ready-made emails, what was sent, and announcements.', [R\MailAccountResource::class, R\EmailTemplateResource::class, R\EmailLogResource::class, Announcements::class]],
            ['Apps & connections', 'Phone apps for staff and drivers, payments, maps and lead sources.', [AppSettings::class, Integrations::class]],
            ['History', 'Who changed what, and when.', [R\HistoryResource::class]],
            ['Advanced', 'Every business rule in one place. Change with care.', [BusinessSettings::class]],
        ];
    }

    /** @return list<class-string> */
    public static function screens(): array
    {
        return collect(self::groups())->flatMap(fn ($g) => $g[2])->all();
    }

    protected static function opens(string $class): bool
    {
        try {
            return $class::canAccess();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function canAccess(): bool
    {
        // Admin only. Other roles reach the few settings screens they use (e.g. Rate card for agents)
        // under "More" in their menu.
        return \App\Support\Access::isAdmin();
    }

    /** The cards for the page: only screens this person may open. */
    public function cards(): array
    {
        return collect(self::groups())->map(fn ($g) => [
            'title' => $g[0],
            'about' => $g[1],
            'links' => collect($g[2])->filter(fn ($c) => self::opens($c))->map(fn ($c) => [
                'label' => $c::getNavigationLabel(),
                'icon' => $c::getNavigationIcon() ?: 'heroicon-o-cog-6-tooth',
                'url' => is_subclass_of($c, \Filament\Resources\Resource::class) ? $c::getUrl('index') : $c::getUrl(),
            ])->values()->all(),
        ])->filter(fn ($g) => $g['links'])->values()->all();
    }

    /** The menu item stays lit on every settings screen. */
    public static function getNavigationItems(): array
    {
        $routes = collect(self::screens())->map(fn ($c) => is_subclass_of($c, \Filament\Resources\Resource::class)
            ? $c::getRouteBaseName().'.*' : $c::getRouteName())->push(static::getRouteName())->all();

        return [parent::getNavigationItems()[0]->isActiveWhen(fn (): bool => request()->routeIs(...$routes))];
    }
}
