<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\CrmMessage;
use App\Notifications\ManualEmail;
use App\Support\Access;
use App\Support\Notifications\Notifier;
use App\Support\Phone;
use App\Support\Push\Apps;
use App\Support\Push\Vapid;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Settings › Apps (4 Nov, brief 16): the three installable apps (driver, staff, customer) —
 * name, icon, colours, start page — with install links, QR codes, and which drivers have the
 * app and notifications switched on.
 */
class AppSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Apps';

    protected static ?string $title = 'Apps';

    protected static ?string $slug = 'apps';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.app-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-apps');
    }

    public function getSubheading(): ?string
    {
        return 'The CRM installs on phones as three apps with their own icon. Changes reach installed apps the next time they open.';
    }

    public function mount(): void
    {
        $fill = [];
        foreach (Apps::KEYS as $app) {
            $c = Apps::get($app);
            $fill[$app] = ['name' => $c['name'], 'short_name' => $c['short_name'], 'theme' => $c['theme'], 'background' => $c['background'], 'start' => $c['start'], 'icon' => $c['icon'] ?? null];
        }
        $this->form->fill($fill);
    }

    public function form(Form $form): Form
    {
        $tabs = [];
        foreach (['driver' => 'Driver app', 'staff' => 'Staff app', 'customer' => 'Customer app'] as $app => $label) {
            $scope = Apps::defaults()[$app]['scope'];
            $tabs[] = Forms\Components\Tabs\Tab::make($label)->schema([
                Forms\Components\Grid::make(2)->statePath($app)->schema([
                    Forms\Components\TextInput::make('name')->label('App name')->required()->maxLength(45),
                    Forms\Components\TextInput::make('short_name')->label('Name under the icon')->required()->maxLength(12)->helperText('Up to 12 letters.'),
                    Forms\Components\FileUpload::make('icon')->label('Icon')->image()->imageEditor()->imageCropAspectRatio('1:1')
                        ->disk('uploads')->directory('pwa/source')->visibility('public')->maxSize(4096)->acceptedFileTypes(['image/png', 'image/jpeg'])
                        ->helperText('A square PNG or JPG, at least 512 × 512. Empty = the logo on the background colour.'),
                    Forms\Components\Grid::make(1)->columnSpan(1)->schema([
                        Forms\Components\ColorPicker::make('theme')->label('Top bar colour')->required()->regex('/^#[0-9a-fA-F]{6}$/'),
                        Forms\Components\ColorPicker::make('background')->label('Background while it opens')->required()->regex('/^#[0-9a-fA-F]{6}$/'),
                        Forms\Components\TextInput::make('start')->label('Opens on')->required()->maxLength(120)
                            ->rule(fn () => function ($attr, $value, $fail) use ($scope) {
                                if (! str_starts_with((string) $value.'/', $scope) && ! str_starts_with((string) $value, $scope)) {
                                    $fail('The page must be under '.$scope);
                                }
                            })
                            ->helperText('A page under '.$scope),
                    ]),
                ]),
                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('publish_'.$app)->label('Save & publish '.$label)->icon('heroicon-o-arrow-up-tray')
                        ->action(fn () => $this->publish($app)),
                ]),
            ]);
        }

        return $form->statePath('data')->schema([Forms\Components\Tabs::make('apps')->tabs($tabs)->persistTabInQueryString('app')]);
    }

    public function publish(string $app): void
    {
        abort_unless(static::canAccess() && in_array($app, Apps::KEYS, true), 403);
        $state = $this->form->getState()[$app] ?? [];
        $icon = is_array($state['icon'] ?? null) ? collect($state['icon'])->first() : ($state['icon'] ?? null);
        $cfg = Apps::publish($app, [
            'name' => trim((string) $state['name']), 'short_name' => trim((string) $state['short_name']),
            'theme' => $state['theme'], 'background' => $state['background'], 'start' => '/'.ltrim((string) $state['start'], '/'), 'icon' => $icon,
        ]);
        AuditLog::note(auth()->user(), 'apps', ucfirst($app).' app published (version '.$cfg['version'].')');
        Notification::make()->success()->title($cfg['name'].' published')->body('Version '.$cfg['version'].'. Installed apps update the next time they open.')->send();
        $this->mount();
    }

    public function apps(): array
    {
        return collect(Apps::KEYS)->mapWithKeys(fn ($k) => [$k => Apps::get($k) + [
            'install' => Apps::installUrl($k),
            'qr' => \App\Support\Documents\Pdf::qr(Apps::installUrl($k)),
            'icon_url' => Apps::iconUrl($k, 'icon-192.png'),
            'devices' => UserDevice::query()->where('app', $k)->count(),
            'installed' => UserDevice::query()->where('app', $k)->where('installed', true)->count(),
        ]])->all();
    }

    /** @return Collection<int, User> drivers with their devices */
    public function drivers(): Collection
    {
        return User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'Driver'))
            ->with(['devices' => fn ($q) => $q->orderByDesc('last_seen_at')])->orderBy('name')->get();
    }

    public static function driverStatus(User $u): array
    {
        $d = $u->devices;

        return [
            'installed' => $d->contains(fn ($x) => $x->installed),
            'push' => $d->contains(fn ($x) => $x->canPush()),
            'blocked' => $d->isNotEmpty() && $d->every(fn ($x) => $x->permission === 'denied'),
            'last_seen' => $d->max('last_seen_at'),
            'version' => $d->first()?->app_version,
        ];
    }

    /** Drivers whose notifications are blocked or never switched on (for the dashboard warning). */
    public static function driversWithoutPush(): int
    {
        return User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'Driver'))
            ->whereDoesntHave('devices', fn ($q) => $q->where('permission', 'granted')->whereNotNull('push_endpoint'))->count();
    }

    public function whatsappLink(User $u): ?string
    {
        $text = 'Hi '.strtok($u->name, ' ').', please install the RED PRIME Driver app on your phone: '.Apps::installUrl('driver');

        return rescue(fn () => Phone::whatsappUrl($u->phone, $text), null, false);
    }

    public function testDriver(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $u = User::findOrFail($id);
        $n = new CrmMessage('Test notification from the office', 'If you see this, notifications work on your phone.', null, false, 'heroicon-o-bell', 'success', 'other');
        $n->channels = ['database', 'push'];
        Notifier::deliverNow($u, $n);
        Notification::make()->title('Test sent to '.$u->name)->body($u->devices()->whereNotNull('push_endpoint')->exists() ? 'It should arrive on their phone in a few seconds.' : 'They have no device with notifications on, so it only shows under their bell.')->success()->send();
    }

    public function removeDevice(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $d = UserDevice::findOrFail($id);
        if ($d->session_id && config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('id', $d->session_id)->delete(); // signs that phone out
        }
        $d->delete();
        AuditLog::note($d->user ?? auth()->user(), 'apps', 'Device removed by '.auth()->user()->name.': '.($d->label ?: 'device').' (signed out, no more notifications)');
        Notification::make()->title('Device removed and signed out')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendInstall')->label('Send install link')->icon('heroicon-o-paper-airplane')
                ->form([
                    Forms\Components\Select::make('drivers')->label('Drivers')->multiple()->required()->native(false)
                        ->options(fn () => $this->drivers()->filter(fn ($u) => filled($u->email))->pluck('name', 'id')->all())
                        ->helperText('They get an email with the install link and the steps for iPhone and Android.'),
                ])
                ->action(function (array $data) {
                    $sent = 0;
                    foreach (User::whereIn('id', (array) $data['drivers'])->get() as $u) {
                        $body = "Hi {$u->name},\n\nPlease install the RED PRIME Driver app on your phone, so you get your jobs and changes straight away:\n\n".Apps::installUrl('driver')
                            ."\n\nAndroid: open the link in Chrome and tap Install.\niPhone: open the link in Safari, tap Share, then \"Add to Home Screen\". Open the app from the new icon and allow notifications.";
                        $sent += Notifier::send($u, new ManualEmail('Install the RED PRIME Driver app', $body, 'driver', 'Driver app install link'));
                    }
                    AuditLog::note(auth()->user(), 'apps', 'Driver app install link emailed to '.$sent.' drivers');
                    Notification::make()->title('Install link sent to '.$sent.' '.\Illuminate\Support\Str::plural('driver', $sent))->success()->send();
                }),
            Action::make('regenerate')->label('Make new notification keys')->icon('heroicon-o-key')->color('danger')->outlined()
                ->requiresConfirmation()
                ->modalHeading('Make new notification keys?')
                ->modalDescription('Only do this if the keys were exposed. Every phone and computer stops getting notifications until the person opens the app again and allows notifications.')
                ->modalSubmitActionLabel('Make new keys')
                ->action(function () {
                    Vapid::generate();
                    $n = UserDevice::query()->whereNotNull('push_endpoint')->update(['push_endpoint' => null, 'endpoint_hash' => null, 'push_keys' => null]);
                    AuditLog::note(auth()->user(), 'apps', 'New web push keys made; '.$n.' devices must allow notifications again');
                    Notification::make()->title('New keys made')->body($n.' devices will ask to allow notifications again.')->warning()->send();
                }),
        ];
    }
}
