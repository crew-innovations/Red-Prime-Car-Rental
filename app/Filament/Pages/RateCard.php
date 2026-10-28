<?php

namespace App\Filament\Pages;

use App\Models\Car;
use App\Models\Package;
use App\Models\PriceRule;
use App\Models\Trip;
use App\Models\Yacht;
use App\Support\Access;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * Pricing › Rate card: the standard rates of every car, yacht, trip and
 * package on one screen, editable in place (they are the same fields as on
 * each record, so there is only one place a standard rate lives).
 */
class RateCard extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Rate card';

    protected static ?string $title = 'Rate card';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.rate-card';

    #[Url]
    public string $service = 'car';

    public static function canAccess(): bool
    {
        return ! Access::isDriverOnly() && ! Access::isCustomer() && (Access::allows('manage-pricing') || Access::allows('manage-quotations'));
    }

    public function getSubheading(): ?string
    {
        return 'Standard rates. Special rates for seasons and dates are under Special rates and are applied on top.';
    }

    public function services(): array
    {
        return ['car' => 'Cars', 'yacht' => 'Yachts', 'trip' => 'Yacht trips', 'package' => 'Trips & packages'];
    }

    public function updatedService(): void
    {
        $this->service = array_key_exists($this->service, $this->services()) ? $this->service : 'car';
        $this->resetTable();
    }

    protected function editable(): bool
    {
        return Access::allows('manage-pricing');
    }

    public function table(Table $table): Table
    {
        $service = array_key_exists($this->service, $this->services()) ? $this->service : 'car';
        $money = fn (string $name, string $label) => $this->editable()
            ? Tables\Columns\TextInputColumn::make($name)->label($label)->type('number')->step(1)->rules(['nullable', 'numeric', 'min:0'])
                ->extraAttributes(['style' => 'min-width:8.5rem'])
                ->getStateUsing(fn ($record) => $record->{$name} === null ? null : \App\Support\Pricing\PriceEngine::qtyText((float) $record->{$name}))
            : Tables\Columns\TextColumn::make($name)->label($label)->money('AED')->placeholder('-');

        $query = match ($service) {
            'yacht' => Yacht::query(),
            'trip' => Trip::query(),
            'package' => Package::query(),
            default => Car::query(),
        };

        $columns = match ($service) {
            'yacht' => [
                $money('hourly_price', 'Per hour'),
                $money('half_day_price', 'Half day'),
                $money('full_day_price', 'Full day'),
                $money('deposit', 'Deposit'),
                Tables\Columns\TextColumn::make('minimum_hours')->label('Min hours')->placeholder('-'),
                Tables\Columns\TextColumn::make('max_guests')->label('Guests')->placeholder('-'),
            ],
            'trip' => [
                $money('price', 'Price'),
                Tables\Columns\TextColumn::make('price_unit')->label('Per')->formatStateUsing(fn (?string $state) => $state === 'per_trip' ? 'Trip' : 'Person'),
                $money('child_price', 'Child'),
            ],
            'package' => [
                Tables\Columns\TextColumn::make('price_text')->label('Price')->state(fn (Package $p): string => ($u = $p->unitPrice()) !== null ? 'AED '.number_format($u, 2).($p->isPerPerson() ? ' per person' : '') : '-'),
            ],
            default => [
                $money('hourly_price', 'Per hour'),
                $money('daily_price', 'Per day'),
                $money('weekly_price', 'Per week'),
                $money('monthly_price', 'Per month'),
                $money('chauffeur_daily_price', 'With driver / day'),
                $money('chauffeur_hourly_price', 'With driver / hour'),
                $money('deposit', 'Deposit'),
                $money('delivery_fee', 'Delivery'),
                Tables\Columns\TextColumn::make('km')->label('Km / day')
                    ->state(fn (Car $c): string => $c->daily_km_limit ? number_format($c->daily_km_limit).' km, then AED '.number_format((float) $c->extra_km_charge, 2) : 'Unlimited'),
            ],
        };

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record): ?string => $this->specialsText($service, $record)),
                ...$columns,
            ])
            ->defaultSort('name')
            ->paginated([25, 50, 100])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn ($record): ?string => match ($service) {
                        'yacht' => \App\Filament\Resources\YachtResource::getUrl('edit', ['record' => $record]),
                        'trip' => \App\Filament\Resources\TripResource::getUrl('edit', ['record' => $record]),
                        'package' => \App\Filament\Resources\PackageResource::getUrl('edit', ['record' => $record]),
                        default => \App\Filament\Resources\CarResource::getUrl('edit', ['record' => $record]),
                    }),
            ])
            ->emptyStateHeading('Nothing here yet');
    }

    protected function specialsText(string $service, $record): ?string
    {
        $count = PriceRule::query()->active()->where('service_type', $service)
            ->where(fn (Builder $q) => $q->where('applies_to', 'all')
                ->orWhere(fn ($w) => $w->where('applies_to', 'item')->where('item_id', $record->getKey()))
                ->when($record->type ?? null, fn ($w, $type) => $w->orWhere(fn ($c) => $c->where('applies_to', 'category')->where('category', $type))))
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
            ->count();

        return $count ? $count.' special rate'.($count > 1 ? 's' : '') : null;
    }
}
