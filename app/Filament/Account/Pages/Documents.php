<?php

namespace App\Filament\Account\Pages;

use App\Enums\DocumentStatus;
use App\Models\CustomerDocument;
use App\Support\Access;
use App\Support\CustomerArea;
use App\Support\Drivers\Notify;
use App\Support\Options;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** The customer's documents with their review status, and an upload form. */
class Documents extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'account.documents';

    protected static ?string $slug = 'documents';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Documents';

    protected static ?string $title = 'My documents';

    protected static ?int $navigationSort = 3;

    public ?array $upload = [];

    public static function canAccess(): bool
    {
        return Access::isCustomer();
    }

    public static function canUpload(): bool
    {
        return CustomerArea::allows('customer_can_upload_documents') && Options::requestableDocumentTypes() !== [];
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->statePath('upload')->schema([
            Forms\Components\Select::make('type')
                ->label('Document')
                ->options(fn (): array => Options::requestableDocumentTypes())
                ->native(false)
                ->required(),
            Forms\Components\FileUpload::make('path')
                ->label('Photo or PDF (max 10 MB)')
                ->disk('private')
                ->directory(fn (): string => 'customers/'.CustomerArea::customerId())
                ->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->maxSize(10240)
                ->storeFileNamesIn('original_name')
                ->required(),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::canUpload(), 403);
        $data = $this->form->getState();
        abort_unless(array_key_exists($data['type'], Options::requestableDocumentTypes()), 422);
        // Only a file just uploaded into this customer's own folder is accepted.
        abort_unless(\App\Support\PrivateFiles::freshUploads([(string) $data['path']], 'customers/'.CustomerArea::customerId()) !== [], 422);

        $doc = CustomerDocument::create([
            'customer_id' => CustomerArea::customerId(),
            'type' => $data['type'],
            'status' => DocumentStatus::Received,
            'path' => $data['path'],
            'original_name' => mb_substr((string) ($data['original_name'] ?? ''), 0, 200) ?: null,
            'uploaded_via' => 'account',
        ]);

        $customer = CustomerArea::customer();
        Notify::staff('review-documents', 'New document from '.$customer->name, Options::documentLabel($doc->type).' uploaded from their account.',
            \App\Filament\Resources\DocumentResource::getUrl('index', ['activeTab' => 'review'], panel: 'admin'), $customer->assignee, 'info');

        Notification::make()->title('Thank you, we received your document')->body('Our team will check it.')->success()->send();
        $this->form->fill();
    }

    public function documents()
    {
        return CustomerArea::documents()->latest()->limit(100)->get();
    }
}
