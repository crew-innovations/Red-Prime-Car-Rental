<?php

namespace App\Filament\Pages;

use App\Support\Access;
use App\Support\Accounts\ProfitReport;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** Finance › Profit & reports (28 Oct, Priority 8.3). Figures and their definitions on one screen. */
class ProfitReports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Accounts & reports';

    protected static ?string $navigationLabel = 'Profit & reports';

    protected static ?string $title = 'Profit & reports';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.profit-reports';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    #[Url]
    public string $by = 'booking';

    public const VIEWS = ['booking' => 'Booking', 'car' => 'Car', 'yacht' => 'Yacht', 'agent' => 'Agent', 'service' => 'Service', 'month' => 'Month', 'category' => 'Category'];

    public static function canAccess(): bool
    {
        return Access::allows('manage-finance');
    }

    public function mount(): void
    {
        $this->from ??= now()->startOfMonth()->toDateString();
        $this->until ??= now()->endOfMonth()->toDateString();
        $this->by = array_key_exists($this->by, self::VIEWS) ? $this->by : 'booking';
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

    public function report(): ProfitReport
    {
        $from = rescue(fn () => \Illuminate\Support\Carbon::parse($this->from), now()->startOfMonth(), false);
        $until = rescue(fn () => \Illuminate\Support\Carbon::parse($this->until), now()->endOfMonth(), false);
        if ($until->lt($from)) {
            [$from, $until] = [$until, $from];
        }

        return ProfitReport::forRange($from, $until);
    }
}
