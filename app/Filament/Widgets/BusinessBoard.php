<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\LeadStatus;
use App\Enums\QuotationStatus;
use App\Enums\Readiness;
use App\Enums\YachtStatus;
use App\Filament\Pages\ProfitReports;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\CarResource;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\QuotationResource;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\YachtResource;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Transaction;
use App\Models\Yacht;
use App\Support\Access;
use App\Support\Accounts\ProfitReport;
use Filament\Widgets\Widget;

/**
 * Dashboard "At a glance" (28 Oct brief, Priority 1.2): every number opens the
 * records behind it. Agents only count their own leads, quotes and bookings;
 * money only for roles that see money.
 */
class BusinessBoard extends Widget
{
    protected static string $view = 'filament.widgets.business-board';

    protected static ?int $sort = -5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '120s';

    public static function canView(): bool
    {
        return ! Access::isDriverOnly() && auth()->check();
    }

    protected function getViewData(): array
    {
        $bookings = fn () => Access::scopeOwn(Booking::query(), 'booking');
        $leads = fn () => Access::scopeOwn(Lead::query(), 'lead');
        $quotes = fn () => Access::scopeOwn(Quotation::query(), 'quotation');
        $b = fn (string $tab) => BookingResource::getUrl('index', ['activeTab' => $tab]);
        $row = fn (string $label, $value, ?string $url, string $tone = '', ?string $unit = null) => compact('label', 'value', 'url', 'tone', 'unit');

        $late = $bookings()->where('status', BookingStatus::InProgress)->where('end_at', '<', now())->count();
        $panels = [];

        $panels[] = ['title' => 'Bookings', 'icon' => 'heroicon-o-calendar-days', 'rows' => [
            $row('With the customer now', $bookings()->where('status', BookingStatus::InProgress)->count(), $b('out')),
            $row('Coming back in 48 hours', $bookings()->where('status', BookingStatus::InProgress)->whereBetween('end_at', [now(), now()->addHours(48)])->count(), $b('out')),
            $row('Late returns', $late, $b('late'), $late ? 'danger' : ''),
            $row('Starting in 7 days', $bookings()->whereIn('status', [BookingStatus::Confirmed, BookingStatus::PendingPayment])->whereBetween('start_at', [now(), now()->addDays(7)])->count(), $b('upcoming')),
            $row('Waiting for payment', $bookings()->where('status', BookingStatus::PendingPayment)->count(), $b('waiting'), 'warning'),
            $row('Enquiries not confirmed', $bookings()->whereIn('status', [BookingStatus::Inquiry, BookingStatus::Quotation])->count(), $b('enquiries')),
        ]];

        $panels[] = ['title' => 'Sales', 'icon' => 'heroicon-o-chat-bubble-left-right', 'rows' => array_values(array_filter([
            $row('New enquiries not contacted', $leads()->where('status', LeadStatus::New)->count(), LeadResource::getUrl('index', ['activeTab' => 'new'])),
            Access::allows('assign-leads')
                ? $row('Enquiries with no agent', Lead::query()->whereNull('assigned_to')->whereNotIn('status', [LeadStatus::Converted, LeadStatus::Lost])->count(), LeadResource::getUrl('index', ['activeTab' => 'unassigned']))
                : null,
            $row('Follow-ups due', $leads()->followUpDue()->count(), LeadResource::getUrl('index', ['activeTab' => 'follow_up'])),
            $row('Quotations waiting for the customer', $quotes()->whereIn('status', [QuotationStatus::Sent, QuotationStatus::Negotiation])->count(), QuotationResource::getUrl('index', ['activeTab' => 'open'])),
            $row('Accepted, booking not made yet', $quotes()->where('status', QuotationStatus::Accepted)->whereNull('booking_id')->count(), QuotationResource::getUrl('index', ['activeTab' => 'accepted']), 'warning'),
        ]))];

        $cars = Car::count();
        $yachts = Yacht::count();
        $maintenance = Car::where('status', CarStatus::Maintenance)->count() + Yacht::where('status', YachtStatus::Maintenance)->count();
        $docs = Car::documentsDueSoon()->count() + Yacht::documentsDueSoon()->count();
        $panels[] = ['title' => 'Fleet', 'icon' => 'heroicon-o-truck', 'rows' => array_values(array_filter([
            $row('Cars ready to rent', Car::where('status', CarStatus::Available)->where('readiness', Readiness::Ready)->count(), CarResource::getUrl('index', ['activeTab' => 'available']), '', '/ '.$cars),
            $row('Cars out on rent', Car::where('status', CarStatus::Rented)->count(), CarResource::getUrl('index', ['activeTab' => 'rented'])),
            $yachts ? $row('Yachts free', Yacht::where('status', YachtStatus::Available)->count(), YachtResource::getUrl('index', ['activeTab' => 'available']), '', '/ '.$yachts) : null,
            $row('Waiting for cleaning / inspection', Car::where('readiness', '!=', Readiness::Ready)->count(), CarResource::getUrl('index', ['activeTab' => 'attention'])),
            $row('In maintenance', $maintenance, CarResource::getUrl('index', ['activeTab' => 'maintenance']), $maintenance ? 'warning' : ''),
            $row('Documents due (insurance, registration…)', $docs, CarResource::getUrl('index', ['activeTab' => 'documents']), $docs ? 'danger' : ''),
        ]))];

        if (Access::allows('manage-finance')) {
            $s = ProfitReport::forRange(now()->startOfMonth(), now()->endOfMonth())->summary();
            $aed = fn ($v) => number_format((float) $v);
            $panels[] = ['title' => 'Money this month', 'icon' => 'heroicon-o-banknotes', 'rows' => [
                $row('Earned (completed, without VAT)', $aed($s['earned']), ProfitReports::getUrl(), '', 'AED'),
                $row('Received this month', $aed($s['received']), TransactionResource::getUrl('index', ['activeTab' => 'month']), '', 'AED'),
                $row('To collect', $aed($s['outstanding']), TransactionResource::getUrl('index', ['activeTab' => 'outstanding']), $s['outstanding'] > 0 ? 'warning' : '', 'AED'),
                $row('Actual costs', $aed($s['expenses']), ProfitReports::getUrl(['by' => 'category']), '', 'AED'),
                $row('Estimated costs (not counted)', $aed($s['estimates']), TransactionResource::getUrl('index', ['activeTab' => 'estimates']), '', 'AED'),
                $row('Expenses waiting for approval', Transaction::query()->where('approval_status', 'pending')->whereNull('reversed_at')->count(), TransactionResource::getUrl('index', ['activeTab' => 'approval'])),
            ]];
        }

        return ['panels' => $panels];
    }
}
