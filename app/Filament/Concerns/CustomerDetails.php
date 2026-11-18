<?php

namespace App\Filament\Concerns;

use App\Enums\CustomerType;
use App\Enums\DocumentStatus;
use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Support\Access;
use App\Support\Ocr\DocumentReader;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * 18 Nov (meeting item 6): every customer detail and document in one pop-up, opened from the
 * booking (new customer, or "Customer details" for the chosen one) and from the contract. The
 * same fields are on the customer page. "Read the documents" (item 7) fills the fields from the
 * uploads; staff check them before saving.
 */
class CustomerDetails
{
    /** upload key => [label, number field, expiry field, issue field] */
    public const UPLOADS = [
        'emirates_id' => ['Emirates ID (front and back)', 'id_number', 'id_expires_on', null],
        'passport' => ['Passport', 'passport_number', 'passport_expires_on', null],
        'driving_licence' => ['Driving licence (front and back)', 'licence_number', 'licence_expires_on', 'licence_issued_on'],
        'international_licence' => ['International driving permit', null, null, null],
        'visa' => ['Visa / entry stamp', null, null, null],
        'trade_licence' => ['Trade licence (company)', 'trade_licence_number', 'trade_licence_expires_on', null],
    ];

    public const RESIDENCY = ['resident' => 'UAE resident', 'gcc' => 'GCC national', 'tourist' => 'Tourist / visitor'];

    /** Customer columns saved from this form. */
    public const FIELDS = [
        'name', 'phone', 'whatsapp', 'email', 'type', 'company_name', 'trn', 'address', 'residency', 'nationality', 'date_of_birth',
        'id_number', 'id_expires_on', 'passport_number', 'passport_expires_on',
        'licence_number', 'licence_country', 'licence_issued_on', 'licence_expires_on', 'trade_licence_number', 'trade_licence_expires_on',
    ];

