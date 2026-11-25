<?php

namespace App\Filament\Support;

use App\Enums\DocumentStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\DocumentRequest;
use App\Models\Setting;
use App\Support\Phone;
use App\Support\Uploads\CameraUpload;
use Filament\Forms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;

/** Document actions shared by the customer page and the booking page. */
class DocumentActions
{
    /**
     * "Request documents": creates a secure upload link and offers to send it on WhatsApp.
     *
     * @param  callable(): ?Customer  $customer
     * @param  callable(): ?Booking  $booking
     */
    public static function requestLink(string $class, callable $customer, callable $booking)
    {
        return $class::make('requestDocuments')
            ->label('Request documents')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->authorize('review-documents')
            ->modalWidth('lg')
            ->modalDescription('Creates a private upload link for the customer. No account or app needed.')
            ->fillForm(function () use ($booking) {
                $b = $booking();
                $types = $b?->requiredDocumentTypes() ?: [];

                return [
                    'types' => $types,
                    'hours' => (int) Setting::read('upload_link_hours', 72),
                ];
            })
            ->form([
                Forms\Components\CheckboxList::make('types')
                    ->label('Documents to upload')
                    ->options(fn (): array => \App\Support\Options::requestableDocumentTypes())
                    ->columns(2)
                    ->required(),
                Forms\Components\TextInput::make('hours')
                    ->label('Link works for')
                    ->numeric()->minValue(1)->maxValue(720)->suffix('hours')
                    ->required(),
            ])
            ->action(function (array $data) use ($customer, $booking) {
                $c = $customer();
                if (! $c) {
                    Notification::make()->title('Add the customer first')->danger()->send();

                    return;
                }

                $request = DocumentRequest::issue($c, $data['types'], (int) $data['hours'], $booking()?->id);
                $url = $request->url();
                $message = \App\Support\Options::message('msg_upload', ['name' => $c->name, 'link' => $url]);

                Notification::make()
                    ->title('Upload link ready')
                    ->body('Valid until '.$request->expires_at->format('d M, H:i').'. Link: '.$url)
                    ->success()
                    ->persistent()
                    ->actions(array_filter([
                        ($wa = Phone::whatsappUrl($c->whatsapp ?: $c->phone, $message))
                            ? NotificationAction::make('whatsapp')->label('Send on WhatsApp')->button()->url($wa, shouldOpenInNewTab: true)
                            : null,
                        NotificationAction::make('open')->label('Open link')->url($url, shouldOpenInNewTab: true),
                    ]))
                    ->send();
            });
    }

    /** Staff upload of a document they received another way (email, WhatsApp, at the desk). */
    public static function uploadForm(?int $customerId = null): array
    {
        return [
            Forms\Components\Select::make('type')
                ->options(fn (): array => \App\Support\Options::documentTypes())
                ->required(),
            Forms\Components\DatePicker::make('expires_on')
                ->label('Expiry date')
                ->native(false)
                ->displayFormat('d M Y'),
            ...CameraUpload::withScan(
                Forms\Components\FileUpload::make('path')
                    ->label('File')
                    ->disk('private')
                    ->directory(fn () => 'customers/'.($customerId ?? 'misc'))
                    ->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                    ->maxSize(10240)
                    ->storeFileNamesIn('original_name')
                    ->required()
                    ->columnSpanFull(),
                'path'
            ),
            Forms\Components\ToggleButtons::make('status')
                ->options([DocumentStatus::Approved->value => 'Checked and approved', DocumentStatus::Received->value => 'Review later'])
                ->colors([DocumentStatus::Approved->value => 'success', DocumentStatus::Received->value => 'info'])
                ->default(DocumentStatus::Approved->value)
                ->inline()
                ->required(),
            Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
        ];
    }

    /** Fill in the review fields for a staff upload. */
    public static function staffUploadData(array $data): array
    {
        $data['uploaded_via'] = 'staff';
        $data['uploaded_by'] = auth()->id();
        if (($data['status'] ?? null) === DocumentStatus::Approved->value) {
            $data['reviewed_by'] = auth()->id();
            $data['reviewed_at'] = now();
        }

        return $data;
    }

    /** Open, approve and reject: used on the customer page and the Documents list. */
    public static function reviewActions(): array
    {
        return [
            TableAction::make('view')
                ->iconButton()
                ->icon('heroicon-o-eye')
                ->tooltip('Open file')
                ->url(fn (CustomerDocument $record): ?string => $record->viewUrl(), shouldOpenInNewTab: true),
            TableAction::make('approve')
                ->iconButton()
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->tooltip('Approve')
                ->authorize('review-documents')
                ->form([
                    Forms\Components\DatePicker::make('expires_on')->label('Expiry date (if any)')->native(false)->displayFormat('d M Y'),
                ])
                ->fillForm(fn (CustomerDocument $record): array => ['expires_on' => $record->expires_on])
                ->modalWidth('sm')
                ->visible(fn (CustomerDocument $record): bool => $record->status !== DocumentStatus::Approved)
                ->action(function (CustomerDocument $record, array $data) {
                    $record->update(['expires_on' => $data['expires_on'] ?? null]);
                    $record->review(DocumentStatus::Approved);
                    Notification::make()->title('Document approved')->success()->send();
                }),
            TableAction::make('reject')
                ->iconButton()
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->tooltip('Reject or ask for a new one')
                ->authorize('review-documents')
                ->modalWidth('md')
                ->form([
                    Forms\Components\ToggleButtons::make('status')
                        ->hiddenLabel()
                        ->options([DocumentStatus::Replacement->value => 'Ask for a new upload', DocumentStatus::Rejected->value => 'Reject'])
                        ->default(DocumentStatus::Replacement->value)
                        ->inline()
                        ->required(),
                    Forms\Components\TextInput::make('note')
                        ->label('Reason')
                        ->datalist(\App\Support\Options::list('doc_reject_reasons'))
                        ->required(),
                ])
                ->visible(fn (CustomerDocument $record): bool => in_array($record->status, [DocumentStatus::Received, DocumentStatus::Approved], true))
                ->action(function (CustomerDocument $record, array $data) {
                    $record->review(DocumentStatus::from($data['status']), $data['note']);
                    Notification::make()->title('Saved. Send the customer a new upload link if needed.')->success()->send();
                }),
        ];
    }

    public static function statusSummary(CustomerDocument $d): string
    {
        return $d->status->getLabel().($d->review_note ? ' · '.$d->review_note : '');
    }
}
