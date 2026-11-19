<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\Access;
use App\Support\Options;
use App\Support\Rules;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * Everything the business decides for itself: company details and messages,
 * booking and return rules, VAT and quotations, document types and rules,
 * suggestion lists, money categories and what each role may do.
 * The CRM ships with its previous behaviour as defaults; nothing new is assumed.
 */
class BusinessSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.business-settings';

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Business settings';

    protected static ?string $title = 'Business settings';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'business-settings';

    public ?array $data = [];

    public const KINDS = [
        'self_drive' => 'Car, self-drive',
        'with_driver' => 'Car, with driver',
        'yacht' => 'Yacht charter',
        'bareboat' => 'Extra for yacht charter without crew (bareboat)',
        'trip' => 'Yacht trip',
        'package' => 'Trip / package',
        'transfer' => 'Limousine & transfer',
        'corporate' => 'Extra for company / agency customers',
        // 18 Nov (meeting item 8): car rentals also need these, by who the customer is.
        'resident' => 'Extra for UAE residents (car rentals)',
        'gcc' => 'Extra for GCC nationals (car rentals)',
        'tourist' => 'Extra for tourists / visitors (car rentals)',
    ];

    public static function canAccess(): bool
    {
        return Access::allows('manage-team');
    }

    public function mount(): void
    {
        $values = Setting::values();

        foreach (array_keys(self::KINDS) as $kind) {
            $values['req_'.$kind] = $values['doc_requirements'][$kind] ?? [];
        }
        foreach (array_keys(Options::LISTS) as $key) {
            $values['list_'.$key] = Options::list($key);
        }
        foreach (Options::MESSAGES as $key => [, $default]) {
            $values[$key] = filled($values[$key] ?? null) ? $values[$key] : $default;
        }
        foreach (array_keys(Access::ABILITIES) as $ability) {
            $values['perm_'.self::permKey($ability)] = array_values(array_intersect(Access::rolesFor($ability), Access::ROLES));
        }

        foreach (['salik', 'darb', 'parking'] as $t) {
            $values['toll_fee_'.$t] = ((array) ($values['toll_service_fee'] ?? []))[$t] ?? null;
        }
        $channels = (array) ($values['notification_channels'] ?? []);
        $values['chan_staff'] = $channels['*'] ?? \App\Support\Notifications\Preferences::ROLE_DEFAULTS['*'];
        $values['chan_driver'] = $channels['Driver'] ?? \App\Support\Notifications\Preferences::ROLE_DEFAULTS['Driver'];
        $values['chan_customer'] = $channels['Customer'] ?? \App\Support\Notifications\Preferences::ROLE_DEFAULTS['Customer'];
        $values['document_types'] = Options::documentTypeRows();
        $values['return_charges'] = collect(Options::charges())
            ->map(fn ($meta, $key) => ['key' => $key, 'label' => $meta[0], 'category' => $meta[1], 'cars_only' => $meta[2]])
            ->values()->all();
        foreach (['income', 'expense'] as $type) {
            $values['categories_'.$type] = collect(Transaction::categories($type))
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all();
        }

        $this->form->fill($values);
    }

    protected static function permKey(string $ability): string
    {
        return str_replace('-', '_', $ability);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Tabs::make('settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        $this->companyTab(),
                        $this->bookingsTab(),
                        $this->quotesTab(),
                        $this->documentsTab(),
                        $this->listsTab(),
                        $this->moneyTab(),
                        $this->driversTab(),
                        $this->rolesTab(),
                    ]),
            ]);
    }

    protected function companyTab(): Forms\Components\Tabs\Tab
    {
        $placeholders = 'You can use: {first_name} {name} {company} {link} {number} {reference} {item} {start} {end} {amount} {balance_line}';

        return Forms\Components\Tabs\Tab::make('Company & messages')
            ->icon('heroicon-o-building-office-2')
            ->schema([
                Forms\Components\Section::make('Website connection')
                    ->description('The website reads published Yacht Trips and Trips & Packages from the CRM and sends enquiries to it.')
                    ->schema([
                        Forms\Components\TagsInput::make('website_origins')
                            ->label('Website addresses allowed to use the CRM API')
                            ->placeholder('https://www.redprimerentals.com')
                            ->helperText('Type the full address and press Enter. Only these sites can send enquiries from a browser. Leave empty until the website is live.')
                            ->nestedRecursiveRules(['url', 'max:255']),
                    ]),
                Forms\Components\Section::make('Website contact & social')
                    ->description('Shown on redprimerentals.com: header, footer, contact page and the WhatsApp buttons.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('website_phone')->label('Phone (calls)')->tel()->maxLength(30),
                        Forms\Components\TextInput::make('website_whatsapp')->label('WhatsApp number')->tel()->maxLength(30)
                            ->helperText('With country code, e.g. +971 55 793 9231.'),
                        Forms\Components\TextInput::make('website_email')->label('Email')->email()->maxLength(120),
                        Forms\Components\TextInput::make('website_map_url')->label('Google Maps link')->url()->maxLength(255),
                        Forms\Components\Textarea::make('website_address')->label('Address on the website')->rows(2)
                            ->helperText('Empty = the company address above is used.'),
                        Forms\Components\TextInput::make('website_instagram')->label('Instagram')->url()->maxLength(255),
                        Forms\Components\TextInput::make('website_facebook')->label('Facebook')->url()->maxLength(255),
                        Forms\Components\TextInput::make('website_tiktok')->label('TikTok')->url()->maxLength(255),
                        Forms\Components\TextInput::make('website_snapchat')->label('Snapchat')->url()->maxLength(255),
                    ]),
                Forms\Components\Section::make('Company profile')
                    ->description('Printed on every PDF: quotations, confirmations, invoices, receipts and statements. The TRN is only printed while VAT is switched on.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('profile_missing')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn (): bool => \App\Support\Documents\CompanyProfile::missing() !== [])
                            ->content(fn (): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString('<span style="color:rgb(var(--warning-600));font-weight:600">Company profile incomplete:</span> '
                                .e(implode(', ', \App\Support\Documents\CompanyProfile::missing())).'. PDFs still work, without these details.')),
                        Forms\Components\TextInput::make('company_name')->label('Trading name')->required()->maxLength(120),
                        Forms\Components\TextInput::make('company_legal_name')->label('Legal name (as on the trade licence)')->maxLength(160),
                        Forms\Components\TextInput::make('company_trade_licence')->label('Trade licence number')->maxLength(60),
                        Forms\Components\TextInput::make('company_trn')->label('VAT registration number (TRN)')->maxLength(30),
                        Forms\Components\TextInput::make('company_phone')->label('Phone')->tel()->maxLength(30),
                        Forms\Components\TextInput::make('company_whatsapp')->label('WhatsApp')->tel()->maxLength(30),
                        Forms\Components\TextInput::make('company_email')->label('Email')->email()->maxLength(120),
                        Forms\Components\TextInput::make('company_website')->label('Website')->maxLength(120)->placeholder('redprimerentals.com'),
                        Forms\Components\Textarea::make('company_address')->label('Address')->rows(2)->columnSpanFull(),
                        Forms\Components\FileUpload::make('company_logo')->label('Logo for white paper (PDFs)')
                            ->helperText('PNG or JPG. Empty = the built-in RED PRIME logo.')
                            ->image()->disk('uploads')->directory('company')->visibility('public')->maxSize(2048)
                            ->acceptedFileTypes(['image/png', 'image/jpeg']),
                        Forms\Components\FileUpload::make('company_logo_dark')->label('Logo for dark backgrounds')
                            ->helperText('Optional. PNG or JPG.')
                            ->image()->disk('uploads')->directory('company')->visibility('public')->maxSize(2048)
                            ->acceptedFileTypes(['image/png', 'image/jpeg']),
                        Forms\Components\FileUpload::make('company_stamp')->label('Stamp or signature (optional)')
                            ->helperText('Printed small at the bottom of quotations. Kept private.')
                            ->image()->disk('private')->directory('company')->maxSize(1024)
                            ->acceptedFileTypes(['image/png', 'image/jpeg'])
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('bank_name')->label('Bank')->maxLength(120),
                        Forms\Components\TextInput::make('bank_account_name')->label('Account name')->maxLength(160),
                        Forms\Components\TextInput::make('bank_iban')->label('IBAN')->maxLength(40),
                        Forms\Components\TextInput::make('bank_swift')->label('SWIFT / BIC')->maxLength(20),
                        Forms\Components\Textarea::make('bank_details')->label('Other payment details')->rows(2)
                            ->helperText('Free text, printed after the bank details (or alone if the fields above are empty).'),
                        Forms\Components\Textarea::make('policy_cancellation')->label('Cancellation and changes (printed on quotations and confirmations)')->rows(3)
                            ->helperText('Empty = not printed. Your own wording; the CRM does not assume a policy.'),
                        Forms\Components\Textarea::make('policy_late_return')->label('Late return (printed on quotations and confirmations)')->rows(3)
                            ->helperText('Empty = not printed.'),
                        Forms\Components\Textarea::make('policy_fuel')->label('Fuel policy (printed on quotations)')->rows(2)
                            ->helperText('Empty = not printed. For example how the car is handed over and returned.'),
                        Forms\Components\Textarea::make('policy_fines')->label('Salik, fines and admin fee (printed on quotations)')->rows(2)
                            ->helperText('Empty = not printed. Your own wording.'),
                    ]),
                Forms\Components\Section::make('WhatsApp messages')
                    ->description($placeholders)
                    ->schema(collect(Options::MESSAGES)->map(fn ($meta, $key) => Forms\Components\Textarea::make($key)
                        ->label($meta[0])
                        ->rows(2)
                        ->required()
                        ->maxLength(1000))->values()->all()),
            ]);
    }

    protected function bookingsTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Bookings & returns')
            ->icon('heroicon-o-calendar-days')
            ->schema([
                Forms\Components\Section::make('Availability')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('pending_payment_holds')
                            ->label('"Waiting for payment" bookings keep the car')
                            ->helperText('Off = the dates stay open for others until the booking is confirmed.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('website_booking_hold_hours')
                            ->label('Website bookings: hours to pay')
                            ->numeric()->integer()->minValue(0)->maxValue(720)->suffix('hours')
                            ->helperText('A booking made on the website is "Waiting for payment". If no payment is recorded in this time, it is cancelled and the dates open again. 0 = never cancelled by itself.'),
                        Forms\Components\TextInput::make('booking_buffer_minutes')
                            ->label('Gap between bookings of the same car')
                            ->helperText('Time kept free for delivery, cleaning, etc. 0 = back-to-back allowed.')
                            ->numeric()->minValue(0)->maxValue(1440)->suffix('minutes')->required(),
                        Forms\Components\TextInput::make('trip_default_minutes')
                            ->label('Trip length when a trip has none set')
                            ->numeric()->minValue(15)->maxValue(1440)->suffix('minutes')->required(),
                        Forms\Components\TextInput::make('car_default_hours')
                            ->label('New car booking / quote length')
                            ->helperText('Return time filled in after picking the start.')
                            ->numeric()->minValue(1)->maxValue(720)->suffix('hours')->required(),

                    ]),
                Forms\Components\Section::make('Price suggestions')
                    ->description('Which rate the CRM suggests on bookings and quotations. Staff can always change the price.')
                    ->columns(3)
                    ->schema(collect(Rules::PRICING)->map(fn ($meta, $key) => Forms\Components\TextInput::make($key)
                        ->label($meta[0])
                        ->numeric()->minValue(1)->maxValue(365)->suffix($meta[1])->required())->values()->push(
                            Forms\Components\Select::make('transfer_km_rounding')
                                ->label('Road distance rounding (limousine)')
                                ->options(['0.1' => 'To 0.1 km (28.64 → 28.6)', 'up_1' => 'Up to the whole km (28.1 → 29)', '0.01' => 'Two decimals (28.64)'])
                                ->default('0.1')->required()->native(false)
                                ->helperText('How the Google Maps road distance is billed. Bookings already priced keep their distance.'),
                            Forms\Components\Select::make('hourly_increment_minutes')
                                ->label('Hourly rates are charged per')
                                ->options([15 => '15 minutes', 30 => '30 minutes', 60 => 'Full hour'])
                                ->native(false)->required()
                        )->all()),
                Forms\Components\Section::make('Late return')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('late_grace_minutes')
                            ->label('Grace period')
                            ->helperText('Not counted as late within this time.')
                            ->numeric()->minValue(0)->maxValue(1440)->suffix('minutes')->required(),
                        Forms\Components\Select::make('late_charge_mode')
                            ->label('Late charge')
                            ->options(Rules::LATE_MODES)
                            ->native(false)
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make('late_charge_amount')
                            ->label('Amount per late hour')
                            ->numeric()->minValue(0)->prefix('AED')
                            ->required(fn (Get $get): bool => $get('late_charge_mode') === 'per_hour')
                            ->visible(fn (Get $get): bool => $get('late_charge_mode') === 'per_hour'),
                        Forms\Components\Placeholder::make('late_note')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content('The amount is only filled in on the return form; staff can always change or remove it.'),
                    ]),
                Forms\Components\Section::make('Return charges')
                    ->description('The lines staff can fill in when a car / yacht comes back. Extra km, fuel and late return are used by the CRM and cannot be removed (you can rename them).')
                    ->schema([
                        Forms\Components\Repeater::make('return_charges')
                            ->hiddenLabel()
                            ->schema([
                                Forms\Components\Hidden::make('key'),
                                Forms\Components\Hidden::make('category'),
                                Forms\Components\TextInput::make('label')->required()->maxLength(60),
                                Forms\Components\Toggle::make('cars_only')->label('Cars only')->inline(false),
                            ])
                            ->columns(2)
                            ->reorderable()
                            ->addActionLabel('Add charge')
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->collapsible(),
                    ]),
            ]);
    }

    protected function quotesTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('VAT & quotations')
            ->icon('heroicon-o-receipt-percent')
            ->schema([
                Forms\Components\Section::make('VAT')
                    ->description('Off until you switch it on. The rate and whether prices include VAT are your choice.')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Toggle::make('vat_enabled')->label('Charge VAT')->live()->inline(false),
                        Forms\Components\TextInput::make('vat_rate')
                            ->label('VAT rate')
                            ->numeric()->minValue(0.01)->maxValue(100)->suffix('%')
                            ->required(fn (Get $get): bool => (bool) $get('vat_enabled'))
                            ->visible(fn (Get $get): bool => (bool) $get('vat_enabled')),
                        Forms\Components\Toggle::make('vat_inclusive')
                            ->label('Prices already include VAT')
                            ->inline(false)
                            ->visible(fn (Get $get): bool => (bool) $get('vat_enabled')),
                    ]),
                Forms\Components\Section::make('New leads: who gets them')
                    ->description('For leads from ads (Meta, TikTok, Snapchat), shared forms and the website. A lead that already has someone is never moved.')
                    ->schema([
                        Forms\Components\ToggleButtons::make('lead_assignment')->label('How')->inline()->live()
                            ->options(\App\Support\Leads\Assignment::MODES)->required(),
                        Forms\Components\Select::make('lead_assignment_people')->label('Round-robin group')->multiple()
                            ->options(fn () => \App\Support\Leads\Assignment::eligible()->orderBy('name')->pluck('name', 'id')->all())
                            ->helperText('Each new lead goes to the person in this group who got one longest ago.')
                            ->visible(fn (Forms\Get $get): bool => in_array($get('lead_assignment'), ['round_robin', 'by_service'], true)),
                        Forms\Components\Repeater::make('lead_assignment_by_service')->label('Person per service')
                            ->schema([
                                Forms\Components\Select::make('service')->options(\App\Enums\ServiceType::class)->required(),
                                Forms\Components\Select::make('user_id')->label('Person')->options(fn () => \App\Support\Leads\Assignment::eligible()->orderBy('name')->pluck('name', 'id')->all())->required(),
                            ])->columns(2)->defaultItems(0)->addActionLabel('Add a service')
                            ->helperText('Services not listed use the round-robin group.')
                            ->visible(fn (Forms\Get $get): bool => $get('lead_assignment') === 'by_service'),
                    ]),
                Forms\Components\Section::make('Quotations')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('quote_valid_days')
                            ->label('Default validity')
                            ->numeric()->minValue(1)->maxValue(365)->suffix('days')
                            ->helperText('Empty = staff choose the date on each quotation. It can always be changed per quote.'),
                        Forms\Components\Textarea::make('quote_terms')
                            ->label('Standard terms')
                            ->helperText('Added to new quotations; staff can edit them per quotation.')
                            ->rows(5)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('booking_requires_accepted_quote')
                            ->label('Bookings must come from an accepted quotation')
                            ->helperText('Off: staff can also book directly (walk-ins). On: a new confirmed booking needs an accepted quotation.')
                            ->inline(false),
                        $this->listField('quote_extras'),
                        $this->listField('quote_decline_reasons'),
                    ]),
                Forms\Components\Section::make('Discounts')
                    ->description('How much each role can take off the price list (rental, extras and the total together). Above the limit the price waits for a manager\'s OK before it can be sent or confirmed. Super Admin is never limited.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Repeater::make('discount_limits')
                            ->label('Limit per role')
                            ->schema([
                                Forms\Components\Select::make('role')->options(array_combine(Access::ROLES, Access::ROLES))->required()->native(false)->distinct(),
                                Forms\Components\TextInput::make('percent')->label('Most off')->numeric()->minValue(0)->maxValue(100)->suffix('%')
                                    ->placeholder('No limit'),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add a role')
                            ->helperText('Empty % = no limit for that role. A person with two roles gets the higher limit. Roles not listed use the single limit below.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('discount_reason_from_percent')
                            ->label('A reason is needed above')
                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                            ->helperText('Smaller discounts need no reason (they are still saved in the history). Empty = every change needs a reason. Price increases always need one.'),
                        Forms\Components\Toggle::make('discount_stacks_with_rules')
                            ->label('Allow a discount on top of a special rate')
                            ->helperText('Off: when a season or special rate already applies, no extra discount can be given.')
                            ->inline(false),
                        Forms\Components\TextInput::make('price_max_discount_percent')
                            ->label('Roles not listed above: most off')
                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                            ->helperText('Empty = no limit. People with "Pricing" rights are not limited by this one.'),
                        Forms\Components\Toggle::make('price_approval_required')
                            ->label('Roles not listed above: wait for a manager instead of refusing')
                            ->helperText('Off: a bigger discount is refused. On: it waits for a manager. (Roles listed above always wait.)')
                            ->inline(false),
                    ]),
                Forms\Components\Section::make('Advance to confirm')
                    ->description('The part of the price paid to confirm a booking. The security deposit is separate and never counts toward it. Staff can change the % on each quotation and booking.')
                    ->columns(3)
                    ->schema([
                        ...collect(['car' => 'Car rental', 'yacht' => 'Yacht charter', 'trip' => 'Yacht trip', 'package' => 'Trip / package', 'transfer' => 'Limousine & transfer'])
                            ->map(fn ($label, $key) => Forms\Components\TextInput::make('advance_percent.'.$key)->label($label)
                                ->numeric()->minValue(0)->maxValue(100)->suffix('%')->placeholder('No advance'))
                            ->values()->all(),
                        Forms\Components\TextInput::make('advance_min_percent')
                            ->label('Lowest advance staff can set')
                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                            ->helperText('Lower needs "Approve prices" and a reason. Empty = no minimum.'),
                        Forms\Components\Select::make('balance_due_rule')
                            ->label('The balance is due')
                            ->options(\App\Support\Pricing\Advance::DUE_RULES)
                            ->native(false)->required()->live(),
                        Forms\Components\TextInput::make('balance_due_hours')
                            ->label('Hours before the start')
                            ->numeric()->integer()->minValue(0)->maxValue(720)->suffix('hours')
                            ->visible(fn (Get $get): bool => $get('balance_due_rule') === 'before_start'),
                        Forms\Components\Toggle::make('confirm_on_advance')
                            ->label('Confirm the booking once the advance is paid')
                            ->helperText('On: a "Waiting for payment" booking becomes "Confirmed" by itself when the payments reach the advance. Off: staff confirm by hand.')
                            ->inline(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function documentsTab(): Forms\Components\Tabs\Tab
    {
        $typeOptions = fn (Get $get): array => collect($get('document_types') ?? [])
            ->filter(fn ($row) => filled($row['label'] ?? null))
            ->mapWithKeys(fn ($row) => [($row['key'] ?? null) ?: Options::keyFor($row['label'], []) => $row['label']])
            ->all();

        return Forms\Components\Tabs\Tab::make('Documents')
            ->icon('heroicon-o-identification')
            ->schema([
                Forms\Components\Section::make('Document types')
                    ->description('The documents you collect from customers. Switch on "Customer can upload" for the ones you send upload links for.')
                    ->schema([
                        Forms\Components\Repeater::make('document_types')
                            ->hiddenLabel()
                            ->schema([
                                Forms\Components\Hidden::make('key'),
                                Forms\Components\TextInput::make('label')->label('Name')->required()->maxLength(80)->live(onBlur: true),
                                Forms\Components\Toggle::make('on_link')->label('Customer can upload')->default(true)->inline(false),
                            ])
                            ->columns(2)
                            ->reorderable()
                            ->addActionLabel('Add document type')
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->collapsible()
                            ->live(),
                    ]),
                Forms\Components\Section::make('Required for each booking type')
                    ->description('Nothing is required until you tick it here. Staff can always ask for other documents on the upload link.')
                    ->columns(2)
                    ->schema([
                        ...collect(self::KINDS)->map(fn ($label, $kind) => Forms\Components\CheckboxList::make('req_'.$kind)
                            ->label($label)
                            ->options($typeOptions)
                            ->columns(2))->values()->all(),
                        Forms\Components\Toggle::make('doc_block_handover')
                            ->label('Block hand over / start until required documents are approved')
                            ->helperText('Managers can still override; the override is recorded.')
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('doc_block_confirm')
                            ->label('Only confirm a booking once required documents are approved')
                            ->helperText('Until then it can be created as Waiting for payment.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('upload_link_hours')
                            ->label('Upload links work for')
                            ->numeric()->minValue(1)->maxValue(720)->suffix('hours')
                            ->helperText('Staff can change it on each link.')
                            ->required(),
                        $this->listField('doc_reject_reasons'),
                    ]),
            ]);
    }

    protected function listsTab(): Forms\Components\Tabs\Tab
    {
        $keys = ['lost_reasons', 'cancel_reasons', 'pickup_locations', 'booking_sources', 'car_types', 'car_brands', 'car_locations', 'package_types', 'marinas'];

        return Forms\Components\Tabs\Tab::make('Lists')
            ->icon('heroicon-o-list-bullet')
            ->schema([
                Forms\Components\Section::make('Suggestion lists')
                    ->description('Shown as suggestions while typing. Staff can still type something else. Press Enter after each item.')
                    ->columns(2)
                    ->schema(array_map(fn ($key) => $this->listField($key), $keys)),
            ]);
    }

    protected function moneyTab(): Forms\Components\Tabs\Tab
    {
        $repeater = fn (string $type) => Forms\Components\Repeater::make('categories_'.$type)
            ->label($type === 'income' ? 'Money in' : 'Money out')
            ->schema([
                Forms\Components\Hidden::make('key'),
                Forms\Components\TextInput::make('label')->hiddenLabel()->required()->maxLength(60),
            ])
            ->addActionLabel('Add category')
            ->reorderable();

        return Forms\Components\Tabs\Tab::make('Money categories')
            ->icon('heroicon-o-banknotes')
            ->schema([
                Forms\Components\Section::make('Categories for payments and expenses')
                    ->description('Rename or add categories. Ones the CRM uses itself (rentals, deposits, deposit refunds, extras…) come back if removed, so reports keep working.')
                    ->columns(2)
                    ->schema([$repeater('income'), $repeater('expense')]),
                Forms\Components\Section::make('Fines, tolls and deposits')
                    ->description('Fines and tolls are passed on to the customer who had the car: not revenue. Admin and service fees are revenue. Empty = no fee.')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('fine_admin_fee_mode')->label('Admin fee per fine')->native(false)->selectablePlaceholder(false)
                            ->options(['fixed' => 'Fixed amount', 'percent' => '% of the fine'])->live(),
                        Forms\Components\TextInput::make('fine_admin_fee_amount')->label(fn (Forms\Get $get) => $get('fine_admin_fee_mode') === 'percent' ? 'Percent' : 'Amount')
                            ->numeric()->minValue(0)->placeholder('No admin fee')
                            ->prefix(fn (Forms\Get $get) => $get('fine_admin_fee_mode') === 'percent' ? null : 'AED')->suffix(fn (Forms\Get $get) => $get('fine_admin_fee_mode') === 'percent' ? '%' : null),
                        Forms\Components\TextInput::make('fine_admin_fee_min')->label('Minimum fee')->numeric()->minValue(0)->prefix('AED')->placeholder('None'),
                        Forms\Components\TextInput::make('toll_fee_salik')->label('Service fee per Salik trip')->numeric()->minValue(0)->prefix('AED')->placeholder('None'),
                        Forms\Components\TextInput::make('toll_fee_darb')->label('Service fee per Darb trip')->numeric()->minValue(0)->prefix('AED')->placeholder('None'),
                        Forms\Components\TextInput::make('toll_fee_parking')->label('Service fee per parking charge')->numeric()->minValue(0)->prefix('AED')->placeholder('None'),
                        Forms\Components\Toggle::make('toll_auto_charge')->label('Add matched tolls to the customer\'s booking straight away')->columnSpan(2),
                        Forms\Components\TextInput::make('deposit_hold_days')->label('Hold deposits after the return for')->numeric()->minValue(1)->maxValue(90)->suffix('days')
                            ->placeholder('No hold')->helperText('Fines and tolls arrive late. Empty = refund at the return as before.'),
                    ]),
                Forms\Components\Section::make('Expense controls')
                    ->schema([
                        Forms\Components\Toggle::make('expense_approval_required')
                            ->label('Expenses entered by staff without finance rights wait for approval')
                            ->helperText('Until Finance approves them they are shown apart and do not count in profit. Finance and admins are never held.'),
                    ]),
            ]);
    }

    protected function driversTab(): Forms\Components\Tabs\Tab
    {
        $statusNote = 'Changing these never removes jobs, bookings or history.';

        return Forms\Components\Tabs\Tab::make('Drivers')
            ->icon('heroicon-o-truck')
            ->schema([
                Forms\Components\Section::make('Driver access')
                    ->description($statusNote)
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('driver_login_enabled')
                            ->label('Drivers can log in')
                            ->helperText('Off = no driver can open the CRM. Their jobs stay on the bookings; staff do the handovers.'),
                        Forms\Components\Toggle::make('driver_sees_customer_phone')
                            ->label('Drivers see the customer\'s phone number'),
                        Forms\Components\Toggle::make('driver_sees_instructions')
                            ->label('Drivers see the booking\'s driver instructions'),
                        Forms\Components\CheckboxList::make('driver_steps')
                            ->label('Job steps drivers can set')
                            ->options(\App\Models\BookingJob::DRIVER_STEPS)
                            ->helperText('Handover and collection are set below.'),
                        Forms\Components\Toggle::make('driver_can_handover')->label('Drivers can hand over cars (with the handover check)'),
                        Forms\Components\Toggle::make('handover_requires_approval')
                            ->label('The office approves the driver\'s handover check before the car is released')
                            ->helperText('The driver sends the check and photos, waits for the OK on the phone, then hands over the keys.'),
                        Forms\Components\Toggle::make('driver_can_return')->label('Drivers can collect cars (with the return check)'),
                        Forms\Components\Toggle::make('driver_can_report_damage')->label('Drivers can report damage'),
                        Forms\Components\Placeholder::make('money_note')->hiddenLabel()
                            ->content('Drivers never record payments, deposits, refunds or charges, and cannot change prices. The office completes every return.'),
                    ]),
                Forms\Components\Section::make('Driver self-service')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('driver_self_service')
                            ->label('Drivers can update some of their own details')
                            ->live(),
                        Forms\Components\CheckboxList::make('driver_editable_fields')
                            ->label('Which details')
                            ->options(\App\Models\DriverProfile::SELF_SERVICE_FIELDS)
                            ->visible(fn (Get $get): bool => (bool) $get('driver_self_service')),
                    ]),
                Forms\Components\Section::make('Handover and collection checks')
                    ->description('What the driver must fill in before a handover or collection is saved. Staff forms are not changed.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('handover_require_km')->label('Handover: odometer required'),
                        Forms\Components\Toggle::make('return_require_km')->label('Collection: odometer required'),
                        Forms\Components\Toggle::make('handover_require_fuel')->label('Handover: fuel level required'),
                        Forms\Components\Toggle::make('return_require_fuel')->label('Collection: fuel level required'),
                        Forms\Components\TextInput::make('handover_min_photos')->label('Handover: minimum photos')->numeric()->minValue(0)->maxValue(20)->required(),
                        Forms\Components\TextInput::make('return_min_photos')->label('Collection: minimum photos')->numeric()->minValue(0)->maxValue(20)->required(),
                        Forms\Components\TagsInput::make('handover_checklist')->label('Handover checklist')->placeholder('Add item')->reorderable(),
                        Forms\Components\TagsInput::make('return_checklist')->label('Collection checklist')->placeholder('Add item')->reorderable(),
                        Forms\Components\Toggle::make('checklist_all_required')->label('Every checklist item must be ticked')->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Assigning drivers')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('driver_job_gap_minutes')
                            ->label('Minimum time between two jobs of the same driver')
                            ->helperText('A delivery or collection closer than this to another job counts as a clash. With-driver bookings keep the driver for the whole time.')
                            ->numeric()->minValue(0)->maxValue(1440)->suffix('minutes')->required(),
                        Forms\Components\Toggle::make('driver_block_incomplete')
                            ->label('Only assign drivers whose required documents are complete and not expired'),
                    ]),
                Forms\Components\Section::make('Driver profile and documents')
                    ->columns(2)
                    ->schema([
                        Forms\Components\CheckboxList::make('driver_required_fields')
                            ->label('Required profile fields')
                            ->options(\App\Models\DriverProfile::FIELDS)
                            ->columns(2)
                            ->helperText('Name, phone and email are always required.'),
                        Forms\Components\CheckboxList::make('driver_required_documents')
                            ->label('Required documents')
                            ->options(\App\Models\DriverDocument::TYPES)
                            ->columns(2)
                            ->helperText('Missing or expired ones show on the driver list and the driver\'s profile.'),
                        Forms\Components\TextInput::make('driver_doc_warn_days')
                            ->label('Warn before a document expires')
                            ->numeric()->minValue(0)->maxValue(365)->suffix('days')->required(),
                        Forms\Components\Toggle::make('driver_doc_reminders')
                            ->label('Send expiry reminders (to the driver and the people who manage drivers)')
                            ->helperText('Sent once a day by the scheduled task on the server.'),
                    ]),
                Forms\Components\Section::make('Driver phone')
                    ->description('How drivers work with their jobs on the phone (4 Nov).')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('driver_can_decline')->label('Drivers can decline a job (with a reason)')
                            ->helperText('It shows under "Needs a driver" on the dispatch board.'),
                        Forms\Components\TextInput::make('job_accept_minutes')->label('Tell the office when a new job is not accepted within')
                            ->numeric()->minValue(5)->maxValue(1440)->suffix('minutes')->placeholder('No reminder'),
                        Forms\Components\Toggle::make('driver_updates_to_customer')->label('Tell the customer when the driver is on the way or running late'),
                        Forms\Components\Select::make('handover_signature')->label('Customer signature at handover and collection')->native(false)->selectablePlaceholder(false)
                            ->options(['off' => 'Off', 'optional' => 'Optional', 'required' => 'Required']),
                        Forms\Components\CheckboxList::make('driver_languages')->label('Languages on the driver screens')
                            ->options(['en' => 'English', 'ur' => 'Urdu', 'ar' => 'Arabic', 'hi' => 'Hindi (screens in English until translated)'])->columns(2)
                            ->helperText('Each driver picks theirs under My profile.'),
                        Forms\Components\Fieldset::make('Live location')->columns(3)->schema([
                            Forms\Components\Toggle::make('driver_location_enabled')->label('Share driver location during jobs')->columnSpanFull()
                                ->helperText('Only while a job is active (on the way, arrived, chauffeur ride, charter), with the job open on the phone. Tell the drivers in writing before you switch this on.'),
                            Forms\Components\TextInput::make('location_interval_seconds')->label('Send every')->numeric()->minValue(15)->maxValue(600)->suffix('seconds'),
                            Forms\Components\TextInput::make('location_retention_days')->label('Keep for')->numeric()->minValue(1)->maxValue(365)->suffix('days'),
                        ]),
                    ]),
                Forms\Components\Section::make('Notifications')
                    ->description('What each group gets by default. Everyone can change their own under "My notifications". Phone notifications need the app installed (Settings › Apps).')
                    ->columns(3)
                    ->schema([
                        Forms\Components\CheckboxList::make('chan_staff')->label('Staff')->options(self::CHANNELS),
                        Forms\Components\CheckboxList::make('chan_driver')->label('Drivers & crew')->options(self::CHANNELS),
                        Forms\Components\CheckboxList::make('chan_customer')->label('Customers with an account')->options(self::CHANNELS),
                        Forms\Components\Toggle::make('notify_drivers_by_email')->label('Also email drivers about every job')->columnSpan(1),
                        Forms\Components\Toggle::make('notify_staff_by_email')->label('Also email staff about everything')->columnSpan(2),
                        Forms\Components\TextInput::make('lead_accept_minutes')->label('Remind when a new lead is not accepted within')
                            ->numeric()->minValue(5)->maxValue(1440)->suffix('minutes')->placeholder('No reminder'),
                        Forms\Components\TextInput::make('job_soon_minutes')->label('Tell the driver a job starts in')
                            ->numeric()->minValue(10)->maxValue(720)->suffix('minutes')->placeholder('No reminder'),
                        Forms\Components\TextInput::make('balance_reminder_days')->label('Remind finance a balance is due in')
                            ->numeric()->minValue(0)->maxValue(30)->suffix('days')->placeholder('No reminder'),
                        Forms\Components\Toggle::make('quote_expiry_customer_reminder')->label('Remind the customer before a quotation expires'),
                        Forms\Components\Toggle::make('late_return_customer_reminder')->label('Message the customer when a return is late'),
                    ]),
            ]);
    }

    protected function rolesTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Roles & permissions')
            ->icon('heroicon-o-shield-check')
            ->schema([
                Forms\Components\Section::make('Agents and customer accounts')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('agent_own_records_only')
                            ->label('Agents only see their own leads, customers, quotations and bookings')
                            ->helperText('Records assigned to them or created by them. Off = agents see everything their permissions allow.')
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('customer_accounts_enabled')
                            ->label('Customers can log in to their account')
                            ->helperText('Invite a customer from their page. Off = no customer can log in; nothing is deleted.'),
                        Forms\Components\TextInput::make('invite_link_hours')
                            ->label('Login links work for')
                            ->numeric()->minValue(1)->maxValue(720)->suffix('hours')->required(),
                        Forms\Components\Toggle::make('customer_sees_quotations')->label('Customers see their quotations'),
                        Forms\Components\Toggle::make('customer_sees_payments')->label('Customers see their payments and balance'),
                        Forms\Components\Toggle::make('customer_can_upload_documents')->label('Customers can upload documents from their account'),
                        Forms\Components\Toggle::make('account_documents_gate')->label('Documents first')
                            ->helperText('When a customer logs in and documents needed for their bookings or quotations (Documents section) are missing or not approved yet, they see only "My documents" until our team approves them.')
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Customer portal accounts')
                    ->description('A customer account is made automatically, and the customer gets an email to choose a password. No password is ever sent by email.')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Toggle::make('portal_on_lead')->label('When a lead with an email comes in'),
                        Forms\Components\Toggle::make('portal_on_quote')->label('When a quotation is sent'),
                        Forms\Components\Toggle::make('portal_on_booking')->label('When a booking is made'),
                        Forms\Components\Toggle::make('portal_new_device_email')->label('Email the customer when they sign in on a new device')->columnSpanFull(),
                        Forms\Components\TagsInput::make('disposable_email_domains')->label('Never make accounts for these email domains')
                            ->helperText('Throw-away email services. Leads from them still come in; they just get no account.')->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Online payments')
                    ->description('Gateways (Stripe, Paymob, Tabby, Tamara, PayPal) are connected in Settings › Integrations. Money is recorded only when the provider confirms it.')
                    ->schema([
                        Forms\Components\TextInput::make('payment_link_days')
                            ->label('A payment link stays open for (days)')
                            ->numeric()->minValue(1)->maxValue(365)
                            ->placeholder('Until paid or cancelled')
                            ->helperText('Empty = no expiry. Staff can cancel a link any time.'),
                    ]),
                Forms\Components\Section::make('Audit log')
                    ->description('Every sign-in, change, export and document opening is kept in Settings › Audit log. Lines cannot be edited or deleted from the CRM.')
                    ->schema([
                        Forms\Components\TextInput::make('audit_retention_days')
                            ->label('Keep the audit log for (days)')
                            ->numeric()->minValue(90)->maxValue(3650)
                            ->placeholder('Keep forever')
                            ->helperText('Empty = keep everything. If set (90 days or more), older lines are removed each night.'),
                    ]),
                // 5 Nov ("Make it simple", Part A): four roles, shown as a plain table. The ability matrix is
                // for experts only and sits folded away under "Advanced".
                Forms\Components\Section::make('What each role can do')
                    ->description('Four roles: Admin, Agent, Accounts and Driver. Give each team member one role under Team & roles.')
                    ->schema([
                        Forms\Components\Placeholder::make('roles_table')->hiddenLabel()
                            ->content(fn (): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(view('filament.pages.partials.role-table', [
                                'roles' => Access::ROLE_DESCRIPTIONS,
                                'rows' => collect(Access::LABELS)->map(fn ($label, $ability) => ['label' => $label, 'roles' => array_values(array_intersect(Access::rolesFor($ability), Access::ROLES))])->values()->all(),
                            ])->render())),
                    ]),
                Forms\Components\Section::make('Advanced: change what a role can do')
                    ->description('For experts only. Leave as it is unless Crew Innovations asks you to change it. Admin always keeps these settings.')
                    ->collapsible()->collapsed()
                    ->columns(2)
                    ->schema(collect(Access::LABELS)->map(fn ($label, $ability) => Forms\Components\CheckboxList::make('perm_'.self::permKey($ability))
                        ->label($label)
                        ->options(array_combine(Access::ROLES, Access::ROLES))
                        ->disableOptionWhen(fn (string $value): bool => $ability === 'manage-team' && $value === 'Admin')
                        ->columns(3))->values()->all()),
            ]);
    }

    protected function listField(string $key): Forms\Components\TagsInput
    {
        [$title, $help] = Options::LISTS[$key];

        return Forms\Components\TagsInput::make('list_'.$key)
            ->label($title)
            ->helperText($help)
            ->reorderable()
            ->placeholder('Add item');
    }

    public const DRIVER_AND_ACCOUNT_KEYS = [
        'agent_own_records_only', 'customer_accounts_enabled', 'customer_can_upload_documents', 'account_documents_gate', 'customer_sees_payments', 'customer_sees_quotations', 'invite_link_hours',
        'driver_login_enabled', 'driver_self_service', 'driver_editable_fields', 'driver_sees_customer_phone', 'driver_sees_instructions', 'driver_steps',
        'driver_can_handover', 'handover_requires_approval', 'driver_can_return', 'driver_can_report_damage',
        'handover_require_km', 'handover_require_fuel', 'handover_min_photos', 'return_require_km', 'return_require_fuel', 'return_min_photos',
        'checklist_all_required', 'handover_checklist', 'return_checklist',
        'driver_job_gap_minutes', 'driver_required_fields', 'driver_required_documents', 'driver_block_incomplete',
        'driver_doc_warn_days', 'driver_doc_reminders', 'notify_drivers_by_email', 'notify_staff_by_email',
        // 28 Oct: pricing and sales workflow
        'hourly_increment_minutes', 'price_max_discount_percent', 'price_approval_required', 'booking_requires_accepted_quote', 'doc_block_confirm',
        'expense_approval_required', 'audit_retention_days', 'payment_link_days',
        'lead_assignment', 'lead_assignment_people', 'transfer_km_rounding',
    ];

    /** 4 Nov (sections 10, 11, 12): saved with their own clean-up in save(). */
    public const CHANNELS = ['database' => 'In the CRM (bell)', 'push' => 'Phone notification', 'mail' => 'Email'];

    protected const PHASE4_KEYS = ['fine_admin_fee_mode', 'fine_admin_fee_amount', 'fine_admin_fee_min', 'toll_auto_charge', 'deposit_hold_days'];

    protected const PHASE3_KEYS = [
        'driver_can_decline', 'job_accept_minutes', 'driver_updates_to_customer', 'handover_signature', 'driver_languages',
        'driver_location_enabled', 'location_interval_seconds', 'location_retention_days',
    ];

    protected const PHASE2_KEYS = [
        'lead_accept_minutes', 'job_soon_minutes', 'balance_reminder_days', 'quote_expiry_customer_reminder', 'late_return_customer_reminder',
        'portal_on_lead', 'portal_on_quote', 'portal_on_booking', 'portal_new_device_email', 'disposable_email_domains',
    ];

    protected const PHASE1_KEYS = [
        'policy_fuel', 'policy_fines',
        'company_whatsapp', 'company_website', 'company_logo', 'company_logo_dark', 'company_stamp',
        'bank_name', 'bank_account_name', 'bank_iban', 'bank_swift',
        'discount_limits', 'discount_reason_from_percent', 'discount_stacks_with_rules',
        'advance_percent', 'advance_min_percent', 'balance_due_rule', 'balance_due_hours', 'confirm_on_advance',
    ];

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $out = [];

        // Document types: keep existing keys so old documents keep their name.
        $taken = [];
        $out['document_types'] = collect($data['document_types'] ?? [])
            ->filter(fn ($r) => filled($r['label'] ?? null))
            ->map(function ($r) use (&$taken) {
                $key = filled($r['key'] ?? null) && ! in_array($r['key'], $taken, true) ? $r['key'] : Options::keyFor($r['label'], $taken);
                $taken[] = $key;

                return ['key' => $key, 'label' => trim($r['label']), 'on_link' => (bool) ($r['on_link'] ?? false)];
            })->values()->all();
        $validTypes = array_column($out['document_types'], 'key');

        $out['doc_requirements'] = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $out['doc_requirements'][$kind] = array_values(array_intersect((array) ($data['req_'.$kind] ?? []), $validTypes));
        }

        $taken = [];
        $out['return_charges'] = collect($data['return_charges'] ?? [])
            ->filter(fn ($r) => filled($r['label'] ?? null))
            ->map(function ($r) use (&$taken) {
                $key = filled($r['key'] ?? null) && ! in_array($r['key'], $taken, true) ? $r['key'] : Options::keyFor($r['label'], $taken);
                $taken[] = $key;

                return ['key' => $key, 'label' => trim($r['label']), 'category' => $r['category'] ?: 'extras', 'cars_only' => (bool) ($r['cars_only'] ?? false)];
            })->values()->all();

        foreach (['income', 'expense'] as $type) {
            // Existing categories keep their key, so renaming never splits old records.
            $taken = [];
            $out['categories_'.$type] = collect($data['categories_'.$type] ?? [])
                ->filter(fn ($r) => filled($r['label'] ?? null))
                ->map(function ($r) use (&$taken) {
                    $key = filled($r['key'] ?? null) && ! in_array($r['key'], $taken, true) ? $r['key'] : Options::keyFor($r['label'], $taken);
                    $taken[] = $key;

                    return ['key' => $key, 'label' => trim($r['label'])];
                })->values()->all();
        }

        foreach (array_keys(Options::LISTS) as $key) {
            if (! array_key_exists('list_'.$key, $data)) {
                continue; // list not shown on the page (e.g. old yacht lists): keep what is stored
            }
            $out['list_'.$key] = array_values(array_unique(array_filter(array_map('trim', (array) ($data['list_'.$key] ?? [])))));
        }

        foreach (array_keys(Options::MESSAGES) as $key) {
            $out[$key] = trim((string) ($data[$key] ?? ''));
        }

        $perms = [];
        foreach (array_keys(Access::ABILITIES) as $ability) {
            $perms[$ability] = array_values(array_intersect((array) ($data['perm_'.self::permKey($ability)] ?? []), Access::ROLES));
        }
        $perms['manage-team'] = array_values(array_unique([...$perms['manage-team'], 'Admin']));
        // 5 Nov: stored only when it differs from the defaults, so later default changes still apply.
        $defaults = collect(Access::ABILITIES)->map(fn ($r) => array_values(array_intersect($r, Access::ROLES)))->all();
        $same = collect($perms)->every(fn ($r, $a) => collect($r)->sort()->values()->all() === collect($defaults[$a] ?? [])->sort()->values()->all());
        $out['role_abilities_v2'] = $same ? null : $perms;

        foreach ([
            'company_name', 'company_trn', 'company_phone', 'company_email', 'company_address', 'bank_details',
            'company_legal_name', 'company_trade_licence', 'policy_cancellation', 'policy_late_return',
            'vat_enabled', 'vat_rate', 'vat_inclusive', 'quote_valid_days', 'quote_terms',
            'doc_block_handover', 'upload_link_hours',
            'pending_payment_holds', 'website_booking_hold_hours', 'booking_buffer_minutes', 'trip_default_minutes', 'car_default_hours', 'yacht_default_hours',
            'late_grace_minutes', 'late_charge_mode', 'late_charge_amount',
            ...array_keys(Rules::PRICING),
            'website_origins',
            'website_phone', 'website_whatsapp', 'website_email', 'website_address', 'website_map_url',
            'website_instagram', 'website_facebook', 'website_tiktok', 'website_snapchat',
            ...self::DRIVER_AND_ACCOUNT_KEYS,
            ...self::PHASE1_KEYS,
            ...self::PHASE2_KEYS,
            ...self::PHASE3_KEYS,
            ...self::PHASE4_KEYS,
        ] as $key) {
            if (! array_key_exists($key, $data)) {
                continue; // field not shown on the page (old yacht settings): keep what is stored
            }
            $out[$key] = $data[$key];
        }
        if (empty($out['vat_enabled'])) {
            $out['vat_rate'] = null;
            $out['vat_inclusive'] = false;
        }
        if (($out['late_charge_mode'] ?? null) !== 'per_hour') {
            $out['late_charge_amount'] = null;
        }
        if (array_key_exists('website_booking_hold_hours', $out)) {
            $out['website_booking_hold_hours'] = max(0, (int) $out['website_booking_hold_hours']);
        }
        foreach (['pending_payment_holds', 'vat_enabled', 'vat_inclusive', 'doc_block_handover'] as $bool) {
            $out[$bool] = (bool) $out[$bool];
        }
        foreach (self::DRIVER_AND_ACCOUNT_KEYS as $key) {
            if (! array_key_exists($key, $out)) {
                continue;
            }
            $default = Setting::DEFAULTS[$key] ?? null;
            $out[$key] = match (true) {
                is_bool($default) => (bool) $out[$key],
                is_int($default) => (int) $out[$key],
                is_array($default) => array_values(array_unique(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, (array) $out[$key])))),
                default => $out[$key],
            };
        }
        if (array_key_exists('lead_assignment', $out)) {
            $out['lead_assignment'] = array_key_exists((string) $out['lead_assignment'], \App\Support\Leads\Assignment::MODES) ? $out['lead_assignment'] : 'manual';
            $out['lead_assignment_people'] = array_values(array_map('intval', array_filter((array) ($out['lead_assignment_people'] ?? []))));
            $out['lead_assignment_by_service'] = collect((array) ($data['lead_assignment_by_service'] ?? []))
                ->filter(fn ($r) => ! empty($r['service']) && ! empty($r['user_id']))
                ->map(fn ($r) => ['service' => $r['service'] instanceof \BackedEnum ? $r['service']->value : (string) $r['service'], 'user_id' => (int) $r['user_id']])
                ->unique('service')->values()->all();
        }
        if (array_key_exists('payment_link_days', $out)) {
            $out['payment_link_days'] = filled($out['payment_link_days']) && (int) $out['payment_link_days'] > 0 ? (int) $out['payment_link_days'] : null;
        }
        // 4 Nov: discounts and advance.
        $pct = fn ($v) => $v === null || $v === '' ? null : max(0.0, min(100.0, (float) $v));
        if (array_key_exists('discount_limits', $out)) {
            $out['discount_limits'] = collect((array) $out['discount_limits'])
                ->filter(fn ($r) => in_array($r['role'] ?? null, Access::ROLES, true))
                ->map(fn ($r) => ['role' => $r['role'], 'percent' => $pct($r['percent'] ?? null)])
                ->unique('role')->values()->all();
            $out['discount_reason_from_percent'] = $pct($out['discount_reason_from_percent'] ?? null);
            $out['discount_stacks_with_rules'] = (bool) ($out['discount_stacks_with_rules'] ?? false);
        }
        if (array_key_exists('advance_percent', $out)) {
            $out['advance_percent'] = collect(['car', 'yacht', 'trip', 'package', 'transfer'])
                ->mapWithKeys(fn ($k) => [$k => $pct(((array) $out['advance_percent'])[$k] ?? null)])->all();
            $out['advance_min_percent'] = $pct($out['advance_min_percent'] ?? null);
            $out['balance_due_rule'] = array_key_exists((string) ($out['balance_due_rule'] ?? ''), \App\Support\Pricing\Advance::DUE_RULES) ? $out['balance_due_rule'] : 'standard';
            $out['balance_due_hours'] = max(0, (int) ($out['balance_due_hours'] ?? 48));
            $out['confirm_on_advance'] = (bool) ($out['confirm_on_advance'] ?? false);
        }
        // 4 Nov (Phase 4): fines, tolls, deposits.
        if (array_key_exists('fine_admin_fee_mode', $out)) {
            $num = fn ($v) => $v === null || $v === '' || (float) $v <= 0 ? null : round((float) $v, 2);
            $out['fine_admin_fee_mode'] = $out['fine_admin_fee_mode'] === 'percent' ? 'percent' : 'fixed';
            $out['fine_admin_fee_amount'] = $num($out['fine_admin_fee_amount']);
            $out['fine_admin_fee_min'] = $num($out['fine_admin_fee_min']);
            $out['toll_auto_charge'] = (bool) $out['toll_auto_charge'];
            $out['deposit_hold_days'] = filled($out['deposit_hold_days']) && (int) $out['deposit_hold_days'] > 0 ? (int) $out['deposit_hold_days'] : null;
            $out['toll_service_fee'] = collect(['salik', 'darb', 'parking'])->mapWithKeys(fn ($t) => [$t => $num($data['toll_fee_'.$t] ?? null)])->filter()->all();
        }
        // 4 Nov (Phase 3): driver phone.
        if (array_key_exists('driver_can_decline', $out)) {
            foreach (['driver_can_decline', 'driver_updates_to_customer', 'driver_location_enabled'] as $k) {
                $out[$k] = (bool) ($out[$k] ?? false);
            }
            $out['job_accept_minutes'] = filled($out['job_accept_minutes'] ?? null) && (int) $out['job_accept_minutes'] > 0 ? (int) $out['job_accept_minutes'] : null;
            $out['handover_signature'] = in_array($out['handover_signature'] ?? null, ['off', 'optional', 'required'], true) ? $out['handover_signature'] : 'optional';
            $out['driver_languages'] = array_values(array_intersect((array) ($out['driver_languages'] ?? []), ['en', 'ur', 'ar', 'hi'])) ?: ['en'];
            $out['location_interval_seconds'] = max(15, min(600, (int) ($out['location_interval_seconds'] ?: 60)));
            $out['location_retention_days'] = max(1, min(365, (int) ($out['location_retention_days'] ?: 30)));
            if ($out['driver_location_enabled'] !== (bool) Setting::read('driver_location_enabled', false)) {
                \App\Models\AuditLog::event('settings', 'Driver live location switched '.($out['driver_location_enabled'] ? 'ON' : 'off'));
            }
        }
        // 4 Nov (Phase 2): notifications and portal accounts.
        if (array_key_exists('chan_staff', $data)) {
            $out['notification_channels'] = [
                '*' => array_values(array_intersect((array) $data['chan_staff'], array_keys(self::CHANNELS))),
                'Driver' => array_values(array_intersect((array) $data['chan_driver'], array_keys(self::CHANNELS))),
                'Customer' => array_values(array_intersect((array) $data['chan_customer'], array_keys(self::CHANNELS))),
            ];
        }
        $mins = fn ($v) => filled($v) && (int) $v > 0 ? (int) $v : null;
        foreach (['lead_accept_minutes', 'job_soon_minutes'] as $k) {
            if (array_key_exists($k, $out)) {
                $out[$k] = $mins($out[$k]);
            }
        }
        if (array_key_exists('followup_enabled', $out)) {
            $out['followup_enabled'] = (bool) $out['followup_enabled'];
        }
        if (array_key_exists('followup_hours', $out)) {
            $out['followup_hours'] = filled($out['followup_hours']) ? implode(', ', array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) $out['followup_hours'])))) : null;
        }
        foreach (['followup_quiet_from', 'followup_quiet_to'] as $k) {
            if (array_key_exists($k, $out)) {
                $out[$k] = filled($out[$k]) ? min(23, max(0, (int) $out[$k])) : null;
            }
        }
        if (array_key_exists('balance_reminder_days', $out)) {
            $out['balance_reminder_days'] = filled($out['balance_reminder_days']) ? max(0, (int) $out['balance_reminder_days']) : null;
        }
        foreach (['quote_expiry_customer_reminder', 'late_return_customer_reminder', 'portal_on_lead', 'portal_on_quote', 'portal_on_booking', 'portal_new_device_email'] as $k) {
            if (array_key_exists($k, $out)) {
                $out[$k] = (bool) $out[$k];
            }
        }
        if (array_key_exists('disposable_email_domains', $out)) {
            $out['disposable_email_domains'] = collect((array) $out['disposable_email_domains'])
                ->map(fn ($d) => ltrim(mb_strtolower(trim((string) $d)), '@'))->filter()->unique()->values()->all();
        }
        foreach (['company_logo', 'company_logo_dark', 'company_stamp'] as $file) {
            if (array_key_exists($file, $out) && is_array($out[$file])) {
                $out[$file] = collect($out[$file])->first(); // a single upload
            }
        }

        $max = $out['price_max_discount_percent'] ?? null;
        $out['price_max_discount_percent'] = $max === null || $max === '' ? null : (float) $max;
        $out['transfer_km_rounding'] = in_array((string) ($out['transfer_km_rounding'] ?? '0.1'), ['0.1', 'up_1', '0.01'], true) ? (string) $out['transfer_km_rounding'] : '0.1';
        $out['hourly_increment_minutes'] = in_array((int) ($out['hourly_increment_minutes'] ?? 60), [15, 30, 60], true) ? (int) $out['hourly_increment_minutes'] : 60;
        $out['driver_steps'] = array_values(array_intersect($out['driver_steps'] ?? [], array_keys(\App\Models\BookingJob::DRIVER_STEPS)));
        $out['driver_editable_fields'] = array_values(array_intersect($out['driver_editable_fields'] ?? [], array_keys(\App\Models\DriverProfile::SELF_SERVICE_FIELDS)));
        $out['driver_required_fields'] = array_values(array_intersect($out['driver_required_fields'] ?? [], array_keys(\App\Models\DriverProfile::FIELDS)));
        $out['driver_required_documents'] = array_values(array_intersect($out['driver_required_documents'] ?? [], array_keys(\App\Models\DriverDocument::TYPES)));

        $before = Setting::values();
        Setting::write($out);

        $changed = collect($out)->filter(fn ($v, $k) => ($before[$k] ?? null) != $v)->keys()->all();
        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'settings',
            'old_values' => collect($before)->only($changed)->all() ?: null,
            'new_values' => collect($out)->only($changed)->all() ?: null,
            'note' => $changed ? 'Business settings changed: '.Str::limit(implode(', ', $changed), 200) : 'Business settings saved (no changes)',
        ]);

        if (in_array('role_abilities_v2', $changed, true)) {
            AuditLog::event('permissions', 'Who-can-do-what changed in Business settings', [
                'before' => $before['role_abilities_v2'] ?? null, 'after' => $out['role_abilities_v2'] ?? null,
            ]);
        }

        Notification::make()->title('Settings saved')->success()->send();
        $this->mount();
    }
}
