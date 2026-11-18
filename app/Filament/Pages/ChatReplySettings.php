<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Access;
use App\Support\Chat\AutoReply;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** Settings › Chat auto replies (12 Nov, C4): what the CRM answers by itself on Instagram / Messenger / WhatsApp. */
class ChatReplySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';

    protected static ?string $navigationLabel = 'Chat auto replies';

    protected static ?string $title = 'Chat auto replies';

    protected static ?string $slug = 'settings/chat-replies';

    protected static string $view = 'filament.pages.basic-settings'; // the same simple form + Save

    protected static bool $shouldRegisterNavigation = false; // reached from Settings

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Access::allows('manage-team');
    }

    public function mount(): void
    {
        $this->form->fill([
            'chat_welcome_enabled' => AutoReply::welcomeOn(),
            'chat_welcome_text' => Setting::read('chat_welcome_text') ?: AutoReply::WELCOME,
            'chat_welcome_form' => (bool) Setting::read('chat_welcome_form', true),
            'chat_closed_enabled' => (bool) Setting::read('chat_closed_enabled', false),
            'chat_closed_text' => Setting::read('chat_closed_text') ?: AutoReply::CLOSED,
            'chat_hours_from' => Setting::read('chat_hours_from'),
            'chat_hours_to' => Setting::read('chat_hours_to'),
            'chat_days' => AutoReply::days(),
        ]);
    }

    public function form(Form $form): Form
    {
        $hint = '{name} = the customer\'s first name, {company} = your company name.';

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('First reply to a new enquiry')
                ->description('Sent once, straight away, when someone writes to you on Instagram, Facebook Messenger or WhatsApp for the first time (or again after their last enquiry was closed). Your agent then carries on the chat.')
                ->schema([
                    Forms\Components\Toggle::make('chat_welcome_enabled')->label('Send the first reply automatically')->live(),
                    Forms\Components\Textarea::make('chat_welcome_text')->label('Message')->rows(3)->required()->maxLength(600)->helperText($hint)
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('chat_welcome_enabled')),
                    Forms\Components\Toggle::make('chat_welcome_form')->label('Add the details form link under it')
                        ->helperText('Uses your newest open form in Leads › Shared forms. What the customer fills in goes onto the same lead. Nothing is added when you have no open form.')
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('chat_welcome_enabled')),
                ]),
            Forms\Components\Section::make('Reply outside working hours')
                ->description('Sent in place of the first reply when a new enquiry arrives while you are closed, and at most once every 12 hours for later messages.')
                ->columns(2)->schema([
                    Forms\Components\Toggle::make('chat_closed_enabled')->label('Reply automatically when we are closed')->live()->columnSpanFull(),
                    Forms\Components\TextInput::make('chat_hours_from')->label('We open at')->numeric()->minValue(0)->maxValue(23)->suffix(':00')
                        ->required(fn (Forms\Get $get): bool => (bool) $get('chat_closed_enabled'))->visible(fn (Forms\Get $get): bool => (bool) $get('chat_closed_enabled')),
                    Forms\Components\TextInput::make('chat_hours_to')->label('We close at')->numeric()->minValue(0)->maxValue(23)->suffix(':00')->different('chat_hours_from')
                        ->helperText('Dubai time. Closing after midnight is fine, e.g. 10 to 2.')
                        ->required(fn (Forms\Get $get): bool => (bool) $get('chat_closed_enabled'))->visible(fn (Forms\Get $get): bool => (bool) $get('chat_closed_enabled')),
                    Forms\Components\CheckboxList::make('chat_days')->label('Working days')->options(AutoReply::DAYS)->columns(4)->columnSpanFull()
                        ->required(fn (Forms\Get $get): bool => (bool) $get('chat_closed_enabled'))->visible(fn (Forms\Get $get): bool => (bool) $get('chat_closed_enabled')),
                    Forms\Components\Textarea::make('chat_closed_text')->label('Message')->rows(3)->maxLength(600)->helperText($hint)->columnSpanFull()
                        ->required(fn (Forms\Get $get): bool => (bool) $get('chat_closed_enabled'))->visible(fn (Forms\Get $get): bool => (bool) $get('chat_closed_enabled')),
                ]),
            Forms\Components\Section::make('Good to know')
                ->schema([Forms\Components\Placeholder::make('how')->hiddenLabel()->content('These replies only go out once Instagram, Facebook & WhatsApp messages are connected in Settings › Integrations. They appear in the conversation in Messages like any other reply.')]),
        ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();
        $out = ['chat_welcome_enabled' => (bool) ($d['chat_welcome_enabled'] ?? false), 'chat_closed_enabled' => (bool) ($d['chat_closed_enabled'] ?? false)];
        if ($out['chat_welcome_enabled']) {
            $out += ['chat_welcome_text' => trim((string) $d['chat_welcome_text']), 'chat_welcome_form' => (bool) ($d['chat_welcome_form'] ?? false)];
        }
        if ($out['chat_closed_enabled']) {
            $out += ['chat_closed_text' => trim((string) $d['chat_closed_text']), 'chat_hours_from' => (int) $d['chat_hours_from'], 'chat_hours_to' => (int) $d['chat_hours_to'],
                'chat_days' => array_values(array_map('intval', (array) $d['chat_days']))];
        }

        $before = Setting::values();
        Setting::write($out);
        $changed = collect($out)->filter(fn ($v, $k) => ($before[$k] ?? null) != $v)->keys()->all();
        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'settings',
            'old_values' => collect($before)->only($changed)->all() ?: null,
            'new_values' => collect($out)->only($changed)->all() ?: null,
            'note' => $changed ? 'Chat auto replies changed: '.implode(', ', $changed) : 'Chat auto replies saved (no changes)',
        ]);
        $this->mount();
        Notification::make()->success()->title('Saved')->send();
    }
}
