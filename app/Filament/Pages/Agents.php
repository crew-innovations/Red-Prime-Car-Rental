<?php

namespace App\Filament\Pages;

use App\Support\Access;
use App\Support\Reports\AgentReport;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/** 5 Nov ("Make it simple", B.6): what each agent did. Admin only; every number opens its list. */
class Agents extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Agents';

    protected static ?string $title = 'Agents';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'agents';

    protected static string $view = 'filament.pages.agents';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    public static function canAccess(): bool
    {
        return Access::isAdmin();
    }

    public function mount(): void
    {
        $this->from ??= now()->startOfMonth()->toDateString();
        $this->until ??= now()->endOfMonth()->toDateString();
    }

    public function setRange(string $range): void
    {
        [$this->from, $this->until] = match ($range) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'last_30' => [now()->subDays(29)->toDateString(), now()->toDateString()],
            'year' => [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()],
            default => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
        };
    }

    public function getViewData(): array
    {
        $from = rescue(fn () => Carbon::parse($this->from), now()->startOfMonth(), false);
        $to = rescue(fn () => Carbon::parse($this->until), now()->endOfMonth(), false);

        return ['rows' => (new AgentReport($from, $to))->rows(), 'fromDate' => $from, 'toDate' => $to];
    }

    /** Links to the lists behind the numbers. */
    public static function leadsUrl(int $agent, string $tab = 'all'): string
    {
        return \App\Filament\Resources\LeadResource::getUrl('index', ['activeTab' => $tab, 'tableFilters' => ['assigned_to' => ['value' => $agent]]]);
    }

    public static function bookingsUrl(int $agent, string $tab = 'all'): string
    {
        return \App\Filament\Resources\BookingResource::getUrl('index', ['activeTab' => $tab, 'tableFilters' => ['assigned_to' => ['value' => $agent]]]);
    }
}
