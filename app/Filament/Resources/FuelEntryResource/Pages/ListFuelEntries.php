<?php

namespace App\Filament\Resources\FuelEntryResource\Pages;

use App\Filament\Resources\FuelEntryResource;
use App\Models\Car;
use App\Models\FuelEntry;
use App\Models\FuelPrice;
use App\Models\Setting;
use App\Support\Access;
use App\Support\Fuel\Fuel;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ListFuelEntries extends ListRecords
{
    protected static string $resource = FuelEntryResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $missing = Fuel::priceSetForThisMonth() ? '' : ' <b style="color:rgb(var(--warning-600))">This month\'s fuel prices are not set yet: fuel charges at return can\'t be worked out.</b>';

        return new HtmlString('Fill-ups from drivers wait here. Confirming one adds the fuel expense to the car (and its booking).'.$missing);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('prices')->label('Fuel prices')->icon('heroicon-o-currency-dollar')->color('gray')
                ->modalHeading('Fuel prices')
                ->modalDescription('UAE prices change on the 1st of every month. These are used for the fuel charge at return and to check receipts.')
                ->modalSubmitActionLabel('Save')
                ->fillForm(function () {
                    $month = now()->startOfMonth();
                    $data = ['month' => $month->format('Y-m'), 'refuel_fee' => Setting::read('refuel_fee')];
                    foreach (array_keys(Fuel::GRADES) as $g) {
                        if ($g !== 'electric') {
                            $data['price_'.$g] = FuelPrice::where('fuel_grade', $g)->where('month', $month->toDateString())->value('price_per_litre');
                        }
                    }

                    return $data;
                })
                ->form(fn () => [
                    Forms\Components\Select::make('month')->native(false)->required()
                        ->options(collect([1, 0, -1, -2])->mapWithKeys(fn ($i) => [now()->startOfMonth()->addMonths($i)->format('Y-m') => now()->startOfMonth()->addMonths($i)->format('F Y')]))
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, $state) {
                            foreach (array_keys(Fuel::GRADES) as $g) {
                                if ($g !== 'electric') {
                                    $set('price_'.$g, FuelPrice::where('fuel_grade', $g)->where('month', Carbon::parse($state.'-01')->toDateString())->value('price_per_litre'));
                                }
                            }
                        }),
                    Forms\Components\Grid::make(3)->schema(collect(Fuel::GRADES)->except('electric')->map(fn ($label, $g) => Forms\Components\TextInput::make('price_'.$g)
                        ->label($label)->numeric()->minValue(0.5)->maxValue(20)->step(0.01)->prefix('AED')->suffix('/L')
                        ->helperText(fn (Forms\Get $get) => ($p = Fuel::price($g, Carbon::parse(($get('month') ?: now()->format('Y-m')).'-01')->subMonth())[0]) ? 'Before: '.number_format($p, 2) : null))->values()->all()),
                    Forms\Components\TextInput::make('refuel_fee')->label('Refuel fee (on top of the fuel)')
                        ->numeric()->minValue(0)->maxValue(1000)->prefix('AED')
                        ->helperText('Charged once when a car comes back with less fuel. Empty = no fee. Only Admin can change this.')
                        ->disabled(! Access::allows('manage-pricing')),
                ])
                ->action(function (array $data) {
                    $month = Carbon::parse($data['month'].'-01')->startOfMonth()->toDateString();
                    foreach (array_keys(Fuel::GRADES) as $g) {
                        $v = $data['price_'.$g] ?? null;
                        if ($g === 'electric' || $v === null || $v === '') {
                            continue;
                        }
                        FuelPrice::updateOrCreate(['fuel_grade' => $g, 'month' => $month], ['price_per_litre' => round((float) $v, 2), 'set_by' => auth()->id()]);
                    }
                    if (Access::allows('manage-pricing') && array_key_exists('refuel_fee', $data)) {
                        $fee = $data['refuel_fee'];
                        Setting::write(['refuel_fee' => ($fee === null || $fee === '') ? null : round((float) $fee, 2)]);
                    }
                    Notification::make()->title('Fuel prices saved')->success()->send();
                }),
            Actions\Action::make('report')->label('Monthly report')->icon('heroicon-o-chart-bar')->color('gray')
                ->url(fn () => \App\Filament\Pages\FuelReport::getUrl()),
            \App\Support\Exports\TableExport::action(),
            Actions\Action::make('add')->label('Add fill-up')->icon('heroicon-o-plus')
                ->modalHeading('Add fill-up')
                ->modalDescription('Added by Accounts / Admin, so it is confirmed at once and the fuel expense is made.')
                ->modalWidth('2xl')
                ->form(FuelEntryResource::entryForm())
                ->action(function (array $data, Actions\Action $action) {
                    try {
                        $e = Fuel::record($data, auth()->user());
                    } catch (ValidationException $ex) {
                        Notification::make()->title(collect($ex->errors())->flatten()->first())->danger()->send();
                        $action->halt();
                    }
                    Notification::make()->title('Fill-up added to '.$e->car?->name)->success()->send();
                }),
        ];
    }

    public function getTabs(): array
    {
        $waiting = FuelEntry::where('status', 'waiting')->count();

        return [
            'waiting' => Tab::make('Waiting')->badge($waiting ?: null)->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'waiting')),
            'confirmed' => Tab::make('Confirmed')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'confirmed')),
            'rejected' => Tab::make('Rejected')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return FuelEntry::where('status', 'waiting')->exists() ? 'waiting' : 'confirmed';
    }
}
