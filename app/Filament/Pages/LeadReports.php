<?php

namespace App\Filament\Pages;

use App\Support\Access;
use App\Support\Reports\LeadReport;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/** Accounts & reports › Lead report (28 Oct-2, Phase 20). */
class LeadReports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationGroup = 'Accounts & reports';

    protected static ?string $navigationLabel = 'Lead report';

    protected static ?string $title = 'Lead report';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.lead-reports';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    #[Url]
    public string $by = 'channel';

    /** 6 Nov (limousine L4): one service only, e.g. which channels bring limousine leads. */
    #[Url]
    public ?string $service = null;

    public static function canAccess(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-leads');
    }

    public function mount(): void
    {
        $this->from ??= now()->startOfMonth()->toDateString();
        $this->until ??= now()->endOfMonth()->toDateString();
        $this->by = array_key_exists($this->by, LeadReport::VIEWS) ? $this->by : 'channel';
    }

    public function setRange(string $range): void
    {
        [$this->from, $this->until] = match ($range) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'quarter' => [now()->startOfQuarter()->toDateString(), now()->endOfQuarter()->toDateString()],
            'year' => [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()],
            default => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
        };
    }

    public function report(): LeadReport
    {
        $from = rescue(fn () => Carbon::parse($this->from), now()->startOfMonth(), false);
        $until = rescue(fn () => Carbon::parse($this->until), now()->endOfMonth(), false);
        if ($until->lt($from)) {
            [$from, $until] = [$until, $from];
        }

        $service = \App\Enums\ServiceType::tryFrom((string) $this->service)?->value;

        return new LeadReport($from, $until, $service);
    }
}
