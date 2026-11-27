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
    use \App\Filament\Concerns\HasNextStep;

    use GuardsSave {
        save as protected guardedSave;
        getFormActions as protected guardedFormActions;
    }

    protected static string $resource = QuotationResource::class;

    public function getTitle(): string
    {
        return 'Quotation '.$this->getRecord()->number;
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return $this->progressBar($this->detailsLine()); // 6 Nov (S4): progress bar + the details line
    }

    protected function detailsLine(): ?string
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
        return $this->withNextStep($this->pageActions()); // 6 Nov (S4): one big next step, the rest under "More"
    }

    /** Every button of this page (the next step is picked from these). */
    protected function pageActions(): array
    {
        $after = fn () => $this->redirect(QuotationResource::getUrl('edit', ['record' => $this->getRecord()]));

        $workflow = array_map(
            fn ($action) => in_array($action->getName(), ['convert', 'duplicate', 'preview'], true) ? $action : $action->after($after),
            QuotationResource::workflowActions(Actions\Action::class),
        );
        // The first two (send, convert) stay as buttons; the rest go in the menu.
        $buttons = array_values(array_filter($workflow, fn ($a) => in_array($a->getName(), ['approvePrice', 'rejectPrice', 'send', 'accept', 'convert'], true)));
        $menu = array_values(array_filter($workflow, fn ($a) => ! in_array($a->getName(), ['approvePrice', 'rejectPrice', 'send', 'accept', 'convert'], true)));

        return [
            ...$buttons,
            Actions\ActionGroup::make([
                ...$menu,
                \App\Filament\Support\EmailActions::send(Actions\Action::class),
                \App\Filament\Resources\PaymentRequestResource::linkAction(Actions\Action::class, fn () => $this->getRecord())
                    ->visible(fn (): bool => $this->getRecord()->status === \App\Enums\QuotationStatus::Accepted && ! $this->getRecord()->booking_id),
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
