<?php

namespace App\Support;

use App\Filament\Pages as P;
use App\Filament\Resources as R;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Str;

/**
 * 6 Nov ("Make it simple", S4 / C.1): one short menu per role.
 *
 * Every screen still exists and keeps its address; the menu just shows a few items per role and
 * puts the rest underneath them (shown when you are in that section). Each screen keeps its own
 * permission check, so a role never sees more than before. Anything a person may open that is not
 * placed below still appears under "More", so nothing is ever lost from the menu. Settings pages
 * are reached from one Settings page (App\Filament\Pages\SettingsHub).
 */
class Menu
{
    /** Which menu a person gets: the strongest of their roles. */
    public static function roleFor(?\App\Models\User $user): string
    {
        if (! $user) {
            return 'Agent';
        }
        if (Access::isAdmin($user)) {
            return 'Admin';
        }
        $roles = Access::rolesOf($user);
        foreach (['Accounts', 'Agent', 'Driver'] as $r) {
            if ($roles->contains($r)) {
                return $r;
            }
        }

        return 'Agent';
    }

    /**
     * The menus. Each entry: [screen, label or null, children]. A screen is a Filament class or a
     * shortcut key (see shortcut()). When the screen itself can't be opened, its first child that
     * can takes its place.
     */
    public static function layout(string $role): array
    {
        return match ($role) {
            'Driver' => [
                [P\MyJobs::class, null, []],
                [P\MyFuel::class, null, []],
                [P\Messages::class, null, []],
                [P\MyProfile::class, null, []],
            ],
            'Agent' => [
                [P\Dashboard::class, 'Home', []],
                [R\LeadResource::class, 'My leads', ['waiting-leads', P\PipelineBoard::class, R\QuotationResource::class, R\LeadFormResource::class]],
                [R\CustomerResource::class, 'My customers', [R\DocumentResource::class]],
                [R\BookingResource::class, 'My bookings', [R\DamageReportResource::class]],
                [R\RentalContractResource::class, 'My contracts', []], // 18 Nov
                [P\Calendar::class, null, []],
                [P\Messages::class, null, []],
            ],
            'Accounts' => [
                [P\Dashboard::class, 'Home', []],
                [R\TransactionResource::class, 'Payments', ['to-collect', R\InvoiceResource::class, R\PaymentRequestResource::class]],
                ['deposits', 'Deposits', []],
                [R\TrafficFineResource::class, 'Fines & Salik', [R\TollTripResource::class, R\TollAccountResource::class, P\StatementImport::class]],
                [R\FuelEntryResource::class, null, []],
                [R\SupplierResource::class, null, []],
                ['expenses', 'Expenses', []],
                [P\AccountsOverview::class, 'Reports', [P\ProfitReports::class, P\DiscountReports::class, P\LeadReports::class]],
            ],
            default => [ // Admin
                [P\Dashboard::class, 'Home', []],
                [R\LeadResource::class, 'Leads', [P\PipelineBoard::class, R\QuotationResource::class, R\CustomerResource::class, R\DocumentResource::class, R\LeadFormResource::class, P\Messages::class, P\LeadReports::class]],
                [R\BookingResource::class, 'Bookings', [P\DispatchBoard::class, R\DriverResource::class, R\DamageReportResource::class]],
                [R\RentalContractResource::class, 'Contracts', []], // 18 Nov: rental contracts
                // 6 Nov: Limousine is a core service, so it keeps its own place (it was hidden under Bookings / Settings).
                ['transfers', 'Limousine', [P\DispatchBoard::class, P\LiveDrivers::class, R\TransferServiceResource::class, R\VehicleCategoryResource::class, R\TransferRateResource::class, R\TransferFixedRouteResource::class, 'limo-leads']],
                [P\Calendar::class, null, []],
                [R\CarResource::class, 'Cars & Yachts', ['car-readiness', R\YachtResource::class, R\TripResource::class, R\PackageResource::class, R\SupplierResource::class]],
                [P\AccountsOverview::class, 'Money', [R\TransactionResource::class, 'to-collect', R\InvoiceResource::class, 'deposits', R\TrafficFineResource::class, R\TollTripResource::class, R\TollAccountResource::class, P\StatementImport::class, R\FuelEntryResource::class, R\PaymentRequestResource::class, P\DiscountReports::class, P\ProfitReports::class]],
                [P\Agents::class, null, []],
                [P\SettingsHub::class, 'Settings', []],
            ],
        };
    }

