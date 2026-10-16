<?php

namespace App\Filament\Resources\QuotationResource\Pages;

use App\Filament\Concerns\GuardsSave;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\QuotationResource;
use App\Models\Quotation;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditQuotation extends EditRecord
{
    use GuardsSave {
        save as protected guardedSave;
        getFormActions as protected guardedFormActions;
    }

    protected static string $resource = QuotationResource::class;

    public function getTitle(): string
    {
        return 'Quotation '.$this->getRecord()->number;
    }

    public function getSubheading(): ?string
    {
        /** @var Quotation $q */
        $q = $this->getRecord();

        return collect([$q->customerName(), $q->bookable?->name, $q->status?->getLabel()])->filter()->implode(' · ');
    }

    /** A sent / accepted quote cannot be changed from the form. */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        abort_if(QuotationResource::isLocked($this->getRecord()), 403);

        $this->guardedSave($shouldRedirect, $shouldSendSavedNotification);
    }

    protected function getFormActions(): array
    {
        return QuotationResource::isLocked($this->getRecord())
            ? [$this->getCancelFormAction()->label('Back')]
            : $this->guardedFormActions();
    }

    protected function getHeaderActions(): array
    {
        $after = fn () => $this->redirect(QuotationResource::getUrl('edit', ['record' => $this->getRecord()]));

        $workflow = array_map(
            fn ($action) => in_array($action->getName(), ['convert', 'duplicate', 'preview'], true) ? $action : $action->after($after),
            QuotationResource::workflowActions(Actions\Action::class),
        );
        // The first two (send, convert) stay as buttons; the rest go in the menu.
        $buttons = array_values(array_filter($workflow, fn ($a) => in_array($a->getName(), ['send', 'accept', 'convert'], true)));
        $menu = array_values(array_filter($workflow, fn ($a) => ! in_array($a->getName(), ['send', 'accept', 'convert'], true)));

        return [
            ...$buttons,
            Actions\ActionGroup::make([
                ...$menu,
                Actions\Action::make('customer')
                    ->label('Open customer')
                    ->icon('heroicon-o-user')
                    ->visible(fn (): bool => (bool) $this->getRecord()->customer_id)
                    ->url(fn (): ?string => $this->getRecord()->customer_id ? CustomerResource::getUrl('edit', ['record' => $this->getRecord()->customer_id]) : null),
                Actions\Action::make('lead')
                    ->label('Open lead')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->visible(fn (): bool => (bool) $this->getRecord()->lead_id)
                    ->url(fn (): ?string => $this->getRecord()->lead_id ? LeadResource::getUrl('edit', ['record' => $this->getRecord()->lead_id]) : null),
                Actions\DeleteAction::make(),
                Actions\RestoreAction::make(),
            ]),
        ];
    }
}
