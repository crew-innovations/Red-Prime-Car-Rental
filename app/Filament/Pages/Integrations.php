<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Support\Access;
use App\Support\Integrations\Registry;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Settings › Integrations (brief 28 Oct-2, 3.2 / 4.3 / 12.1): every outside
 * service in one place, with what it needs, its real status, the last test,
 * the last error and recent webhook / API events. Secrets are typed once and
 * never shown again; "Connected" only after a successful test.
 */
class Integrations extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $title = 'Integrations';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.integrations';

    public static function canAccess(): bool
    {
        return Access::allows('manage-integrations');
    }

    public function providers(): array
    {
        $saved = Integration::query()->get()->keyBy('provider');

        return collect(Registry::all())->map(function (array $def, string $key) use ($saved) {
            $i = $saved[$key] ?? Integration::for($key);
            $missing = collect($def['required'] ?? [])->reject(fn ($s) => $i->hasSecret($s))->map(fn ($s) => $def['secrets'][$s]['label'] ?? $s)->values()->all();

            return [
                'key' => $key, 'def' => $def, 'i' => $i,
                'status' => $i->exists ? $i->status : 'not_set_up',
                'missing' => $missing,
                'events' => IntegrationEvent::query()->where('provider', $key)->latest('id')->limit(5)->get(),
            ];
        })->groupBy(fn ($p) => $p['def']['group'] ?? 'Other', preserveKeys: true)->all();
    }

    public function setUpAction(): Action
    {
        return Action::make('setUp')
            ->label('Set up')
            ->icon('heroicon-o-cog-6-tooth')
            ->modalWidth('2xl')
            ->modalHeading(fn (array $arguments): string => 'Set up '.(Registry::get($arguments['provider'] ?? '')['label'] ?? ''))
            ->modalDescription('Keys are stored encrypted and are never shown again. Leave a key empty to keep the saved one.')
            ->fillForm(function (array $arguments): array {
                $i = Integration::for($arguments['provider']);
                $def = Registry::get($arguments['provider']);
                $config = collect($def['config'] ?? [])->mapWithKeys(fn ($f, $k) => ['config_'.$k => $i->config($k, $f['default'] ?? null)])->all();

                return ['enabled' => $i->enabled, 'mode' => $i->mode ?: 'test'] + $config;
            })
            ->form(function (array $arguments): array {
                $key = $arguments['provider'] ?? '';
                $def = Registry::get($key) ?? [];
                $i = Integration::for($key);
                $fields = [
                    Forms\Components\Toggle::make('enabled')->label('Switched on')->helperText('Off = the CRM does not call this service at all.'),
                ];
                if (! empty($def['modes'])) {
                    $fields[] = Forms\Components\ToggleButtons::make('mode')->label('Mode')->inline()
                        ->options(['test' => 'Test / sandbox', 'live' => 'Live'])->live()
                        ->helperText('Test and live keys are kept apart. Live charges real money.');
                }
                foreach ($def['config'] ?? [] as $k => $f) {
                    $fields[] = isset($f['options'])
                        ? Forms\Components\Select::make('config_'.$k)->label($f['label'])->options($f['options'])->multiple((bool) ($f['multiple'] ?? false))->helperText($f['help'] ?? null)
                        : Forms\Components\TextInput::make('config_'.$k)->label($f['label'])->helperText($f['help'] ?? null)->maxLength(255);
                }
                foreach ($def['secrets'] ?? [] as $k => $f) {
                    $fields[] = Forms\Components\TextInput::make('secret_'.$k)
                        ->label($f['label'])
                        ->password()->revealable(false)
                        ->autocomplete('new-password')
                        ->maxLength(4000)
                        ->placeholder(fn (Forms\Get $get): string => $i->hasSecret($k, $get('mode') ?: 'test') ? 'Saved · leave empty to keep' : 'Not saved yet')
                        ->helperText($f['help'] ?? null);
                }

                return $fields;
            })
            ->action(function (array $data, array $arguments) {
                abort_unless(Access::allows('manage-integrations'), 403);
                $key = $arguments['provider'];
                $def = Registry::get($key);
                abort_unless($def, 404);
                $i = Integration::for($key);
                $mode = ! empty($def['modes']) && ($data['mode'] ?? 'test') === 'live' ? 'live' : 'test';
                $before = ['enabled' => (bool) $i->enabled, 'mode' => $i->mode, 'config' => $i->config];

                $config = (array) ($i->config ?? []);
                foreach ($def['config'] ?? [] as $k => $f) {
                    $config[$k] = $data['config_'.$k] ?? null;
                }
                $changedSecrets = $i->putSecrets(collect($data)->filter(fn ($v, $k) => str_starts_with($k, 'secret_'))->mapWithKeys(fn ($v, $k) => [substr($k, 7) => $v])->all(), $mode);
                $i->fill(['enabled' => (bool) ($data['enabled'] ?? false), 'mode' => $mode, 'config' => $config, 'updated_by' => auth()->id()]);
                // Changing keys or mode means it has to be tested again.
                if ($changedSecrets || $before['mode'] !== $mode || ! $i->exists) {
                    $i->status = collect($def['required'] ?? [])->every(fn ($s) => $i->hasSecret($s)) ? 'configured' : 'not_set_up';
                }
                $i->save();

                AuditLog::create([
                    'event' => 'integration', 'auditable_type' => 'integration', 'auditable_id' => $i->id,
                    'old_values' => $before, 'new_values' => ['enabled' => $i->enabled, 'mode' => $mode, 'config' => $config, 'keys_changed' => $changedSecrets],
                    'note' => mb_substr($def['label'].' settings saved'.($changedSecrets ? ' (new keys: '.implode(', ', $changedSecrets).')' : ''), 0, 255),
                ]);
                Notification::make()->title($def['label'].' saved')->body($i->status === 'configured' ? 'Now press "Test connection".' : null)->success()->send();
            });
    }

    public function testAction(): Action
    {
        return Action::make('test')
            ->label('Test connection')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->action(function (array $arguments) {
                abort_unless(Access::allows('manage-integrations'), 403);
                $def = Registry::get($arguments['provider']);
                $i = Integration::for($arguments['provider']);
                if (! $i->exists || empty($def['tester'])) {
                    Notification::make()->title('Save the settings first')->warning()->send();

                    return;
                }
                [$ok, $error, $account] = array_pad((array) $def['tester']::test($i), 3, null);
                $i->recordTest((bool) $ok, $error, $account);
                AuditLog::create(['event' => 'integration', 'outcome' => $ok ? null : 'failed', 'auditable_type' => 'integration', 'auditable_id' => $i->id,
                    'note' => mb_substr($def['label'].' test: '.($ok ? 'connected' : 'failed · '.$error), 0, 255)]);
                Notification::make()->title($ok ? $def['label'].' is connected' : $def['label'].': test failed')->body($ok ? $account : $error)->{$ok ? 'success' : 'danger'}()->send();
            });
    }

    public function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label('Disconnect')
            ->icon('heroicon-o-link-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Removes the saved keys and switches it off. History, leads and payments already received are kept.')
            ->action(function (array $arguments) {
                abort_unless(Access::allows('manage-integrations'), 403);
                $i = Integration::for($arguments['provider']);
                if (! $i->exists) {
                    return;
                }
                $i->forgetSecrets();
                $i->forceFill(['enabled' => false, 'status' => 'not_set_up', 'account_label' => null, 'token_expires_at' => null, 'permissions' => null, 'updated_by' => auth()->id()])->save();
                AuditLog::create(['event' => 'integration', 'auditable_type' => 'integration', 'auditable_id' => $i->id, 'note' => $i->label().' disconnected, keys removed']);
                Notification::make()->title($i->label().' disconnected')->success()->send();
            });
    }
}
