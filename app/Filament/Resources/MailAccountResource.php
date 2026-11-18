<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MailAccountResource\Pages;
use App\Models\MailAccount;
use App\Models\Setting;
use App\Support\Access;
use App\Support\Mail\MailRouter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Settings › Email accounts (4 Nov, 14.1): the company's own mailboxes. Passwords are stored
 * encrypted and never shown again; a mailbox sends only after a successful test email.
 */
class MailAccountResource extends Resource
{
    protected static ?string $model = MailAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Email accounts';

    protected static ?string $modelLabel = 'email account';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'email-accounts';

    public static function canViewAny(): bool
    {
        return Access::allows('manage-integrations');
    }

    public static function canCreate(): bool
    {
        return Access::allows('manage-integrations');
    }

    public static function canEdit(Model $record): bool
    {
        return Access::allows('manage-integrations');
    }

    public static function canDelete(Model $record): bool
    {
        return Access::allows('manage-integrations') && ! \App\Models\EmailLog::where('mail_account_id', $record->id)->exists();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Mailbox')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('label')->required()->maxLength(60)->placeholder('Bookings')
                        ->helperText('Your name for it: Bookings, Accounts, No-reply…'),
                    Forms\Components\Select::make('preset')->label('Provider')
                        ->options(collect(MailAccount::PRESETS)->map(fn ($p) => $p['label'])->all())
                        ->native(false)->live()
                        ->afterStateUpdated(function (?string $state, Set $set) {
                            $p = MailAccount::PRESETS[$state] ?? null;
                            if ($p && $p['host']) {
                                $set('host', $p['host']);
                                $set('port', $p['port']);
                                $set('encryption', $p['encryption']);
                            }
                        })
                        ->helperText('Fills in the server and port. You still type your own email and password.'),
                    Forms\Components\TextInput::make('from_name')->label('Sender name')->required()->maxLength(120)->default(fn () => Setting::read('company_name', 'RED PRIME RENTALS')),
                    Forms\Components\TextInput::make('from_address')->label('Sender email')->email()->required()->maxLength(190)->placeholder('bookings@redprimerentals.com'),
                    Forms\Components\TextInput::make('reply_to')->label('Replies go to (optional)')->email()->maxLength(190),
                    Forms\Components\TextInput::make('per_hour')->label('Most emails per hour (announcements)')->numeric()->integer()->minValue(1)->maxValue(5000)
                        ->placeholder((string) Setting::read('announcement_per_hour', 100))
                        ->helperText('So the email provider does not block the mailbox. Empty = '.Setting::read('announcement_per_hour', 100).'.'),
                ]),
            Forms\Components\Section::make('Server (SMTP)')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('host')->required()->maxLength(190)->placeholder('smtp.hostinger.com'),
                    Forms\Components\TextInput::make('port')->required()->numeric()->integer()->minValue(1)->maxValue(65535),
                    Forms\Components\Select::make('encryption')->options(MailAccount::ENCRYPTION)->required()->native(false)->default('tls'),
                    Forms\Components\TextInput::make('username')->label('Username (usually the email)')->maxLength(190)->autocomplete('off'),
                    Forms\Components\TextInput::make('password')
                        ->password()->revealable(false)->autocomplete('new-password')->maxLength(255)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->afterStateHydrated(fn (Forms\Components\TextInput $component) => $component->state(null))
                        ->placeholder(fn (?MailAccount $record): string => $record && filled($record->getRawOriginal('password')) ? 'Saved · type to replace' : '')
                        ->helperText('Stored encrypted. It is never shown again; type a new one to replace it.'),
                    Forms\Components\Toggle::make('is_default')->label('Default mailbox')->inline(false)
                        ->helperText('Used for anything without its own mailbox under "Routing".'),
                ]),
            Forms\Components\Placeholder::make('test_note')->hiddenLabel()->columnSpanFull()
                ->content('After saving, press "Send test email". The mailbox only sends once the test works.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')->weight('bold')->description(fn (MailAccount $r) => $r->from_name.' <'.$r->from_address.'>'),
                Tables\Columns\TextColumn::make('host')->description(fn (MailAccount $r) => 'Port '.$r->port.' · '.(MailAccount::ENCRYPTION[$r->encryption] ?? $r->encryption))->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => MailAccount::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ['connected' => 'success', 'failed' => 'danger'][$state] ?? 'gray')
                    ->description(fn (MailAccount $r) => $r->last_tested_at ? 'Tested '.$r->last_tested_at->diffForHumans().($r->last_error ? ': '.\Illuminate\Support\Str::limit($r->last_error, 80) : '') : null),
                Tables\Columns\IconColumn::make('is_default')->label('Default')->boolean(),
                Tables\Columns\TextColumn::make('routes')->label('Sends')
                    ->state(fn (MailAccount $r) => collect((array) Setting::read('mail_routes', []))->filter(fn ($id) => (int) $id === $r->id)->keys()
                        ->map(fn ($t) => \Illuminate\Support\Str::before(MailRouter::TYPES[$t] ?? $t, ':'))->unique()->implode(', ') ?: ($r->is_default ? 'Everything else' : '—'))
                    ->wrap(),
            ])
            ->actions([
                Tables\Actions\Action::make('test')
                    ->label('Send test email')->icon('heroicon-o-paper-airplane')->color('primary')
                    ->form([Forms\Components\TextInput::make('to')->label('Send it to')->email()->required()->default(fn () => auth()->user()?->email)])
                    ->action(function (MailAccount $record, array $data) {
                        $ok = MailRouter::test($record, $data['to']);
                        Notification::make()->title($ok ? 'Test email sent. The mailbox is connected.' : 'The test failed')
                            ->body($ok ? null : $record->fresh()->last_error)->{$ok ? 'success' : 'danger'}()->persistent()->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('No mailboxes yet')
            ->emptyStateDescription('Until you add one, emails go out with the mail settings in the server .env file.');
    }

    /** "Routing": which mailbox sends which kind of email (14.2). */
    public static function routingAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('routing')
            ->label('Routing')->icon('heroicon-o-arrows-right-left')->color('gray')
            ->authorize('manage-integrations')
            ->modalHeading('Which mailbox sends what')
            ->modalDescription('Only connected mailboxes send. Empty = the default mailbox (or the .env settings when there is none).')
            ->fillForm(fn () => ['routes' => (array) Setting::read('mail_routes', []), 'retention' => Setting::read('email_log_retention_days', 90)])
            ->form([
                Forms\Components\Group::make(collect(MailRouter::TYPES)->map(fn ($label, $type) => Forms\Components\Select::make('routes.'.$type)->label($label)
                    ->options(fn () => MailAccount::query()->orderBy('label')->get()->mapWithKeys(fn (MailAccount $a) => [$a->id => $a->title().($a->isConnected() ? '' : ' · not connected')])->all())
                    ->placeholder('Default mailbox')->native(false))->values()->all()),
                Forms\Components\TextInput::make('retention')->label('Keep email texts in the email log for')->numeric()->integer()->minValue(7)->maxValue(3650)->suffix('days')->required(),
            ])
            ->action(function (array $data) {
                $valid = MailAccount::query()->pluck('id')->all();
                $routes = collect((array) ($data['routes'] ?? []))->filter(fn ($id) => in_array((int) $id, $valid, true))->map(fn ($id) => (int) $id)->all();
                Setting::write(['mail_routes' => $routes, 'email_log_retention_days' => max(7, (int) $data['retention'])]);
                \App\Models\AuditLog::event('settings', 'Email routing changed');
                Notification::make()->title('Routing saved')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMailAccounts::route('/'),
        ];
    }
}
