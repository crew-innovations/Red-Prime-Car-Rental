<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Access;
use App\Support\FollowUps\FollowUps;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Settings › Customer follow-ups (12 Nov, Bilal: "is ko alag sy rakho"): its own page, because the
 * follow-ups are for everything sent to a customer (quotation, limousine price, website booking,
 * payment link), not only quotations.
 */
class FollowUpSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Customer follow-ups';

    protected static ?string $title = 'Customer follow-ups';

    protected static ?string $slug = 'settings/follow-ups';

    protected static string $view = 'filament.pages.basic-settings'; // the same simple form + Save

    protected static bool $shouldRegisterNavigation = false; // reached from Settings

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Access::allows('manage-team'); // same as Business settings
    }

    public function mount(): void
    {
        $this->form->fill([
            'followup_enabled' => FollowUps::enabled(),
            'followup_hours' => implode(', ', FollowUps::hours()),
            'followup_quiet_from' => (int) Setting::read('followup_quiet_from', 22),
            'followup_quiet_to' => (int) Setting::read('followup_quiet_to', 8),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('When the customer does not answer')
                ->description('The CRM reminds the customer by itself, with the same link, and tells the agent. It stops as soon as the customer accepts, pays or declines. Every reminder is written on the lead.')
                ->schema([
                    Forms\Components\Placeholder::make('covers')->label('Follow-ups go for')
                        ->content(new \Illuminate\Support\HtmlString('<ul style="list-style:disc;padding-left:1.2rem;line-height:1.7"><li>a <b>quotation</b> sent and not accepted or declined</li><li>a <b>limousine price</b> confirmed and not paid</li><li>a <b>website booking</b> waiting for payment</li><li>a <b>payment link</b> not paid</li></ul>')),
                    Forms\Components\Toggle::make('followup_enabled')->label('Send automatic follow-ups')->live(),
                ]),
            Forms\Components\Section::make('When')->columns(3)->visible(fn (Forms\Get $get): bool => (bool) $get('followup_enabled'))
                ->schema([
                    Forms\Components\TextInput::make('followup_hours')->label('Remind after (hours)')->placeholder('2, 24, 72')->required()
                        ->helperText('Hours after sending, separated by commas. 2, 24, 72 = after 2 hours, 1 day and 3 days. Up to 5 reminders.')
                        ->regex('/^\s*\d{1,3}(\s*[,;]\s*\d{1,3}){0,4}\s*$/')->maxLength(40),
                    Forms\Components\TextInput::make('followup_quiet_from')->label('No customer reminders from')->numeric()->minValue(0)->maxValue(23)->suffix(':00')->required(),
                    Forms\Components\TextInput::make('followup_quiet_to')->label('Until')->numeric()->minValue(0)->maxValue(23)->suffix(':00')->required()
                        ->helperText('Dubai time. A reminder due at night goes in the morning. Same hour twice = any time.'),
                ]),
            Forms\Components\Section::make('How the customer is reached')
                ->schema([Forms\Components\Placeholder::make('how')->hiddenLabel()->content('In their account and by email. A customer with no email gets nothing; the agent is told to contact them on WhatsApp. Automatic WhatsApp messages need the WhatsApp Business API to be connected.')]),
        ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();
        $out = ['followup_enabled' => (bool) ($d['followup_enabled'] ?? false)];
        if ($out['followup_enabled']) {
            $hours = collect(preg_split('/[\s,;]+/', (string) $d['followup_hours']))->map(fn ($h) => (int) $h)->filter(fn ($h) => $h > 0)->unique()->sort()->values()->take(5);
            $out += [
                'followup_hours' => $hours->implode(', ') ?: implode(', ', FollowUps::DEFAULT_HOURS),
                'followup_quiet_from' => min(23, max(0, (int) $d['followup_quiet_from'])),
                'followup_quiet_to' => min(23, max(0, (int) $d['followup_quiet_to'])),
            ];
        }

        $before = Setting::values();
        Setting::write($out);
        $changed = collect($out)->filter(fn ($v, $k) => ($before[$k] ?? null) != $v)->keys()->all();
        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'settings',
            'old_values' => collect($before)->only($changed)->all() ?: null,
            'new_values' => collect($out)->only($changed)->all() ?: null,
            'note' => $changed ? 'Customer follow-ups changed: '.implode(', ', $changed) : 'Customer follow-ups saved (no changes)',
        ]);
        $this->mount();
        Notification::make()->success()->title('Saved')->send();
    }
}
