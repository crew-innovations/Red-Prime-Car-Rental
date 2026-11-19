<?php

namespace App\Filament\Pages;

use App\Support\Access;
use App\Support\Reports\RentalReport;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** Money › Rental reports (19 Nov, suggestions 2 and 4). */
class RentalReports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = 'Accounts & reports';

    protected static ?string $navigationLabel = 'Rental reports';

    protected static ?string $title = 'Rental reports';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.rental-reports';

    public const VIEWS = ['moves' => 'Out & in', 'use' => 'Car use', 'unpaid' => 'Unpaid', 'vat' => 'VAT by emirate', 'cash' => 'Cash & bank', 'fines' => 'Fines & tolls'];

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    #[Url]
    public string $tab = 'moves';

    /** 19 Nov: fines & tolls per car or per customer. */
    #[Url]
    public string $by = 'car';

    public static function canAccess(): bool
    {
        return Access::allows('manage-finance') || Access::isAdmin();
    }

    public function mount(): void
    {
        $this->from ??= now()->startOfMonth()->toDateString();
        $this->until ??= now()->endOfMonth()->toDateString();
        $this->tab = array_key_exists($this->tab, self::VIEWS) ? $this->tab : 'moves';
    }

    public function setRange(string $range): void
    {
        [$this->from, $this->until] = match ($range) {
            'today' => [today()->toDateString(), today()->toDateString()],
            'week' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'quarter' => [now()->startOfQuarter()->toDateString(), now()->endOfQuarter()->toDateString()],
            default => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
        };
    }

    public function report(): RentalReport
    {
        $from = rescue(fn () => \Illuminate\Support\Carbon::parse($this->from)->startOfDay(), now()->startOfMonth(), false);
        $until = rescue(fn () => \Illuminate\Support\Carbon::parse($this->until)->endOfDay(), now()->endOfMonth(), false);
        if ($until->lt($from)) {
            [$from, $until] = [$until->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return new RentalReport($from, $until);
    }
}
