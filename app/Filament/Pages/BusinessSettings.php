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
            $values['perm_'.self::permKey($ability)] = Access::rolesFor($ability);
        }

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
                Forms\Components\Section::make('Company details')
                    ->description('Printed on quotations, confirmations, invoices and receipts. The TRN is only printed while VAT is switched on.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('company_name')->label('Trading name')->required()->maxLength(120),
                        Forms\Components\TextInput::make('company_legal_name')->label('Legal name (as on the trade licence)')->maxLength(160),
                        Forms\Components\TextInput::make('company_trade_licence')->label('Trade licence number')->maxLength(60),
                        Forms\Components\TextInput::make('company_trn')->label('VAT registration number (TRN)')->maxLength(30),
                        Forms\Components\TextInput::make('company_phone')->label('Phone / WhatsApp')->tel()->maxLength(30),
                        Forms\Components\TextInput::make('company_email')->label('Email')->email()->maxLength(120),
                        Forms\Components\Textarea::make('company_address')->label('Address')->rows(2),
                        Forms\Components\Textarea::make('bank_details')->label('Bank details for payment')->rows(3),
                        Forms\Components\Textarea::make('policy_cancellation')->label('Cancellation and changes (printed on quotations and confirmations)')->rows(3)
                            ->helperText('Empty = not printed. Your own wording; the CRM does not assume a policy.'),
                        Forms\Components\Textarea::make('policy_late_return')->label('Late return (printed on quotations and confirmations)')->rows(3)
                            ->helperText('Empty = not printed.'),
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
                            ->label('"Pending payment" bookings keep the car')
                            ->helperText('Off = the dates stay open for others until the booking is confirmed.')
                            ->columnSpanFull(),
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
                        Forms\Components\TextInput::make('price_max_discount_percent')
                            ->label('Most an agent can take off')
                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                            ->helperText('Empty = no limit. Managers with "Pricing" rights are not limited. A reason is always asked.'),
                        Forms\Components\Toggle::make('price_approval_required')
                            ->label('Above the limit, wait for a manager\'s OK instead of refusing')
                            ->helperText('Off (default): a bigger discount is refused. On: it is saved but can\'t be sent or confirmed until a manager approves it.')
                            ->inline(false),
                        Forms\Components\Toggle::make('booking_requires_accepted_quote')
                            ->label('Bookings must come from an accepted quotation')
                            ->helperText('Off: staff can also book directly (walk-ins). On: a new confirmed booking needs an accepted quotation.')
                            ->inline(false),
                        $this->listField('quote_extras'),
                        $this->listField('quote_decline_reasons'),
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
                            ->helperText('Until then it can be created as Pending payment.')
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
        $keys = ['lost_reasons', 'cancel_reasons', 'pickup_locations', 'car_types', 'car_brands', 'car_locations', 'package_types', 'marinas'];

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
                Forms\Components\Section::make('Notifications')
                    ->description('Messages always appear under the bell in the CRM. Email needs email sending set up on the server.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('notify_drivers_by_email')->label('Also email drivers about new or changed jobs'),
                        Forms\Components\Toggle::make('notify_staff_by_email')->label('Also email staff about handovers, collections and damage'),
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
                Forms\Components\Section::make('Who can do what')
                    ->description('Super Admin can always do everything. Admin always keeps access to these settings. Drivers only see their own jobs and profile; customers only their own account.')
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
        'agent_own_records_only', 'customer_accounts_enabled', 'customer_can_upload_documents', 'customer_sees_payments', 'customer_sees_quotations', 'invite_link_hours',
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
        $out['role_abilities'] = $perms;

        foreach ([
            'company_name', 'company_trn', 'company_phone', 'company_email', 'company_address', 'bank_details',
            'company_legal_name', 'company_trade_licence', 'policy_cancellation', 'policy_late_return',
            'vat_enabled', 'vat_rate', 'vat_inclusive', 'quote_valid_days', 'quote_terms',
            'doc_block_handover', 'upload_link_hours',
            'pending_payment_holds', 'booking_buffer_minutes', 'trip_default_minutes', 'car_default_hours', 'yacht_default_hours',
            'late_grace_minutes', 'late_charge_mode', 'late_charge_amount',
            ...array_keys(Rules::PRICING),
            'website_origins',
            ...self::DRIVER_AND_ACCOUNT_KEYS,
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

        if (in_array('role_abilities', $changed, true)) {
            AuditLog::event('permissions', 'Who-can-do-what changed in Business settings', [
                'before' => $before['role_abilities'] ?? null, 'after' => $out['role_abilities'] ?? null,
            ]);
        }

        Notification::make()->title('Settings saved')->success()->send();
        $this->mount();
    }
}
