<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Support\Access;
use App\Support\Exports\TableExport;
use App\Support\Reports\DiscountReport;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/** Accounts & reports › Discounts given (upgrade brief 4 Nov, 10). Finance and managers. */
class DiscountReports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Accounts & reports';

    protected static ?string $navigationLabel = 'Discounts given';

    protected static ?string $title = 'Discounts given';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'discount-report';

    protected static string $view = 'filament.pages.discount-reports';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    #[Url]
    public string $by = 'agent';

    #[Url]
    public string $source = 'bookings';

    public static function canAccess(): bool
    {
        return Access::allows('manage-finance') || Access::allows('approve-prices');
    }

    public function mount(): void
    {
        $this->from ??= now()->startOfMonth()->toDateString();
        $this->until ??= now()->endOfMonth()->toDateString();
        $this->by = array_key_exists($this->by, DiscountReport::GROUPS) ? $this->by : 'agent';
        $this->source = array_key_exists($this->source, DiscountReport::SOURCES) ? $this->source : 'bookings';
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

    public function report(): DiscountReport
    {
        $from = rescue(fn () => Carbon::parse($this->from), now()->startOfMonth(), false);
        $until = rescue(fn () => Carbon::parse($this->until), now()->endOfMonth(), false);
        if ($until->lt($from)) {
            [$from, $until] = [$until, $from];
        }

        return new DiscountReport($from, $until, $this->source);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => Access::allows('export-data'))
                ->action(function () {
                    abort_unless(Access::allows('export-data'), 403);
                    $report = $this->report();
                    $rows = $report->rows($this->by);
                    $title = 'Discounts given by '.strtolower(DiscountReport::GROUPS[$this->by]).' ('.DiscountReport::SOURCES[$this->source].', '.$report->from->format('d M Y').' – '.$report->to->format('d M Y').')';
                    AuditLog::event('export', 'Exported '.count($rows).' row(s) of '.$title.' as CSV');

                    return response()->streamDownload(function () use ($rows) {
                        $out = fopen('php://output', 'w');
                        fputcsv($out, [DiscountReport::GROUPS[$this->by], 'Records', 'With a discount', 'Price list (discounted ones)', 'Total discount', 'Average %']);
                        foreach ($rows as $r) {
                            fputcsv($out, [TableExport::safe($r['label']), $r['count'], $r['discounted'], $r['list'], $r['total'], $r['average']]);
                        }
                        fclose($out);
                    }, 'discounts-given.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
        ];
    }
}
