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

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'business-settings';

    public ?array $data = [];

    public const KINDS = [
        'self_drive' => 'Car, self-drive',
        'with_driver' => 'Car, with driver',
        'yacht' => 'Yacht charter (old bookings only)',
        'trip' => 'Yacht trip',
        'package' => 'Trip / package',
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
                    ->description('Shown on quotations, upload pages and messages.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('company_name')->required()->maxLength(120),
                        Forms\Components\TextInput::make('company_trn')->label('VAT registration number (TRN)')->maxLength(30),
                        Forms\Components\TextInput::make('company_phone')->label('Phone / WhatsApp')->tel()->maxLength(30),
                        Forms\Components\TextInput::make('company_email')->label('Email')->email()->maxLength(120),
                        Forms\Components\Textarea::make('company_address')->label('Address')->rows(2),
                        Forms\Components\Textarea::make('bank_details')->label('Bank details for payment')->rows(3),
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
                    // Yacht charter thresholds are no longer shown (charters stopped); stored values stay.
                    ->schema(collect(Rules::PRICING)->reject(fn ($meta, $key) => str_starts_with($key, 'yacht_'))->map(fn ($meta, $key) => Forms\Components\TextInput::make($key)
                        ->label($meta[0])
                        ->numeric()->minValue(1)->maxValue(365)->suffix($meta[1])->required())->values()->all()),
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
            ]);
    }

    protected function rolesTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Roles & permissions')
            ->icon('heroicon-o-shield-check')
            ->schema([
                Forms\Components\Section::make('Who can do what')
                    ->description('Super Admin can always do everything. Admin always keeps access to these settings. Drivers only see their own bookings.')
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
            'vat_enabled', 'vat_rate', 'vat_inclusive', 'quote_valid_days', 'quote_terms',
            'doc_block_handover', 'upload_link_hours',
            'pending_payment_holds', 'booking_buffer_minutes', 'trip_default_minutes', 'car_default_hours', 'yacht_default_hours',
            'late_grace_minutes', 'late_charge_mode', 'late_charge_amount',
            ...array_keys(Rules::PRICING),
            'website_origins',
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

        Notification::make()->title('Settings saved')->success()->send();
        $this->mount();
    }
}
