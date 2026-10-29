<?php

namespace App\Filament\Resources\SupplierResource\RelationManagers;

use App\Models\Car;
use App\Models\SupplierRate;
use App\Models\Yacht;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Cost rates: what this supplier charges, for one vehicle or as their default, with dates. */
class RatesRelationManager extends RelationManager
{
    protected static string $relationship = 'rates';

    protected static ?string $title = 'Cost rates';

    protected static ?string $icon = 'heroicon-o-calculator';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::allows('manage-suppliers');
    }

    protected function vehicleOptions(): array
    {
        $s = $this->getOwnerRecord();

        return Car::query()->where('supplier_id', $s->id)->orderBy('name')->get()->mapWithKeys(fn ($c) => ['car:'.$c->id => $c->name.' (car)'])
            ->merge(Yacht::query()->where('supplier_id', $s->id)->orderBy('name')->get()->mapWithKeys(fn ($y) => ['yacht:'.$y->id => $y->name.' (yacht)']))
            ->all();
    }

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\Select::make('vehicle')->label('For')->placeholder('All this supplier\'s vehicles (default)')
                ->options(fn () => $this->vehicleOptions())
                ->helperText('A rate for one vehicle wins over the default.')
                ->afterStateHydrated(fn ($component, ?SupplierRate $record) => $component->state($record?->vehicle_id ? $record->vehicle_type.':'.$record->vehicle_id : null)),
            Forms\Components\Select::make('method')->label('Charged')->options(SupplierRate::METHODS)->required()->live()->native(false),
            Forms\Components\TextInput::make('amount')->numeric()->required()->minValue(0.01)
                ->prefix(fn (Forms\Get $get): ?string => $get('method') === 'percent' ? null : 'AED')
                ->suffix(fn (Forms\Get $get): ?string => $get('method') === 'percent' ? '%' : null),
            Forms\Components\ToggleButtons::make('partial')->label('A part week / month')->inline()
                ->options(['pro_rata' => 'Pro rata', 'full' => 'Counts in full'])->default('pro_rata')
                ->visible(fn (Forms\Get $get): bool => in_array($get('method'), ['per_week', 'per_month'], true)),
            Forms\Components\DatePicker::make('starts_on')->label('From')->helperText('Empty = from the start.'),
            Forms\Components\DatePicker::make('ends_on')->label('Until')->helperText('Empty = no end.'),
            Forms\Components\Toggle::make('is_active')->label('In use')->default(true)->inline(false),
            Forms\Components\TextInput::make('notes')->maxLength(255),
        ]);
    }

    protected function withVehicle(array $data): array
    {
        [$type, $id] = array_pad(explode(':', (string) ($data['vehicle'] ?? '')), 2, null);
        unset($data['vehicle']);
        $data['vehicle_type'] = in_array($type, ['car', 'yacht'], true) ? $type : null;
        $data['vehicle_id'] = $data['vehicle_type'] ? (int) $id : null;
        $data['partial'] = $data['partial'] ?? 'pro_rata';

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('for')->label('For')->state(fn (SupplierRate $r): string => $r->vehicleModel()?->name ?? 'Default (all vehicles)')
                    ->weight(fn (SupplierRate $r) => $r->vehicle_id ? 'semibold' : null),
                Tables\Columns\TextColumn::make('label')->label('Rate')->state(fn (SupplierRate $r): string => $r->label()),
                Tables\Columns\TextColumn::make('starts_on')->label('From')->date('d M Y')->placeholder('Start'),
                Tables\Columns\TextColumn::make('ends_on')->label('Until')->date('d M Y')->placeholder('No end'),
                Tables\Columns\IconColumn::make('is_active')->label('In use')->boolean(),
                Tables\Columns\TextColumn::make('used')->label('Used')->state(fn (SupplierRate $r): string => $r->isUsed() ? 'Yes, locked' : 'No'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Add cost rate')->authorize('manage-suppliers')
                    ->mutateFormDataUsing(fn (array $data): array => $this->withVehicle($data)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->authorize('manage-suppliers')
                    ->mutateFormDataUsing(fn (array $data): array => $this->withVehicle($data)),
                Tables\Actions\Action::make('end')->label('End it')->icon('heroicon-o-calendar')->color('gray')->authorize('manage-suppliers')
                    ->visible(fn (SupplierRate $r): bool => $r->is_active && ! $r->ends_on)
                    ->form([Forms\Components\DatePicker::make('ends_on')->label('Last day')->default(today())->required()])
                    ->action(fn (SupplierRate $r, array $data) => $r->update(['ends_on' => $data['ends_on']])),
            ])
            ->emptyStateHeading('No cost rates yet')
            ->emptyStateDescription('Without a rate, bookings with this supplier\'s vehicles can\'t be confirmed. Add a default, or one per vehicle.');
    }
}
