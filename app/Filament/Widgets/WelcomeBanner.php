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

    protected function getViewData(): array
    {
        $hour = (int) now()->format('G');
        $name = str(auth()->user()?->name ?? '')->before('(')->trim()->toString();

        $newToday = Lead::whereDate('created_at', today())->count();
        $followUps = Lead::followUpDue()->where('assigned_to', auth()->id())->count();
        $newUnhandled = Lead::where('status', LeadStatus::New)->count();
        $carsFree = Car::where('status', CarStatus::Available)->count();
        $docs = Car::documentsDueSoon()->count() + Yacht::documentsDueSoon()->count();
        $outToday = \App\Models\Booking::whereIn('status', [\App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::PendingPayment])->whereDate('start_at', today())->count();
        $backToday = \App\Models\Booking::where('status', \App\Enums\BookingStatus::InProgress)->where('end_at', '<=', now()->endOfDay())->count();
        $revenue = Transaction::totals(Transaction::query()->betweenDates(now()->startOfMonth(), now()->endOfMonth()))['received'];

        return [
            'greeting' => match (true) {
                $hour < 12 => 'Good morning',
                $hour < 17 => 'Good afternoon',
                default => 'Good evening',
            },
            'name' => $name,
            'date' => now()->format('l, j F Y'),
            'summary' => $followUps
                ? "You have {$followUps} follow-up".($followUps > 1 ? 's' : '').' waiting. Let\'s close some deals today.'
                : 'You are all caught up. Here is how Red Prime is doing today.',
            'chips' => array_values(array_filter([
                ['label' => 'revenue this month', 'value' => 'AED '.number_format($revenue), 'alert' => false],
                ['label' => 'new leads today', 'value' => $newToday, 'alert' => false],
                ['label' => 'not contacted yet', 'value' => $newUnhandled, 'alert' => $newUnhandled > 0],
                ['label' => 'cars available', 'value' => $carsFree, 'alert' => false],
                $outToday ? ['label' => 'going out today', 'value' => $outToday, 'alert' => false] : null,
                $backToday ? ['label' => 'due back today', 'value' => $backToday, 'alert' => true] : null,
                $docs ? ['label' => 'documents due', 'value' => $docs, 'alert' => true] : null,
            ])),
            'actions' => [
                ['label' => 'New lead', 'icon' => 'heroicon-m-plus', 'url' => LeadResource::getUrl('create'), 'primary' => true],
                ['label' => 'New booking', 'icon' => 'heroicon-m-calendar-days', 'url' => \App\Filament\Resources\BookingResource::getUrl('create'), 'primary' => false],
                ['label' => 'Pipeline', 'icon' => 'heroicon-m-view-columns', 'url' => \App\Filament\Pages\PipelineBoard::getUrl(), 'primary' => false],
                ['label' => 'Add car', 'icon' => 'heroicon-m-truck', 'url' => CarResource::getUrl('create'), 'primary' => false],
                ['label' => 'Add yacht', 'icon' => 'heroicon-m-lifebuoy', 'url' => YachtResource::getUrl('create'), 'primary' => false],
                ['label' => 'Add trip', 'icon' => 'heroicon-m-sun', 'url' => TripResource::getUrl('create'), 'primary' => false],
            ],
        ];
    }
}
