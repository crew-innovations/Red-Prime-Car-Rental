<?php

namespace App\Filament\Resources\TollTripResource\Pages;

use App\Filament\Resources\TollTripResource;
use App\Models\TollTrip;
use App\Support\Tolls\Tolls;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Builder;

class ManageTollTrips extends ManageRecords
{
    protected static string $resource = TollTripResource::class;

    protected ?string $subheading = 'Trips go on the customer\'s booking as one line per type; passed on, not revenue. Trips nobody pays are a company cost.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')->label('Import statement')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->url(fn () => \App\Filament\Pages\StatementImport::getUrl(['kind' => 'tolls'])),
            \App\Support\Exports\TableExport::action(),
            Actions\CreateAction::make()->label('Add trip')
                ->mutateFormDataUsing(fn (array $data) => $data + ['status' => 'new', 'created_by' => auth()->id(),
                    'dedupe_key' => TollTrip::dedupeKey($data['toll_type'], null, $data['plate'] ?? ($data['car_id'] ?? null), $data['passed_at'], $data['gate'] ?? null)])
                ->after(function (TollTrip $record) {
                    $r = Tolls::match($record);
                    if ($r->status === 'matched' && Tolls::autoCharge()) {
                        Tolls::chargeBooking($r->booking);
                    }
                }),
        ];
    }

    public function getTabs(): array
    {
        $count = fn (string $s) => TollTrip::where('status', $s)->count() ?: null;

        return [
            'review' => Tab::make('Needs review')->badge($count('new'))->badgeColor('danger')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'new')),
            'matched' => Tab::make('Matched, not on the booking yet')->badge($count('matched'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'matched')),
            'charged' => Tab::make('Charged')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'charged')),
            'company' => Tab::make('Company cost')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'company')),
            'all' => Tab::make('All'),
        ];
    }
}