    /** Links to a list with a tab or filter already chosen. */
    protected static function shortcut(string $key): ?NavigationItem
    {
        $q = fn (string $k) => request()->query($k);

        return match ($key) {
            'car-readiness' => R\CarResource::canViewAny() ? NavigationItem::make('Car readiness')->icon('heroicon-o-sparkles')
                ->url(R\CarResource::getUrl('index', ['activeTab' => 'attention']))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.cars.index') && $q('activeTab') === 'attention') : null,
            'transfers' => R\BookingResource::canViewAny() ? NavigationItem::make('Transfer bookings')->icon('heroicon-o-map')
                ->url(R\BookingResource::getUrl('index', ['activeTab' => 'all', 'tableFilters' => ['service_type' => ['value' => 'transfer']]]))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.bookings.index') && data_get($q('tableFilters'), 'service_type.value') === 'transfer') : null,
            'limo-leads' => P\LeadReports::canAccess() ? NavigationItem::make('Limousine leads')->icon('heroicon-o-chart-bar')
                ->url(P\LeadReports::getUrl(['service' => 'transfer', 'by' => 'channel']))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.lead-reports') && $q('service') === 'transfer') : null,
            'to-collect' => R\TransactionResource::canViewAny() ? NavigationItem::make('To collect')->icon('heroicon-o-inbox-stack')
                ->url(R\TransactionResource::getUrl('index', ['activeTab' => 'outstanding']))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.transactions.index') && $q('activeTab') === 'outstanding') : null,
            'expenses' => R\TransactionResource::canViewAny() ? NavigationItem::make('Expenses')->icon('heroicon-o-receipt-percent')
                ->url(R\TransactionResource::getUrl('index', ['activeTab' => 'expense']))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.transactions.index') && $q('activeTab') === 'expense') : null,
            'deposits' => R\BookingResource::canViewAny() && Access::allows('manage-finance') ? NavigationItem::make('Deposits to settle')->icon('heroicon-o-shield-check')
                ->url(R\BookingResource::getUrl('index', ['activeTab' => 'deposits']))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.bookings.index') && $q('activeTab') === 'deposits') : null,
            'waiting-leads' => R\LeadResource::canViewAny() ? NavigationItem::make('Waiting for an agent')->icon('heroicon-o-hand-raised')
                ->url(R\LeadResource::getUrl('index', ['activeTab' => 'waiting']))
                ->badge(fn () => ($n = \App\Models\Lead::query()->whereNull('assigned_to')->whereNotNull('broadcast_at')->count()) ? (string) $n : null, 'warning')
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.leads.index') && $q('activeTab') === 'waiting') : null,
            default => null,
        };
    }

    /** Every screen of the panel the signed-in person may open, with its own menu item. */
    public static function available(): array
    {
        $panel = Filament::getCurrentPanel();
        $out = [];
        foreach ([...$panel->getPages(), ...$panel->getResources()] as $class) {
            try {
                if (! $class::shouldRegisterNavigation() || ! $class::canAccess()) {
                    continue;
                }
                $items = $class::getNavigationItems();
            } catch (\Throwable $e) {
                report($e);

                continue;
            }
            if ($items) {
                $out[$class] = $items[0];
            }
        }

        return $out;
    }

    protected static function item(string $screen, array $available, ?string $label = null): ?NavigationItem
    {
        if (! class_exists($screen)) {
            $s = self::shortcut($screen);

            return $s && $label ? $s->label($label) : $s;
        }
        if (! isset($available[$screen])) {
            return null;
        }
        $item = clone $available[$screen];
        $item->group(null)->parentItem(null)->sort(0);

        return $label ? $item->label($label) : $item;
    }

    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        $role = self::roleFor(auth()->user());
        $available = self::available();
        $placed = [];
        $top = [];

        foreach (self::layout($role) as [$screen, $label, $children]) {
            $kids = [];
            foreach ($children as $child) {
                if ($c = self::item($child, $available)) {
                    $kids[] = $c;
                    $placed[$child] = true;
                }
            }
            $parent = self::item($screen, $available, $label);
            $placed[$screen] = true;
            if (! $parent && $kids) {
                $parent = array_shift($kids)->label($label ?? '');
            }
            if (! $parent) {
                continue;
            }
            if ($kids) {
                $parent->childItems($kids);
            }
            $top[] = $parent;
        }

        // Settings pages live on the Settings page; they don't go under "More" for Admin.
        if ($role === 'Admin') {
            foreach (P\SettingsHub::screens() as $s) {
                $placed[$s] = true;
            }
        }

        // Anything else this person may open: never lost, just tucked away.
        $more = collect($available)->reject(fn ($item, $class) => isset($placed[$class]) || in_array($class, self::NEVER_IN_MENU, true))
            ->map(fn (NavigationItem $i) => (clone $i)->group(null)->parentItem(null))
            ->sortBy(fn (NavigationItem $i) => Str::lower($i->getLabel()))->values()->all();

        $builder->items($top);
        if ($more) {
            $builder->groups([NavigationGroup::make('More')->items($more)->collapsed()]);
        }

        return $builder;
    }

    /** Pages that are reached from elsewhere (bell, user menu), never from the menu. */
    public const NEVER_IN_MENU = [P\MyNotifications::class];
}
