<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Support\Access;
use App\Support\Exports\TableExport;
use App\Support\Fuel\Fuel;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * Accounts & reports › Fuel report (5 Nov, S5): per car for one month: fill-ups, litres, spend,
 * km driven, km per litre, cost per km, fuel charged to customers on returns, and the difference.
 * Opened from Fuel › Monthly report (not in the menu, to keep it short).
 */
class FuelReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $title = 'Fuel report';

    protected static ?string $slug = 'fuel-report';

    protected static string $view = 'filament.pages.fuel-report';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public ?string $month = null;

    public static function canAccess(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-finance');
    }

    public function mount(): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', (string) $this->month) ? $this->month : now()->format('Y-m');
    }

    public function monthDate(): Carbon
    {
        return rescue(fn () => Carbon::parse($this->month.'-01')->startOfMonth(), now()->startOfMonth(), false);
    }

    public function shift(int $by): void
    {
        $this->month = $this->monthDate()->addMonthsNoOverflow($by)->format('Y-m');
    }

    public function rows(): array
    {
        return Fuel::report($this->monthDate());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('Fill-ups')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(fn () => \App\Filament\Resources\FuelEntryResource::getUrl('index')),
            Action::make('csv')->label('Download CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (): bool => Access::allows('export-data'))
                ->action(function () {
                    abort_unless(Access::allows('export-data'), 403);
                    $rows = $this->rows();
                    AuditLog::event('export', 'Exported the fuel report for '.$this->monthDate()->format('F Y').' as CSV');

                    return response()->streamDownload(function () use ($rows) {
                        $out = fopen('php://output', 'w');
                        fputcsv($out, ['Car', 'Plate', 'Fill-ups', 'Litres', 'Spend (AED)', 'km driven', 'km per litre', 'Cost per km (AED)', 'Charged to customers (AED)', 'Difference (AED)']);
                        foreach ($rows as $r) {
                            fputcsv($out, [TableExport::safe($r['car']->name), TableExport::safe((string) $r['car']->registration_number), $r['fills'], $r['litres'], $r['spend'], $r['km'], $r['km_per_litre'], $r['cost_per_km'], $r['charged'], $r['difference']]);
                        }
                        fclose($out);
                    }, 'fuel-'.$this->month.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
        ];
    }
}
