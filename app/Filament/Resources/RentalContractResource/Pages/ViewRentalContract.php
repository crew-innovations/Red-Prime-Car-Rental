<?php

namespace App\Filament\Resources\RentalContractResource\Pages;

use App\Filament\Resources\RentalContractResource;
use App\Filament\Resources\RentalContractResource\ContractActions;
use App\Models\RentalContract;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRentalContract extends ViewRecord
{
    protected static string $resource = RentalContractResource::class;

    public function getTitle(): string
    {
        return 'Contract '.$this->getRecord()->number;
    }

    public function getSubheading(): ?string
    {
        /** @var RentalContract $c */
        $c = $this->getRecord();

        return collect([$c->customer?->name, $c->booking?->bookable?->name, $c->statusLabel(), $c->typeLabel(), 'v'.$c->version])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        $after = fn () => $this->redirect(RentalContractResource::getUrl('view', ['record' => $this->getRecord()]));
        $all = ContractActions::forContract(fn () => $this->getRecord()->fresh(), $after);
        $main = ['signContract', 'closeContract'];

        return [
            Actions\Action::make('pdf')->label('Contract PDF')->icon('heroicon-o-document-arrow-down')->color('gray')
                ->url(fn (): string => route('pdf.contract', $this->getRecord()), true),
            ...array_values(array_filter($all, fn ($a) => in_array($a->getName(), $main, true))),
            Actions\ActionGroup::make(array_values(array_filter($all, fn ($a) => ! in_array($a->getName(), $main, true))))
                ->label('More')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
            Actions\DeleteAction::make()->visible(fn (): bool => RentalContractResource::canDelete($this->getRecord())),
        ];
    }
}
