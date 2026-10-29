<?php

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Filament\Resources\SupplierResource;
use App\Models\Car;
use App\Models\Supplier;
use App\Models\Yacht;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        $unlinked = fn () => Car::query()->where('ownership', 'partner')->whereNull('supplier_id')->whereNotNull('partner_name')->count()
            + Yacht::query()->where('ownership', 'partner')->whereNull('supplier_id')->whereNotNull('partner_name')->count();

        return [
            \App\Support\Exports\TableExport::action(),
            // One-off help for cars / yachts that only have the old free-text partner name.
            Actions\Action::make('fromPartnerNames')
                ->label('Make suppliers from partner names')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn (): bool => $unlinked() > 0)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $unlinked().' supplier vehicle(s) only have a partner name. A supplier is made for each name (same name = same supplier) and linked. No cost rates are added: set them on each supplier.')
                ->action(function () {
                    $made = 0;
                    foreach ([Car::class, Yacht::class] as $class) {
                        foreach ($class::query()->where('ownership', 'partner')->whereNull('supplier_id')->whereNotNull('partner_name')->get() as $v) {
                            $name = trim((string) $v->partner_name);
                            if ($name === '') {
                                continue;
                            }
                            $s = Supplier::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
                            if (! $s) {
                                $s = Supplier::create(['name' => $name, 'type' => $class === Yacht::class ? 'yacht_owner' : 'car_owner', 'status' => 'active']);
                                $made++;
                            }
                            $v->forceFill(['supplier_id' => $s->id])->save();
                        }
                    }
                    Notification::make()->title($made.' supplier(s) made and linked')->body('Now add each supplier\'s cost rates.')->success()->send();
                }),
            Actions\CreateAction::make()->label('Add supplier'),
        ];
    }
}
