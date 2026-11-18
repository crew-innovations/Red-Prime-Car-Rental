<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\FineStatus;
use App\Filament\Resources as R;
use App\Models\Booking;
use App\Models\Car;
use App\Models\FuelEntry;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\TollTrip;
use App\Models\TrafficFine;
use App\Models\Transaction;
use App\Support\Access;
use App\Support\Deposits\Settlement;
use App\Support\Menu;
use App\Support\Reports\AgentReport;
use App\Support\Suppliers\SupplierLedger;
use Filament\Widgets\Widget;

/**
 * Home per role (6 Nov, S4 / C.4): a few tiles, each a number and where to act on it.
 *  - Accounts: to collect, deposits to settle, fines & Salik to charge, fuel to confirm, suppliers
 *    to pay, payments needing a check.
 *  - Admin: the same, plus leads waiting, cars today and the agents this month.
 *  - Agent: leads waiting, and six numbers for this month.
 * Counts only; every tile opens the list where the work is done.
 */
class RoleHome extends Widget
{
    protected static string $view = 'filament.widgets.role-home';

    protected static ?int $sort = -8;

    protected static bool $isLazy = false; // a few counts: show at once

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->check() && ! Access::isDriverOnly();
    }

    protected function tile(string $label, string $value, ?string $url, string $tone = 'neutral', ?string $note = null): array
    {
        return compact('label', 'value', 'url', 'tone', 'note');
    }

    protected function aed(float $v): string
    {
        return 'AED '.number_format($v, 0);
    }

    /** @return list<array> */
    protected function moneyTiles(): array
    {
        if (! Access::allows('manage-finance')) {
            return [];
        }
        $toCollect = (float) Booking::query()->whereIn('status', BookingStatus::billable())->where('balance', '>', 0)->sum('balance');
        $deposits = Settlement::dueQuery()->count();
        $fines = TrafficFine::query()->whereIn('status', [FineStatus::New, FineStatus::Matched])->count()
            + TollTrip::query()->whereIn('status', ['new', 'matched'])->count();
        $fuel = FuelEntry::query()->where('status', 'waiting')->count();
        $suppliers = rescue(fn () => SupplierLedger::totalOwed(), 0.0, false);
        $checks = Transaction::query()->where('approval_status', 'pending')->count();

        return [
            $this->tile('To collect', $this->aed($toCollect), R\TransactionResource::getUrl('index', ['activeTab' => 'outstanding']), $toCollect > 0 ? 'warning' : 'ok'),
            $this->tile('Deposits to settle', (string) $deposits, R\BookingResource::getUrl('index', ['activeTab' => 'deposits']), $deposits ? 'warning' : 'ok'),
            $this->tile('Fines & Salik to charge', (string) $fines, R\TrafficFineResource::getUrl('index'), $fines ? 'warning' : 'ok'),
            $this->tile('Fuel to confirm', (string) $fuel, R\FuelEntryResource::getUrl('index'), $fuel ? 'warning' : 'ok'),
            $this->tile('Suppliers to pay', $this->aed((float) $suppliers), R\SupplierResource::canAccess() ? R\SupplierResource::getUrl('index') : null, $suppliers > 0 ? 'neutral' : 'ok'),
            $this->tile('Payments needing a check', (string) $checks, R\TransactionResource::getUrl('index', ['activeTab' => 'approval']), $checks ? 'danger' : 'ok'),
        ];
    }

    protected function waitingLeads(): int
    {
        return Access::scopeOwn(Lead::query(), 'lead')->whereNull('assigned_to')->whereNotNull('broadcast_at')->open()->count();
    }

    /** @return array{role: string, groups: list<array{title: string, tiles: list<array>}>, agents: list<array>} */
    public function home(): array
    {
        $role = Menu::roleFor(auth()->user());
        $groups = [];
        $agents = [];

        if ($role === 'Agent') {
            $from = now()->startOfMonth();
            $own = fn ($q, $kind) => Access::scopeOwn($q, $kind);
            $leads = $own(Lead::query(), 'lead')->where('assigned_to', auth()->id())->where('accepted_at', '>=', $from)->count();
            $quotes = $own(Quotation::query(), 'quotation')->whereNotNull('sent_at')->where('sent_at', '>=', $from)->count();
            $bookings = $own(Booking::query(), 'booking')->where('created_at', '>=', $from)->whereNotIn('status', [BookingStatus::Cancelled, BookingStatus::Inquiry, BookingStatus::Quotation, BookingStatus::NoShow]);
            $count = (clone $bookings)->count();
            $toCollect = (float) $own(Booking::query(), 'booking')->whereIn('status', BookingStatus::billable())->where('balance', '>', 0)->sum('balance');
            $waiting = $this->waitingLeads();
            $groups[] = ['title' => 'Right now', 'tiles' => [
                $this->tile('Leads waiting for an agent', (string) $waiting, R\LeadResource::getUrl('index', ['activeTab' => 'waiting']), $waiting ? 'danger' : 'ok', $waiting ? 'First to accept owns it' : null),
            ]];
            $groups[] = ['title' => 'This month', 'tiles' => [
                $this->tile('Leads', (string) $leads, R\LeadResource::getUrl('index')),
                $this->tile('Quotes sent', (string) $quotes, R\QuotationResource::getUrl('index')),
                $this->tile('Bookings', (string) $count, R\BookingResource::getUrl('index', ['activeTab' => 'all'])),
                $this->tile('Revenue', $this->aed((float) (clone $bookings)->sum('total')), null),
                $this->tile('To collect', $this->aed($toCollect), R\BookingResource::getUrl('index', ['activeTab' => 'balance']), $toCollect > 0 ? 'warning' : 'ok'),
                $this->tile('Leads that booked', $leads ? round($count / max($leads, 1) * 100).'%' : '–', null),
            ]];

            return compact('role', 'groups', 'agents');
        }

        if ($role === 'Admin') {
            $waiting = $this->waitingLeads();
            $out = Booking::query()->where('status', BookingStatus::InProgress)->where('bookable_type', 'car')->count();
            $back = Booking::query()->where('status', BookingStatus::InProgress)->where('bookable_type', 'car')->whereDate('end_at', today())->count();
            $groups[] = ['title' => 'Today', 'tiles' => [
                $this->tile('Leads waiting for an agent', (string) $waiting, R\LeadResource::getUrl('index', ['activeTab' => 'waiting']), $waiting ? 'danger' : 'ok'),
                $this->tile('Cars out', (string) $out, R\BookingResource::getUrl('index', ['activeTab' => 'out'])),
                $this->tile('Cars coming back today', (string) $back, R\BookingResource::getUrl('index', ['activeTab' => 'today']), $back ? 'warning' : 'neutral'),
                $this->tile('Cars available', (string) Car::query()->where('status', CarStatus::Available)->count(), R\CarResource::getUrl('index')),
                $this->tile('In maintenance', (string) Car::query()->where('status', CarStatus::Maintenance)->count(), R\CarResource::getUrl('index', ['activeTab' => 'attention'])),
            ]];
            $agents = collect(rescue(fn () => (new AgentReport(now()->startOfMonth(), now()))->rows(), [], false))
                ->sortByDesc('revenue')->take(5)->values()->all();
        }

        if ($money = $this->moneyTiles()) {
            $groups[] = ['title' => 'Money', 'tiles' => $money];
        }

        return compact('role', 'groups', 'agents');
    }
}
