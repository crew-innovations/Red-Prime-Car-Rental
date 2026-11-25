<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Access;
use App\Support\Contracts\ConfirmCheck;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Settings › Rental contracts (18 Nov, meeting minutes). Amounts and rules RED PRIME has not
 * decided yet stay empty (decisions C-1 to C-11): nothing is charged or checked until set here.
 */
class ContractSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Rental contracts';

    protected static ?string $title = 'Rental contracts';

    protected static ?string $slug = 'settings/contracts';

    protected static string $view = 'filament.pages.basic-settings';

    protected static bool $shouldRegisterNavigation = false; // reached from Settings

    public ?array $data = [];

    public const KEYS = [
        'delivery_fee_default', 'no_deposit_allowed', 'no_deposit_fee_mode', 'no_deposit_fee_amount',
        'confirm_requires_details', 'confirm_required_fields', 'driver_min_age', 'licence_min_years',
        'contract_number_prefix', 'contract_required_for_handover', 'contract_sign_link_hours', 'additional_driver_charge',
        'contract_terms_en', 'contract_terms_ar', 'contract_invoice_mode', 'contract_invoice_due_days', 'contract_renewal_days',
        'contract_sign_otp', 'borrow_terms', 'block_expired_car_papers',
    ];

    public static function canAccess(): bool
    {
        return Access::allows('manage-team');
    }

    public function mount(): void
    {
        $this->form->fill(collect(Setting::values())->only(self::KEYS)->all());
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Booking form')
                ->description('Meeting of 18 Nov, items 2 and 4.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('delivery_fee_default')->label('Delivery and collection charges')->native(false)->selectablePlaceholder(false)
                        ->options(['off' => 'Off until switched on on the booking', 'price_list' => 'On from the price list (as before)'])
                        ->helperText('Who may switch them on and change the amount: Business settings › Roles ("Switch delivery / collection charges").'),
                    Forms\Components\Toggle::make('no_deposit_allowed')->label('"No deposit" can be chosen on a booking')->inline(false),
                    Forms\Components\Select::make('no_deposit_fee_mode')->label('No-deposit fee')->native(false)->selectablePlaceholder(false)->live()
                        ->options(['off' => 'No fee (typed per booking if needed)', 'fixed' => 'Fixed amount per rental', 'per_day' => 'Amount per day', 'percent' => '% of the rental']),
                    Forms\Components\TextInput::make('no_deposit_fee_amount')->label(fn (Forms\Get $get): string => $get('no_deposit_fee_mode') === 'percent' ? 'Percent' : 'Amount')
                        ->numeric()->minValue(0)->prefix(fn (Forms\Get $get): ?string => $get('no_deposit_fee_mode') === 'percent' ? null : 'AED')
                        ->suffix(fn (Forms\Get $get): ?string => $get('no_deposit_fee_mode') === 'percent' ? '%' : null)
                        ->visible(fn (Forms\Get $get): bool => $get('no_deposit_fee_mode') !== 'off'),
                ]),
            Forms\Components\Section::make('Before a car booking is confirmed')
                ->description('Meeting item 8. Required documents are set per booking type and per resident / GCC / tourist in Business settings › Documents.')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('confirm_requires_details')->label('Confirm only when the details and documents are complete')->inline(false)->columnSpanFull()
                        ->helperText('Admin can still confirm with a reason (recorded). A booking paid online stays "Waiting for payment" and the team is told what is missing.'),
                    Forms\Components\CheckboxList::make('confirm_required_fields')->label('Customer details needed')->options(ConfirmCheck::FIELD_LABELS)->columns(2)->columnSpanFull(),
                    Forms\Components\TextInput::make('driver_min_age')->label('Minimum driver age')->numeric()->minValue(16)->maxValue(99)->suffix('years')->placeholder('Not checked'),
                    Forms\Components\TextInput::make('licence_min_years')->label('Licence held at least')->numeric()->minValue(0)->maxValue(50)->suffix('years')->placeholder('Not checked'),
                ]),
            Forms\Components\Section::make('Contracts')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('contract_number_prefix')->label('Contract number starts with')->maxLength(8)->required()
                        ->helperText(fn (Forms\Get $get): string => 'e.g. '.($get('contract_number_prefix') ?: 'RC').'-'.date('Y').'-00001'),
                    Forms\Components\TextInput::make('contract_sign_link_hours')->label('Signing links work for')->numeric()->minValue(1)->maxValue(720)->suffix('hours')->required(),
                    Forms\Components\TextInput::make('additional_driver_charge')->label('Additional driver charge')->numeric()->minValue(0)->prefix('AED')->placeholder('Typed per contract')
                        ->helperText('Filled in when a driver is added; can be changed there.'),
                    Forms\Components\Select::make('contract_sign_otp')->label('Signing by link')->native(false)->selectablePlaceholder(false)
                        ->options(['off' => 'Name and signature', 'email' => 'Also a code sent to the customer\'s email'])
                        ->helperText('With the code, a customer without an email signs with name and signature only.'),
                    Forms\Components\Toggle::make('block_expired_car_papers')->label('A car with expired registration or insurance is not handed over')->inline(false)
                        ->helperText('A manager can still hand over anyway; it is recorded.'),
                    Forms\Components\Toggle::make('contract_required_for_handover')->label('Hand over the car only with a fully signed contract')->inline(false)->columnSpanFull()
                        ->helperText('Needs both the hirer\'s and the driver\'s signatures on the rental agreement. Switch on once the contract terms below are in.'),
                    Forms\Components\Textarea::make('contract_terms_en')->label('Contract terms (English)')->rows(14)->columnSpanFull()
                        ->helperText('RED PRIME\'s own terms. Printed on every contract; a signed contract keeps the terms as they were when signed.'),
                    Forms\Components\Textarea::make('contract_terms_ar')->label('Contract terms (Arabic)')->rows(14)->columnSpanFull()->extraInputAttributes(['dir' => 'rtl']),
                    Forms\Components\Textarea::make('borrow_terms')->label('Terms on borrow agreements with suppliers')->rows(5)->columnSpanFull()
                        ->helperText('Printed on "Borrow agreement with the supplier" (bookings of cars hired from a supplier).'),
                ]),
            Forms\Components\Section::make('Monthly contracts')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('contract_invoice_mode')->label('Invoice each month')->native(false)->selectablePlaceholder(false)
                        ->options(['advance' => 'At the start of the month (in advance)', 'arrears' => 'At the end of the month']),
                    Forms\Components\TextInput::make('contract_invoice_due_days')->label('Invoice due after')->numeric()->minValue(0)->maxValue(90)->suffix('days')->required(),
                    Forms\Components\TextInput::make('contract_renewal_days')->label('Remind before a contract ends')->numeric()->minValue(0)->maxValue(60)->suffix('days')->required(),
                ]),
            Forms\Components\Section::make('Who changes a booking\'s status')
                ->schema([Forms\Components\Placeholder::make('status_rule')->hiddenLabel()
                    ->content(new HtmlString('Only roles with <b>"Change a booking\'s status by hand"</b> (Admin by default) can pick a status. Everyone else uses the buttons: Record payment, Hand over, Receive, Ask Admin to cancel. Change it in Business settings › Roles.'))]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $d = $this->form->getState();
        $num = fn ($v) => $v === null || $v === '' ? null : (float) $v;
        $out = [
            'delivery_fee_default' => in_array($d['delivery_fee_default'] ?? 'off', ['off', 'price_list'], true) ? $d['delivery_fee_default'] : 'off',
            'no_deposit_allowed' => (bool) ($d['no_deposit_allowed'] ?? false),
            'no_deposit_fee_mode' => in_array($d['no_deposit_fee_mode'] ?? 'off', ['off', 'fixed', 'per_day', 'percent'], true) ? $d['no_deposit_fee_mode'] : 'off',
            'no_deposit_fee_amount' => $num($d['no_deposit_fee_amount'] ?? null),
            'confirm_requires_details' => (bool) ($d['confirm_requires_details'] ?? false),
            'confirm_required_fields' => array_values(array_intersect((array) ($d['confirm_required_fields'] ?? []), array_keys(ConfirmCheck::FIELD_LABELS))),
            'driver_min_age' => ($v = (int) ($d['driver_min_age'] ?? 0)) > 0 ? $v : null,
            'licence_min_years' => ($v = (int) ($d['licence_min_years'] ?? 0)) > 0 ? $v : null,
            'contract_number_prefix' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($d['contract_number_prefix'] ?? 'RC'))) ?: 'RC',
            'contract_required_for_handover' => (bool) ($d['contract_required_for_handover'] ?? false),
            'contract_sign_link_hours' => max(1, (int) ($d['contract_sign_link_hours'] ?? 48)),
            'additional_driver_charge' => $num($d['additional_driver_charge'] ?? null),
            'contract_terms_en' => filled($d['contract_terms_en'] ?? null) ? trim($d['contract_terms_en']) : null,
            'contract_terms_ar' => filled($d['contract_terms_ar'] ?? null) ? trim($d['contract_terms_ar']) : null,
            'contract_invoice_mode' => ($d['contract_invoice_mode'] ?? 'advance') === 'arrears' ? 'arrears' : 'advance',
            'contract_invoice_due_days' => max(0, (int) ($d['contract_invoice_due_days'] ?? 7)),
            'contract_renewal_days' => max(0, (int) ($d['contract_renewal_days'] ?? 7)),
            'contract_sign_otp' => ($d['contract_sign_otp'] ?? 'off') === 'email' ? 'email' : 'off',
            'borrow_terms' => filled($d['borrow_terms'] ?? null) ? trim($d['borrow_terms']) : null,
            'block_expired_car_papers' => (bool) ($d['block_expired_car_papers'] ?? false),
        ];
        $before = Setting::values();
        Setting::write($out);
        $changed = collect($out)->filter(fn ($v, $k) => ($before[$k] ?? null) != $v)->keys()->all();
        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'settings',
            'old_values' => collect($before)->only($changed)->except(['contract_terms_en', 'contract_terms_ar'])->all() ?: null,
            'new_values' => collect($out)->only($changed)->except(['contract_terms_en', 'contract_terms_ar'])->all() ?: null,
            'note' => $changed ? 'Rental contract settings changed: '.implode(', ', $changed) : 'Rental contract settings saved (no changes)',
        ]);
        $this->mount();
        Notification::make()->success()->title('Saved')->send();
    }
}
