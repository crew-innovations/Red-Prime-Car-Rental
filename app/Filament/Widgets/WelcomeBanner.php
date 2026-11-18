<?php

namespace App\Filament\Widgets;

use App\Enums\CarStatus;
use App\Enums\LeadStatus;
use App\Filament\Resources\CarResource;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\TripResource;
use App\Filament\Resources\YachtResource;
use App\Models\Car;
use App\Models\Lead;
use App\Models\Transaction;
use App\Models\Yacht;
use Filament\Widgets\Widget;

class WelcomeBanner extends Widget
{
    protected static string $view = 'filament.widgets.welcome-banner';

    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static function finesAlerts(): array
    {
        return rescue(function () {
            $fines = \App\Models\TrafficFine::where('status', 'new')->count();
            $toCharge = \App\Models\TrafficFine::where('status', 'matched')->count();
            $trips = \App\Models\TollTrip::where('status', 'new')->count();
            $low = \App\Models\TollAccount::query()->where('is_active', true)->whereNotNull('low_balance')->whereColumn('balance', '<', 'low_balance')->count();
            $fineUrl = fn ($tab) => \App\Filament\Resources\TrafficFineResource::getUrl('index', ['activeTab' => $tab]);

            return array_values(array_filter([
                $fines ? ['value' => $fines, 'label' => 'fine'.($fines > 1 ? 's' : '').' to review', 'url' => $fineUrl('review')] : null,
                $toCharge ? ['value' => $toCharge, 'label' => 'fine'.($toCharge > 1 ? 's' : '').' to charge', 'url' => $fineUrl('matched')] : null,
                $trips ? ['value' => $trips, 'label' => 'toll trip'.($trips > 1 ? 's' : '').' to review', 'url' => \App\Filament\Resources\TollTripResource::getUrl('index', ['activeTab' => 'review'])] : null,
                $low ? ['value' => $low, 'label' => 'toll account'.($low > 1 ? 's' : '').' low', 'url' => \App\Filament\Resources\TollAccountResource::getUrl('index')] : null,
            ]));
        }, [], false);
    }

    protected static function depositAlerts(): array
    {
        $n = rescue(fn () => \App\Support\Deposits\Settlement::dueQuery()->count(), 0, false);

        return $n ? [['value' => $n, 'label' => 'deposit'.($n > 1 ? 's' : '').' to settle', 'url' => \App\Filament\Resources\BookingResource::getUrl('index', ['activeTab' => 'deposits'])]] : [];
    }

