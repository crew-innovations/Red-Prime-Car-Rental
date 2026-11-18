<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Settings › Everyday settings (6 Nov, S4 / C.3): the few settings Admin changes, each with one
 * line of help. They are the same settings as in Business settings (now "Advanced"), so nothing
 * is copied: changing one here changes it there.
 */
class BasicSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Everyday settings';

    protected static ?string $title = 'Everyday settings';

    protected static ?string $slug = 'settings/basic';

    protected static string $view = 'filament.pages.basic-settings';

    protected static bool $shouldRegisterNavigation = false; // reached from Settings

    public ?array $data = [];

    public const SERVICES = ['car' => 'Car rental', 'trip' => 'Yacht trip', 'package' => 'Trip / package', 'transfer' => 'Limousine', 'yacht' => 'Yacht charter'];

    public static function canAccess(): bool
    {
        return Access::allows('manage-team'); // same as Business settings
    }

    public function mount(): void
    {
        $agent = collect((array) Setting::read('discount_limits', []))->firstWhere('role', 'Agent');
        $this->form->fill([
            'company_name' => Setting::read('company_name'),
            'company_whatsapp' => Setting::read('company_whatsapp'),
            'company_website' => Setting::read('company_website'),
            'vat_enabled' => (bool) Setting::read('vat_enabled'),
            'vat_rate' => Setting::read('vat_rate'),
            'advance' => (array) Setting::read('advance_percent', []),
            'agent_discount' => $agent['percent'] ?? null,
            'quote_valid_days' => Setting::read('quote_valid_days'),
            'website_booking_hold_hours' => Setting::read('website_booking_hold_hours'),
            'lead_accept_minutes' => Setting::read('lead_accept_minutes'),
            'fine_admin_fee_amount' => Setting::read('fine_admin_fee_amount'),
            'salik_fee' => ((array) Setting::read('toll_service_fee', []))['salik'] ?? null,
            'refuel_fee' => Setting::read('refuel_fee'),
        ]);
    }

    public function form(Form $form): Form
    {
        $aed = fn (string $name, string $label, string $help) => Forms\Components\TextInput::make($name)->label($label)->numeric()->minValue(0)->prefix('AED')->helperText($help);

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Company')->description('On quotes, invoices and emails.')->columns(3)->schema([
                Forms\Components\TextInput::make('company_name')->label('Company name')->required()->maxLength(120),
                Forms\Components\TextInput::make('company_whatsapp')->label('WhatsApp')->tel()->maxLength(30)->helperText('Customers can message this number.'),
                Forms\Components\TextInput::make('company_website')->label('Website')->url()->maxLength(190),
            ]),
            Forms\Components\Section::make('Money')->columns(3)->schema([
                Forms\Components\Toggle::make('vat_enabled')->label('Charge VAT')->live()->helperText('Off: prices have no VAT line.'),
                Forms\Components\TextInput::make('vat_rate')->label('VAT %')->numeric()->minValue(0)->maxValue(50)->suffix('%')
                    ->visible(fn (Forms\Get $get) => (bool) $get('vat_enabled'))->required(fn (Forms\Get $get) => (bool) $get('vat_enabled')),
                Forms\Components\TextInput::make('agent_discount')->label('Agents can give up to')->numeric()->minValue(0)->maxValue(100)->suffix('%')
                    ->helperText('More than this waits for Admin. Empty = no limit.'),
                Forms\Components\Fieldset::make('Advance to confirm a booking')->columns(5)->schema(
                    collect(self::SERVICES)->map(fn ($label, $key) => Forms\Components\TextInput::make('advance.'.$key)->label($label)->numeric()->minValue(0)->maxValue(100)->suffix('%'))->values()->all()
                )->columnSpanFull(),
                Forms\Components\Placeholder::make('advance_help')->hiddenLabel()->columnSpanFull()
                    ->content('Share of the total the customer pays before the booking is confirmed. Empty = no advance needed.'),
            ]),
            Forms\Components\Section::make('Sales')->columns(3)->schema([
                Forms\Components\TextInput::make('lead_accept_minutes')->label('Minutes to accept a lead')->numeric()->minValue(5)->maxValue(1440)->suffix('min')
                    ->helperText('After this, every agent is reminded and Admin is told.'),
                Forms\Components\TextInput::make('quote_valid_days')->label('Quotes valid for')->numeric()->minValue(1)->maxValue(90)->suffix('days')
                    ->helperText('Fills in "Valid until" on new quotes.'),
                Forms\Components\TextInput::make('website_booking_hold_hours')->label('Website bookings held for')->numeric()->minValue(1)->maxValue(168)->suffix('hours')
                    ->helperText('An unpaid website booking is cancelled after this.'),
            ]),
            Forms\Components\Section::make('Fees charged to customers')->description('Empty = no fee.')->columns(3)->schema([
                $aed('fine_admin_fee_amount', 'Traffic fine admin fee', 'Added to each fine passed on to the customer.'),
                $aed('salik_fee', 'Salik fee', 'Added to each Salik charge passed on.'),
                $aed('refuel_fee', 'Refuel fee', 'Added once when a car comes back with less fuel.'),
            ]),
            Forms\Components\Section::make('Also here')->schema([
                Forms\Components\Placeholder::make('links')->hiddenLabel()->content(fn () => new HtmlString(collect([
                    ['Fuel prices this month', \App\Filament\Resources\FuelEntryResource::class],
                    ['Email accounts', \App\Filament\Resources\MailAccountResource::class],
                    ['Phone apps for staff and drivers', AppSettings::class],
                    ['Every other rule (Advanced)', BusinessSettings::class],
                ])->filter(fn ($l) => $l[1]::canAccess())->map(fn ($l) => '<a href="'.e(is_subclass_of($l[1], \Filament\Resources\Resource::class) ? $l[1]::getUrl('index') : $l[1]::getUrl())
                    .'" style="color:rgb(var(--primary-600));font-weight:600;margin-right:1.25rem">'.e($l[0]).' →</a>')->implode(''))),
            ]),
        ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();
        $num = fn ($v) => ($v === null || $v === '') ? null : (float) $v;

        $limits = collect((array) Setting::read('discount_limits', []))->reject(fn ($r) => ($r['role'] ?? null) === 'Agent')->values()->all();
        array_unshift($limits, ['role' => 'Agent', 'percent' => $num($d['agent_discount'] ?? null)]);
        $advance = collect(self::SERVICES)->keys()->mapWithKeys(fn ($k) => [$k => $num($d['advance'][$k] ?? null)])->all();
        $toll = (array) Setting::read('toll_service_fee', []);
        $toll['salik'] = $num($d['salik_fee'] ?? null);

        $out = [
            'company_name' => trim((string) $d['company_name']),
            'company_whatsapp' => $d['company_whatsapp'] ?: null,
            'company_website' => $d['company_website'] ?: null,
            'vat_enabled' => (bool) $d['vat_enabled'],
            'vat_rate' => $d['vat_enabled'] ? $num($d['vat_rate'] ?? null) : Setting::read('vat_rate'),
            'discount_limits' => $limits,
            'advance_percent' => $advance + (array) Setting::read('advance_percent', []),
            'quote_valid_days' => $num($d['quote_valid_days'] ?? null) ? (int) $d['quote_valid_days'] : null,
            'website_booking_hold_hours' => $num($d['website_booking_hold_hours'] ?? null) ? (int) $d['website_booking_hold_hours'] : Setting::read('website_booking_hold_hours'),
            'lead_accept_minutes' => $num($d['lead_accept_minutes'] ?? null) ? (int) $d['lead_accept_minutes'] : null,
            'fine_admin_fee_amount' => $num($d['fine_admin_fee_amount'] ?? null),
            'toll_service_fee' => $toll,
            'refuel_fee' => $num($d['refuel_fee'] ?? null),
        ];

        $before = Setting::values();
        Setting::write($out);
        $changed = collect($out)->filter(fn ($v, $k) => ($before[$k] ?? null) != $v)->keys()->all();
        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'settings',
            'old_values' => collect($before)->only($changed)->all() ?: null,
            'new_values' => collect($out)->only($changed)->all() ?: null,
            'note' => $changed ? 'Everyday settings changed: '.Str::limit(implode(', ', $changed), 200) : 'Everyday settings saved (no changes)',
        ]);

        Notification::make()->title('Saved')->success()->send();
    }
}