    public static function schema(?Customer $customer = null): array
    {
        $company = fn (Get $get): bool => filled($get('type')) && $get('type') !== CustomerType::Individual->value;
        $date = fn (string $name, string $label) => Forms\Components\DatePicker::make($name)->label($label)->native(false)->displayFormat('d M Y');
        $nationalities = array_combine(CustomerResource::NATIONALITIES, CustomerResource::NATIONALITIES);

        return [
            Forms\Components\Placeholder::make('blacklist_warning')
                ->hiddenLabel()
                ->visible(fn (): bool => (bool) $customer?->blacklisted_at)
                ->content(fn () => new HtmlString('<div style="padding:.6rem .8rem;border-radius:.6rem;background:rgba(220,38,38,.12);color:rgb(220,38,38);font-weight:600">Blacklisted'
                    .($customer?->blacklist_reason ? ': '.e($customer->blacklist_reason) : '').'. Ask Admin before renting to this customer.</div>')),
            Forms\Components\Section::make('Contact')
                ->columns(3)
                ->schema([
                    Forms\Components\ToggleButtons::make('type')->options(CustomerType::class)->default(CustomerType::Individual->value)->inline()->live()->columnSpanFull(),
                    Forms\Components\TextInput::make('name')->label(fn (Get $get): string => $company($get) ? 'Contact person' : 'Full name (as on passport / ID)')->required()->maxLength(255),
                    Forms\Components\TextInput::make('phone')->tel()->required()->maxLength(30)->placeholder('+971 50 123 4567')
                        ->unique('customers', 'phone', ignorable: $customer)
                        ->validationMessages(['unique' => 'A customer with this phone already exists. Pick them from the list instead.']),
                    Forms\Components\TextInput::make('whatsapp')->label('WhatsApp')->tel()->maxLength(30)->placeholder('Same as phone'),
                    Forms\Components\TextInput::make('email')->email()->maxLength(255)
                        ->unique('customers', 'email', ignorable: $customer)
                        ->validationMessages(['unique' => 'A customer with this email already exists. Pick them from the list instead.']),
                    Forms\Components\Select::make('residency')->label('Customer is')->options(self::RESIDENCY)->native(false)->live()
                        ->helperText('Decides which documents are needed.'),
                    Forms\Components\Select::make('nationality')->options($nationalities)->searchable(),
                    $date('date_of_birth', 'Date of birth')->maxDate(today()),
                    Forms\Components\TextInput::make('company_name')->label('Company')->maxLength(255)->visible($company)->required($company),
                    Forms\Components\TextInput::make('trn')->label('Company TRN (VAT number)')->maxLength(30)->visible($company),
                    Forms\Components\Textarea::make('address')->label('Address')->rows(2)->columnSpanFull(),
                ]),
            Forms\Components\Section::make('ID and driving licence')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('id_number')->label('Emirates ID number')->placeholder('784-XXXX-XXXXXXX-X')->maxLength(40)->columnSpan(2),
                    $date('id_expires_on', 'Emirates ID expiry')->columnSpan(2),
                    Forms\Components\TextInput::make('passport_number')->label('Passport number')->maxLength(40)->columnSpan(2),
                    $date('passport_expires_on', 'Passport expiry')->columnSpan(2),
                    Forms\Components\TextInput::make('licence_number')->label('Licence number')->maxLength(40),
                    Forms\Components\TextInput::make('licence_country')->label('Issued in')->maxLength(60)->datalist(['United Arab Emirates', 'Saudi Arabia', 'United Kingdom', 'India', 'Pakistan', 'Russia', 'Germany', 'France', 'United States']),
                    $date('licence_issued_on', 'Licence issued')->maxDate(today()),
                    $date('licence_expires_on', 'Licence expiry'),
                    Forms\Components\TextInput::make('trade_licence_number')->label('Trade licence number')->maxLength(60)->visible($company)->columnSpan(2),
                    $date('trade_licence_expires_on', 'Trade licence expiry')->visible($company)->columnSpan(2),
                ]),
            Forms\Components\Section::make('Documents')
                ->description(fn (): string => 'Photos or PDFs. Front and back can go in the same field.'.(DocumentReader::ready() ? ' Then press "Read the documents" to fill the fields above.' : ''))
                ->columns(2)
                ->schema([
                    ...collect(self::uploads())->map(fn ($meta, $key) => Forms\Components\FileUpload::make('upload_'.$key)
                        ->label($meta[0])
                        ->disk('private')->directory('customers/'.($customer?->id ?? 'new'))->visibility('private')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(10240)->multiple()->maxFiles(4)->imagePreviewHeight('80')
                        ->visible($key === 'trade_licence' ? $company : true)
                        ->helperText(fn () => self::onFile($customer, $key)))->values()->all(),
                    Forms\Components\Toggle::make('documents_checked')
                        ->label('I checked these against the originals')
                        ->helperText('On: saved as approved. Off: saved to be reviewed.')
                        ->default(fn (): bool => Access::allows('review-documents'))
                        ->visible(fn (): bool => Access::allows('review-documents'))
                        ->columnSpanFull(),
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('readDocuments')
                            ->label('Read the documents (OCR)')
                            ->icon('heroicon-o-sparkles')
                            ->color('gray')
                            ->action(fn (Get $get, Set $set) => self::fillFromDocuments($get, $set)),
                    ])->columnSpanFull(),
                    Forms\Components\Hidden::make('ocr_result'),
                ]),
        ];
    }

    /** Upload fields for the document types switched on in Business settings. */
    public static function uploads(): array
    {
        $types = \App\Support\Options::documentTypes();

        return collect(self::UPLOADS)->filter(fn ($meta, $key) => array_key_exists($key, $types))
            ->map(fn ($meta, $key) => [$types[$key] ?: $meta[0], $meta[1], $meta[2], $meta[3]])->all();
    }

    /** What is already on file for this type ("Approved, expires 12 Mar 2027"). */
    protected static function onFile(?Customer $customer, string $type): ?string
    {
        if (! $customer) {
            return null;
        }
        $doc = CustomerDocument::where('customer_id', $customer->id)->where('type', $type)->latest()->first();

        return $doc ? 'On file: '.$doc->status->getLabel().($doc->expires_on ? ', expires '.$doc->expires_on->format('d M Y') : '').'. Add a new one only to replace it.' : null;
    }

    /** Form state for an existing customer. */
    public static function fill(Customer $customer): array
    {
        $data = $customer->only(self::FIELDS);
        foreach (['date_of_birth', 'id_expires_on', 'passport_expires_on', 'licence_issued_on', 'licence_expires_on', 'trade_licence_expires_on'] as $d) {
            $data[$d] = $customer->{$d} ? \Illuminate\Support\Carbon::parse($customer->{$d})->toDateString() : null;
        }
        $data['type'] = $customer->type?->value ?? CustomerType::Individual->value;
        $data['documents_checked'] = Access::allows('review-documents');

        return $data;
    }

    public static function create(array $data): Customer
    {
        $attributes = self::attributes($data);
        $customer = Customer::create($attributes + ['assigned_to' => auth()->id()]);
        self::storeDocuments($customer, array_merge($data, $attributes));

        return $customer;
    }

    public static function update(Customer $customer, array $data): Customer
    {
        $attributes = self::attributes($data);
        $customer->update($attributes);
        self::storeDocuments($customer, array_merge($data, $attributes));
        // Contracts not signed yet show the new details.
        foreach ($customer->contracts()->where('status', 'draft')->whereNull('signed_at')->get() as $contract) {
            \App\Support\Contracts\Contracts::syncMainDriver($contract);
        }

        return $customer;
    }

    protected static function attributes(array $data): array
    {
        $out = collect($data)->only(self::FIELDS)->map(fn ($v) => is_string($v) ? trim($v) : $v)->map(fn ($v) => $v === '' ? null : $v)->all();
        if (! empty($out['id_number'])) {
            $out['id_number'] = DocumentReader::emiratesId($out['id_number']);
        }
        if (($out['type'] ?? null) === CustomerType::Individual->value) {
            $out['company_name'] = $out['company_name'] ?? null;
        }

        return $out;
    }

    /** Uploaded files become customer documents with their number and expiry from the form. */
    protected static function storeDocuments(Customer $customer, array $data): void
    {
        $approve = ! empty($data['documents_checked']) && Access::allows('review-documents');
        $ocr = filled($data['ocr_result'] ?? null) ? json_decode((string) $data['ocr_result'], true) : null;
        foreach (self::UPLOADS as $type => [, $numberField, $expiryField, $issueField]) {
            // Only files just uploaded into the customers folder (never a path of another file sent by the browser).
            foreach (\App\Support\PrivateFiles::freshUploads(array_values(array_filter((array) ($data['upload_'.$type] ?? []), 'is_string')), 'customers') as $path) {
                $disk = Storage::disk('private');
                CustomerDocument::create([
                    'customer_id' => $customer->id,
                    'type' => $type,
                    'number' => $numberField ? ($data[$numberField] ?? null) : null,
                    'issued_on' => $issueField ? ($data[$issueField] ?? null) : null,
                    'expires_on' => $expiryField ? ($data[$expiryField] ?? null) : null,
                    'status' => $approve ? DocumentStatus::Approved : DocumentStatus::Received,
                    'path' => $path,
                    'original_name' => basename($path),
                    'mime' => rescue(fn () => $disk->mimeType($path), null, false),
                    'size' => rescue(fn () => $disk->size($path), null, false),
                    'uploaded_via' => 'staff',
                    'uploaded_by' => auth()->id(),
                    'reviewed_by' => $approve ? auth()->id() : null,
                    'reviewed_at' => $approve ? now() : null,
                    'ocr' => $ocr ? collect((array) ($ocr['documents'] ?? []))->firstWhere('type', $type) : null,
                ]);
            }
        }
    }

    /** "Read the documents": fill empty fields from the uploads, tell what was filled and what differs. */
    public static function fillFromDocuments(Get $get, Set $set, string $prefix = ''): void
    {
        $files = [];
        foreach (array_keys(self::UPLOADS) as $type) {
            foreach ((array) ($get($prefix.'upload_'.$type) ?? []) as $file) {
                $path = match (true) {
                    is_object($file) && method_exists($file, 'getRealPath') => $file->getRealPath(),
                    is_string($file) && $file !== '' => Storage::disk('private')->path($file),
                    default => null,
                };
                if ($path) {
                    $files[] = ['path' => $path, 'mime' => is_object($file) && method_exists($file, 'getMimeType') ? $file->getMimeType() : null, 'type' => self::UPLOADS[$type][0]];
                }
            }
        }
        try {
            $found = DocumentReader::readCustomer($files);
        } catch (\Throwable $e) {
            Notification::make()->warning()->title('Could not read the documents')->body($e->getMessage())->send();

            return;
        }

        $filled = [];
        $differs = [];
        foreach (DocumentReader::CUSTOMER_FIELDS as $field) {
            if (! array_key_exists($field, $found)) {
                continue;
            }
            $current = $get($prefix.$field);
            if ($field === 'nationality' && ! in_array($found[$field], CustomerResource::NATIONALITIES, true)) {
                continue; // only names from the list fit the nationality field
            }
            if (blank($current)) {
                $set($prefix.$field, $found[$field]);
                $filled[] = str_replace('_', ' ', $field);
            } elseif (mb_strtolower((string) $current) !== mb_strtolower((string) $found[$field])) {
                $differs[] = str_replace('_', ' ', $field).': document says "'.$found[$field].'"';
            }
        }
        $set($prefix.'ocr_result', json_encode($found));

        Notification::make()->success()->title($filled ? 'Filled in: '.implode(', ', $filled) : 'Nothing new to fill in')
            ->body(($differs ? 'Different from what is typed (not changed): '.implode('; ', $differs).'. ' : '').'Check every field before saving.')
            ->persistent()->send();
    }
}
