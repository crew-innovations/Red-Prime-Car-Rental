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
 * A driver's own profile: details and documents (read only), the fields the
 * admin lets them update, and their password. Role and other drivers are never reachable.
 */
class MyProfile extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.my-profile';

    protected static ?string $slug = 'my-profile';

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'My profile';

    protected static ?string $title = 'My profile';

    protected static ?int $navigationSort = -19;

    public ?array $details = [];

    public ?array $passwordData = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('Driver');
    }

    protected function getForms(): array
    {
        return ['detailsForm', 'passwordForm'];
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
    }

    public function detailsForm(Form $form): Form
    {
        $fields = self::editableFields();

        return $form->statePath('details')->schema(array_values(array_filter([
            in_array('phone', $fields, true) ? Forms\Components\TextInput::make('phone')->label('Phone number')->tel()->required()->maxLength(30) : null,
            in_array('address', $fields, true) ? Forms\Components\Textarea::make('address')->label('Residential address')->rows(2)->maxLength(500) : null,
            in_array('emergency_name', $fields, true) ? Forms\Components\TextInput::make('emergency_name')->label('Emergency contact name')->maxLength(120) : null,
            in_array('emergency_phone', $fields, true) ? Forms\Components\TextInput::make('emergency_phone')->label('Emergency contact phone')->tel()->maxLength(30) : null,
            in_array('photo', $fields, true) ? Forms\Components\FileUpload::make('photo')->label('New profile photo')->image()->avatar()->disk('private')->directory('tmp')->visibility('private')->maxSize(5120) : null,
        ])));
    }

    public function passwordForm(Form $form): Form
    {
        return $form->statePath('passwordData')->schema([
            Forms\Components\TextInput::make('current_password')->label('Current password')->password()->required()->currentPassword(),
            Forms\Components\TextInput::make('password')->label('New password')->password()->revealable()->required()
                ->rule(Password::min(8)->letters()->numbers())->confirmed(),
            Forms\Components\TextInput::make('password_confirmation')->label('New password again')->password()->required(),
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
            $profile->addMediaFromDisk(is_array($data['photo']) ? array_values($data['photo'])[0] : $data['photo'], 'private')->toMediaCollection('photo');
        }

        Notification::make()->title('Your details are saved')->success()->send();
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

        Notification::make()->title('Password changed')->success()->send();
        $this->passwordForm->fill();
    }

    public function problems(): array
    {
        return DriverPapers::problems(auth()->user());
    }

    public function documents()
    {
        return auth()->user()->driverDocuments()->current()->orderBy('type')->get();
    }
}
