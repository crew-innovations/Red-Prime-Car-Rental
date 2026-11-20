<?php

namespace App\Filament\Pages;

use App\Models\DriverProfile;
use App\Models\Setting;
use App\Support\Drivers\DriverPapers;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\Rules\Password;

/**
 * A driver's own profile: details, documents, the fields the admin lets them update, and their
 * password. Role and other drivers are never reachable.
 * 20 Nov (Bilal, item 8): drivers upload their own documents here; the office approves or rejects them.
 */
class MyProfile extends Page implements HasForms
{
    use \App\Filament\Concerns\DriverLocale, InteractsWithForms;

    protected static string $view = 'filament.pages.my-profile';

    protected static ?string $slug = 'my-profile';

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'My profile';

    protected static ?string $title = 'My profile';

    protected static ?int $navigationSort = -19;

    public ?array $details = [];

    public ?array $passwordData = [];

    public ?array $docData = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('Driver');
    }

    public static function getNavigationLabel(): string
    {
        \App\Support\Drivers\DriverLang::apply(auth()->user());

        return __('driver.my_profile');
    }

    public function getTitle(): string
    {
        return __('driver.my_profile');
    }

    /** 4 Nov (15): the driver screens in English, Urdu or Arabic. */
    public function setLanguage(string $lang): void
    {
        abort_unless(array_key_exists($lang, \App\Support\Drivers\DriverLang::available()), 422);
        auth()->user()->forceFill(['locale' => $lang])->save();
        $this->redirect(static::getUrl());
    }

    protected function getForms(): array
    {
        return ['detailsForm', 'passwordForm', 'docForm'];
    }

    public static function editableFields(): array
    {
        return Setting::read('driver_self_service')
            ? array_values(array_intersect((array) Setting::read('driver_editable_fields', []), array_keys(DriverProfile::SELF_SERVICE_FIELDS)))
            : [];
    }

    public function mount(): void
    {
        $user = auth()->user();
        $profile = $user->driverProfile;
        $this->detailsForm->fill([
            'phone' => $user->phone,
            'address' => $profile?->address,
            'emergency_name' => $profile?->emergency_name,
            'emergency_phone' => $profile?->emergency_phone,
        ]);
        $this->passwordForm->fill();
        $this->docForm->fill();
    }

    public function detailsForm(Form $form): Form
    {
        $fields = self::editableFields();

        return $form->statePath('details')->schema(array_values(array_filter([
            in_array('phone', $fields, true) ? Forms\Components\TextInput::make('phone')->label(__('driver.phone'))->tel()->required()->maxLength(30) : null,
            in_array('address', $fields, true) ? Forms\Components\Textarea::make('address')->label(__('driver.address'))->rows(2)->maxLength(500) : null,
            in_array('emergency_name', $fields, true) ? Forms\Components\TextInput::make('emergency_name')->label(__('driver.emergency_name'))->maxLength(120) : null,
            in_array('emergency_phone', $fields, true) ? Forms\Components\TextInput::make('emergency_phone')->label(__('driver.emergency_phone'))->tel()->maxLength(30) : null,
            in_array('photo', $fields, true) ? Forms\Components\FileUpload::make('photo')->label(__('driver.new_photo'))->image()->avatar()->disk('private')->directory('tmp')->visibility('private')->maxSize(5120) : null,
        ])));
    }

    /** Required types first (Business settings), then the others. */
    public static function uploadTypes(): array
    {
        $required = array_intersect_key(\App\Models\DriverDocument::TYPES, array_flip((array) Setting::read('driver_required_documents', [])));

        return $required + \App\Models\DriverDocument::TYPES;
    }

    public function docForm(Form $form): Form
    {
        return $form->statePath('docData')->columns(2)->schema([
            Forms\Components\Select::make('type')->label(__('driver.doc_type'))->options(self::uploadTypes())->native(false)->required()->live(),
            Forms\Components\TextInput::make('label')->label(__('driver.doc_name'))->maxLength(120)
                ->required(fn (Forms\Get $get): bool => $get('type') === 'other')
                ->visible(fn (Forms\Get $get): bool => in_array($get('type'), ['other', 'permit'], true)),
            Forms\Components\TextInput::make('number')->label(__('driver.doc_number'))->maxLength(60),
            Forms\Components\DatePicker::make('expires_on')->label(__('driver.doc_expiry'))->native(false)->displayFormat('d M Y'),
            Forms\Components\FileUpload::make('path')->label(__('driver.doc_file'))->required()
                ->disk('private')->directory(fn (): string => 'drivers/'.auth()->id())->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])->maxSize(10240)
                ->storeFileNamesIn('original_name')->columnSpanFull(),
        ]);
    }

    public function saveDocument(): void
    {
        $user = auth()->user();
        abort_unless($user?->hasRole('Driver'), 403);
        $data = $this->docForm->getState();
        abort_unless(array_key_exists($data['type'] ?? '', \App\Models\DriverDocument::TYPES), 422);

        $doc = $user->driverDocuments()->create([
            'type' => $data['type'],
            'label' => $data['label'] ?? null,
            'number' => $data['number'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'path' => $data['path'],
            'original_name' => $data['original_name'] ?? null,
            'uploaded_by' => $user->id,
            'status' => 'pending', // the office checks it; an older approved copy stays valid until then
        ]);
        \App\Support\Drivers\Notify::staff('manage-drivers', 'Driver document to check: '.$user->name,
            $doc->typeLabel().' uploaded by the driver. Approve or reject it.',
            \App\Filament\Resources\DriverResource::getUrl('edit', ['record' => $user]).'?activeRelationManager=0',
            null, 'warning', 'other', 'driver-doc:'.$doc->id);

        Notification::make()->title(__('driver.doc_sent'))->body(__('driver.doc_sent_body'))->success()->send();
        $this->docForm->fill();
    }

    public function checklist(): array
    {
        return DriverPapers::checklist(auth()->user());
    }

    public function passwordForm(Form $form): Form
    {
        return $form->statePath('passwordData')->schema([
            Forms\Components\TextInput::make('current_password')->label(__('driver.current_password'))->password()->required()->currentPassword(),
            Forms\Components\TextInput::make('password')->label(__('driver.new_password'))->password()->revealable()->required()
                ->rule(Password::min(8)->letters()->numbers())->confirmed(),
            Forms\Components\TextInput::make('password_confirmation')->label(__('driver.new_password_again'))->password()->required(),
        ]);
    }

    public function saveDetails(): void
    {
        $fields = self::editableFields();
        abort_if($fields === [], 403);

        $data = $this->detailsForm->getState();
        $user = auth()->user();
        if (in_array('phone', $fields, true) && filled($data['phone'] ?? null)) {
            $user->update(['phone' => $data['phone']]);
        }
        $profileData = collect($data)->only(array_intersect($fields, ['address', 'emergency_name', 'emergency_phone']))->all();
        $profile = $user->driverProfile()->firstOrCreate([]);
        if ($profileData) {
            $profile->update($profileData);
        }
        if (in_array('photo', $fields, true) && filled($data['photo'] ?? null)) {
            foreach (\App\Support\PrivateFiles::freshUploads((array) $data['photo'], 'tmp') as $path) {
                $profile->addMediaFromDisk($path, 'private')->toMediaCollection('photo');
            }
        }

        Notification::make()->title(__('driver.details_saved'))->success()->send();
        $this->mount();
    }

    public function savePassword(): void
    {
        $data = $this->passwordForm->getState();
        $user = auth()->user();
        $user->update(['password' => $data['password']]);
        // Keep this browser signed in after the password change.
        if (request()->hasSession()) {
            request()->session()->put('password_hash_'.auth()->getDefaultDriver(), $user->getAuthPassword());
        }
        \App\Models\AuditLog::note($user, 'password', 'Password changed by the driver');

        Notification::make()->title(__('driver.password_changed'))->success()->send();
        $this->passwordForm->fill();
    }

    public function problems(): array
    {
        return DriverPapers::problems(auth()->user());
    }

    public function documents()
    {
        return auth()->user()->driverDocuments()->current()->orderBy('type')->orderByDesc('id')->get();
    }
}
