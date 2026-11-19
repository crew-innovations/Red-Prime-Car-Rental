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

    /** 19 Nov (item 7): missing profile details, completed here before the account opens. */
    public ?array $details = [];

    public static function canAccess(): bool
    {
        return Access::isCustomer();
    }

    public static function canUpload(): bool
    {
        return self::uploadTypes() !== [];
    }

    /** Types the customer can upload: the usual list, plus (19 Nov) any document their bookings still need. */
    public static function uploadTypes(): array
    {
        $types = CustomerArea::allows('customer_can_upload_documents') ? Options::requestableDocumentTypes() : [];

        return CustomerArea::documentsGateOn() ? $types + CustomerArea::documentsStillNeeded() : $types;
    }

    /** 19 Nov: required documents while the account is not open yet (empty = nothing to show). */
    public function gate(): array
    {
        if (! CustomerArea::documentsGateOn() || CustomerArea::unlocked()) {
            return [];
        }

        return CustomerArea::requiredDocuments();
    }

    /** 19 Nov (item 7): details still missing while the account is not open yet. */
    public function profileGate(): array
    {
        return CustomerArea::documentsGateOn() ? CustomerArea::profileStillNeeded() : [];
    }

    public function getTitle(): string
    {
        return CustomerArea::documentsGateOn() && ! CustomerArea::unlocked() ? 'Complete your account' : 'My documents';
    }

    protected function getForms(): array
    {
        return ['form', 'detailsForm'];
    }

    public function mount(): void
    {
        $this->form->fill();
        $this->detailsForm->fill();
    }

    public function detailsForm(Form $form): Form
    {
        $need = fn (string $f) => fn (): bool => array_key_exists($f, $this->profileGate());

        return $form->statePath('details')->columns(2)->schema([
            Forms\Components\TextInput::make('name')->label('Full name (as on your ID)')->maxLength(255)->required()->visible($need('name')),
            Forms\Components\TextInput::make('phone')->label('Mobile number')->tel()->maxLength(30)->required()->visible($need('phone')),
            Forms\Components\TextInput::make('email')->email()->maxLength(255)->required()->visible($need('email')),
            Forms\Components\Select::make('nationality')->options(array_combine(\App\Filament\Resources\CustomerResource::NATIONALITIES, \App\Filament\Resources\CustomerResource::NATIONALITIES))
                ->searchable()->required()->visible($need('nationality')),
            Forms\Components\Select::make('residency')->label('You are')->native(false)->required()->visible($need('residency'))
                ->options(\App\Filament\Concerns\CustomerDetails::RESIDENCY),
            Forms\Components\DatePicker::make('date_of_birth')->label('Date of birth')->native(false)->displayFormat('d M Y')->maxDate(today())->required()->visible($need('date_of_birth')),
            Forms\Components\TextInput::make('address')->maxLength(255)->required()->visible($need('address'))->columnSpanFull(),
            Forms\Components\TextInput::make('id_number')->label('Emirates ID number')->maxLength(40)->visible($need('id_or_passport'))
                ->requiredWithout('passport_number')->helperText('Or your passport number.'),
            Forms\Components\TextInput::make('passport_number')->label('Passport number')->maxLength(40)->visible($need('id_or_passport'))
                ->requiredWithout('id_number'),
            Forms\Components\TextInput::make('licence_number')->label('Driving licence number')->maxLength(40)->required()->visible($need('licence_number')),
            Forms\Components\DatePicker::make('licence_expires_on')->label('Driving licence expiry')->native(false)->displayFormat('d M Y')->minDate(today())->required()->visible($need('licence_expires_on')),
        ]);
    }

    public function saveDetails(): void
    {
        $need = $this->profileGate();
        abort_if($need === [], 403);
        $data = $this->detailsForm->getState();
        $allowed = array_keys($need);
        if (in_array('id_or_passport', $allowed, true)) {
            $allowed = [...$allowed, 'id_number', 'passport_number'];
        }
        $customer = CustomerArea::customer();
        // Only the missing details are filled; nothing already on file is changed from here.
        $fill = collect($data)->only($allowed)->filter(fn ($v, $k) => filled($v) && blank($customer->{$k}))->all();
        if ($fill) {
            $customer->update($fill); // in the audit log with the customer's name
        }
        request()->attributes->remove('rp.required_docs');
        if (CustomerArea::unlocked()) {
            Notification::make()->success()->title('Thank you, your account is ready')->send();
            $this->redirect(Home::getUrl(panel: 'account'));

            return;
        }
        Notification::make()->success()->title('Thank you, your details are saved')
            ->body(CustomerArea::profileStillNeeded() ? 'Please complete the rest.' : 'Now please upload the documents below.')->send();
        $this->detailsForm->fill();
    }

    public function form(Form $form): Form
    {
        return $form->statePath('upload')->schema([
            Forms\Components\Select::make('type')
                ->label('Document')
                ->options(fn (): array => self::uploadTypes())
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
        abort_unless(array_key_exists($data['type'], self::uploadTypes()), 422);
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
            \App\Filament\Resources\DocumentResource::getUrl('index', ['activeTab' => 'review'], panel: 'admin'), $customer->assignee, 'info', event: 'document_uploaded');

        request()->attributes->remove('rp.required_docs');
        $left = CustomerArea::documentsGateOn() ? collect(CustomerArea::requiredDocuments())->filter(fn ($row) => ! $row[2] && $row[1] !== \App\Enums\DocumentStatus::Received->getLabel())->count() : 0;
        Notification::make()->title('Thank you, we received your document')
            ->body($left ? 'Our team will check it. Please upload the other documents too.' : 'Our team will check it. You will get a message when your account is ready.')
            ->success()->send();
        $this->form->fill();
    }

    public function documents()
    {
        return CustomerArea::documents()->latest()->limit(100)->get();
    }
}
