<?php

namespace App\Filament\Pages;

use App\Enums\CustomerType;
use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Support\Access;
use App\Support\Mail\Announcements as Group;
use App\Support\Mail\MailRouter;
use App\Support\Mail\Templates;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Settings › Announcements (4 Nov, brief 14.3): one email to a group. Customers only when they
 * agreed to announcements, always with an unsubscribe link. Sent within the hourly limit of the
 * mailbox, so a big list goes out over a few hours instead of being blocked as spam.
 */
class Announcements extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.pages.announcements';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return ! Access::isDriverOnly() && ! Access::isCustomer() && Access::allows('manage-email');
    }

    public function getSubheading(): ?string
    {
        return 'Send one email to a group. Customers only get it if they agreed to announcements, and every customer email has an unsubscribe link.';
    }

    public function mount(): void
    {
        $this->form->fill(['audience' => 'drivers', 'lang' => 'en']);
    }

    public function form(Form $form): Form
    {
        $customers = fn (Get $get): bool => $get('audience') === 'customers';

        return $form->statePath('data')->columns(2)->schema([
            Forms\Components\Section::make('Who gets it')->columns(2)->schema([
                Forms\Components\ToggleButtons::make('audience')->label('Send to')->options(Group::AUDIENCES)->inline()->required()->live()->columnSpanFull(),
                Forms\Components\Select::make('role')->label('Role')->native(false)->options(array_combine(Access::ROLES, Access::ROLES))
                    ->visible(fn (Get $get) => $get('audience') === 'role')->required(fn (Get $get) => $get('audience') === 'role')->live(),
                Forms\Components\Select::make('language')->label('Language they read')->native(false)->placeholder('Any')->options(['en' => 'English', 'ar' => 'Arabic'])->visible($customers)->live(),
                Forms\Components\Select::make('customer_type')->label('Customer type')->native(false)->placeholder('Any')->options(CustomerType::class)->visible($customers)->live(),
                Forms\Components\Select::make('booked_within_months')->label('Booked with us')->native(false)->placeholder('Any time or never')
                    ->options([3 => 'In the last 3 months', 6 => 'In the last 6 months', 12 => 'In the last 12 months', 24 => 'In the last 2 years'])->visible($customers)->live(),
                Forms\Components\Toggle::make('vip_only')->label('VIP customers only')->visible($customers)->live(),
                Forms\Components\Toggle::make('include_prospects')->label('Include prospects (never booked)')->visible($customers)->live(),
            ]),
            Forms\Components\Section::make('The email')->schema([
                Forms\Components\Select::make('template')->label('Template')->native(false)->placeholder('Write your own')
                    ->options(fn (Get $get) => EmailTemplate::query()->where('is_active', true)
                        ->whereIn('audience', [match ($get('audience')) { 'drivers' => 'driver', 'customers' => 'customer', default => 'staff' }, 'any'])
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set) {
                        if ($state && ($t = EmailTemplate::find($state))) {
                            $set('subject', $t->subject_en);
                            $set('body', $t->body_en);
                        }
                    })
                    ->helperText('A template fills in each person\'s name and details, and uses its Arabic text for customers who read Arabic.'),
                Forms\Components\ToggleButtons::make('lang')->label('Language')->inline()
                    ->options(fn (Get $get) => $get('template') ? ['auto' => 'Each customer\'s language', 'en' => 'English', 'ar' => 'Arabic'] : ['en' => 'English', 'ar' => 'Arabic (right to left)'])
                    ->default('en'),
                Forms\Components\TextInput::make('subject')->required()->maxLength(190)->disabled(fn (Get $get) => filled($get('template')))->dehydrated(),
                Forms\Components\Textarea::make('body')->label('Text')->required()->rows(9)->disabled(fn (Get $get) => filled($get('template')))->dehydrated()
                    ->helperText('Leave an empty line between paragraphs. You can use '.implode(' ', ['{first_name}', '{customer_name}', '{company}', '{account_link}']).'.'),
            ]),
        ]);
    }

    /** People who would get it now (live preview). */
    public function audienceCount(): int
    {
        return rescue(fn () => Group::recipients((array) $this->data)->count(), 0, false);
    }

    public function sample(): array
    {
        return rescue(fn () => Group::recipients((array) $this->data)->take(6)->map(fn ($p) => $p['name'] ?: $p['email'])->all(), [], false);
    }

    public function plan(): array
    {
        $type = Group::type((string) ($this->data['audience'] ?? 'staff'));
        $account = MailRouter::accountFor($type);

        return ['account' => $account?->label ?? 'the default email settings (.env)', 'per_hour' => Group::capacity($account)];
    }

    /** What the first person will see. */
    public function preview(): ?array
    {
        $d = (array) $this->data;
        $first = rescue(fn () => Group::recipients($d)->first(), null, false);
        $record = $first ? ($first['customer'] ?? $first['user']) : null;
        if (filled($d['template'] ?? null) && ($t = EmailTemplate::find($d['template']))) {
            $lang = ($d['lang'] ?? 'en') === 'auto' ? ($first['lang'] ?? 'en') : ($d['lang'] ?? 'en');

            return Templates::render($t, $record, $lang) + ['rtl' => $lang === 'ar'];
        }
        if (blank($d['subject'] ?? null) && blank($d['body'] ?? null)) {
            return null;
        }
        $values = Templates::values($record);

        return ['subject' => strtr((string) ($d['subject'] ?? ''), $values), 'body' => strtr((string) ($d['body'] ?? ''), $values), 'rtl' => ($d['lang'] ?? 'en') === 'ar'];
    }

    public function batches()
    {
        return Group::batches(8);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')->label('Send announcement')->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Send to '.$this->audienceCount().' people?')
                ->modalDescription(fn () => 'From '.$this->plan()['account'].', up to '.$this->plan()['per_hour'].' emails an hour. You can stop it while it is still sending.')
                ->modalSubmitActionLabel('Send')
                ->action(fn () => $this->send()),
            Action::make('log')->label('Email log')->icon('heroicon-o-inbox-stack')->color('gray')
                ->url(fn () => \App\Filament\Resources\EmailLogResource::getUrl()),
        ];
    }

    public function send(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $count = Group::recipients($data)->count();
        if ($count === 0) {
            Notification::make()->title('Nobody to send to')->body('No one in this group has an email address'.(($data['audience'] ?? '') === 'customers' ? ' and agreed to announcements.' : '.'))->warning()->send();

            return;
        }
        $template = filled($data['template'] ?? null) ? EmailTemplate::find($data['template']) : null;
        $r = Group::queue($data, $template, (string) ($data['subject'] ?? ''), (string) ($data['body'] ?? ''), (string) ($data['lang'] ?? 'en'));
        AuditLog::note(auth()->user(), 'email', 'Announcement "'.($template?->name ?? $data['subject']).'" to '.$r['count'].' people ('.Group::AUDIENCES[$data['audience']].', batch '.$r['batch'].')');
        $now = Group::sendDue(Group::PER_RUN, $r['batch']);
        Notification::make()->success()->title('Announcement on its way')
            ->body($r['count'].' emails. '.$now.' sent now'.($r['hours'] > 0 ? ', the rest over about '.($r['hours'] + 1).' hours (hourly limit of the mailbox).' : ($r['count'] > $now ? ', the rest in the next few minutes.' : '.')))
            ->send();
        $this->form->fill(['audience' => $data['audience'], 'lang' => 'en']);
    }

    public function sendNext(string $batch): void
    {
        abort_unless(static::canAccess(), 403);
        $n = Group::sendDue(Group::PER_RUN, $batch);
        Notification::make()->title($n ? $n.' more sent' : 'Nothing to send right now')->body($n ? null : 'The hourly limit of the mailbox is reached. The rest goes out automatically.')->{$n ? 'success' : 'info'}()->send();
    }

    public function stop(string $batch): void
    {
        abort_unless(static::canAccess(), 403);
        $n = Group::cancel($batch);
        AuditLog::note(auth()->user(), 'email', 'Announcement batch '.$batch.' stopped ('.$n.' not sent)');
        Notification::make()->title($n.' emails will not be sent')->warning()->send();
    }
}