    protected function getViewData(): array
    {
        $hour = (int) now()->format('G');
        $name = str(auth()->user()?->name ?? '')->before('(')->trim()->toString();

        $newToday = \App\Support\Access::scopeOwn(\App\Models\Lead::query(), 'lead')->whereDate('created_at', today())->count();
        $followUps = \App\Support\Access::scopeOwn(\App\Models\Lead::query(), 'lead')->followUpDue()->where('assigned_to', auth()->id())->count();
        $newUnhandled = \App\Support\Access::scopeOwn(\App\Models\Lead::query(), 'lead')->where('status', LeadStatus::New)->count();
        $carsFree = Car::where('status', CarStatus::Available)->where('readiness', 'ready')->count();
        $docs = Car::documentsDueSoon()->count();
        $outToday = \App\Support\Access::scopeOwn(\App\Models\Booking::query(), 'booking')->whereIn('status', [\App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::PendingPayment])->whereDate('start_at', today())->count();
        $backToday = \App\Support\Access::scopeOwn(\App\Models\Booking::query(), 'booking')->where('status', \App\Enums\BookingStatus::InProgress)->where('end_at', '<=', now()->endOfDay())->count();
        $docsToReview = \App\Models\CustomerDocument::where('status', \App\Enums\DocumentStatus::Received)->count();
        $quotesWaiting = \App\Support\Access::scopeOwn(\App\Models\Quotation::query(), 'quotation')->where('status', \App\Enums\QuotationStatus::Accepted)->whereNull('booking_id')->count();
        $notReady = Car::where('readiness', '!=', 'ready')->count();
        $revenue = Transaction::totals(Transaction::query()->betweenDates(now()->startOfMonth(), now()->endOfMonth()))['received'];

        $cars = Car::count();
        $lateBack = \App\Support\Access::scopeOwn(\App\Models\Booking::query(), 'booking')->where('status', \App\Enums\BookingStatus::InProgress)->where('end_at', '<', now())->count();
        $bookings = \App\Filament\Resources\BookingResource::class;
        $canFinance = \App\Support\Access::allows('manage-finance');

        // The four big numbers. Revenue only for roles that see money.
        $gauges = array_values(array_filter([
            ['value' => $outToday, 'label' => 'going out today', 'note' => null, 'url' => $bookings::getUrl('index', ['activeTab' => 'today'])],
            ['value' => $backToday, 'label' => 'due back today', 'note' => $lateBack ? $lateBack.' late' : null, 'url' => $bookings::getUrl('index', ['activeTab' => 'today'])],
            ['value' => $carsFree, 'unit' => '/ '.$cars, 'label' => 'cars ready to rent', 'note' => null, 'url' => CarResource::getUrl('index')],
            $canFinance
                ? ['value' => number_format($revenue), 'unit' => 'AED', 'unitFirst' => true, 'label' => 'received this month', 'note' => null, 'url' => \App\Filament\Pages\AccountsOverview::getUrl()]
                : ['value' => $newToday, 'label' => 'new leads today', 'note' => null, 'url' => LeadResource::getUrl('index')],
        ]));

        // Things waiting for someone. Only shown when there is something.
        $alerts = array_values(array_filter([
            $followUps ? ['value' => $followUps, 'label' => 'follow-up'.($followUps > 1 ? 's' : '').' for you', 'url' => LeadResource::getUrl('index', ['activeTab' => 'follow_up'])] : null,
            $newUnhandled ? ['value' => $newUnhandled, 'label' => 'new lead'.($newUnhandled > 1 ? 's' : '').' not contacted', 'url' => LeadResource::getUrl('index', ['activeTab' => 'new'])] : null,
            $quotesWaiting ? ['value' => $quotesWaiting, 'label' => 'accepted quote'.($quotesWaiting > 1 ? 's' : '').' to book', 'url' => \App\Filament\Resources\QuotationResource::getUrl('index', ['activeTab' => 'accepted'])] : null,
            $notReady ? ['value' => $notReady, 'label' => 'waiting for cleaning / inspection', 'url' => CarResource::getUrl('index', ['activeTab' => 'attention'])] : null,
            $docsToReview ? ['value' => $docsToReview, 'label' => 'customer document'.($docsToReview > 1 ? 's' : '').' to review', 'url' => null] : null,
            $docs ? ['value' => $docs, 'label' => 'car document'.($docs > 1 ? 's' : '').' due', 'url' => CarResource::getUrl('index')] : null,
            // 4 Nov (Phase 4): fines and tolls to review, deposits to settle, low toll account.
            ...(\App\Support\Access::allows('manage-fines') ? self::finesAlerts() : []),
            ...(\App\Support\Access::allows('manage-finance') ? self::depositAlerts() : []),
            // 4 Nov (16.4): drivers who would miss job notifications.
            ($noPush = (\App\Support\Access::allows('manage-apps') || \App\Support\Access::allows('manage-drivers')) ? rescue(fn () => \App\Filament\Pages\AppSettings::driversWithoutPush(), 0, false) : 0)
                ? ['value' => $noPush, 'label' => 'driver'.($noPush > 1 ? 's' : '').' with notifications off', 'url' => \App\Filament\Pages\AppSettings::canAccess() ? \App\Filament\Pages\AppSettings::getUrl() : null] : null,
        ]));

        return [
            'greeting' => match (true) {
                $hour < 12 => 'Good morning',
                $hour < 17 => 'Good afternoon',
                default => 'Good evening',
            },
            'name' => $name,
            'date' => now()->format('l, j F'),
            'summary' => $alerts
                ? 'Here is what needs you today.'
                : 'Nothing is waiting for you. Everything is on track.',
            'gauges' => $gauges,
            'alerts' => $alerts,
            'actions' => [
                ['label' => 'New lead', 'icon' => 'heroicon-m-plus', 'url' => LeadResource::getUrl('create'), 'primary' => true],
                ['label' => 'New quotation', 'icon' => 'heroicon-m-document-text', 'url' => \App\Filament\Resources\QuotationResource::getUrl('create'), 'primary' => false],
                ['label' => 'New booking', 'icon' => 'heroicon-m-calendar-days', 'url' => $bookings::getUrl('create'), 'primary' => false],
                ['label' => 'Pipeline', 'icon' => 'heroicon-m-view-columns', 'url' => \App\Filament\Pages\PipelineBoard::getUrl(), 'primary' => false],
            ],
            // Only what this person may add.
            'fleetActions' => array_values(array_filter([
                CarResource::canCreate() ? ['label' => 'Car', 'icon' => 'heroicon-m-truck', 'url' => CarResource::getUrl('create')] : null,
                \App\Filament\Resources\PackageResource::canCreate() ? ['label' => 'Package', 'icon' => 'heroicon-m-map', 'url' => \App\Filament\Resources\PackageResource::getUrl('create')] : null,
                TripResource::canCreate() ? ['label' => 'Trip', 'icon' => 'heroicon-m-sun', 'url' => TripResource::getUrl('create')] : null,
            ])),
        ];
    }
}
