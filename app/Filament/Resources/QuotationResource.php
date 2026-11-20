<?php

namespace App\Filament\Resources;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Enums\PaymentMethod;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Filament\Concerns\GuardedResource;
use App\Filament\Resources\QuotationResource\Pages;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\TripDate;
use App\Models\Yacht;
use App\Support\Access;
use App\Support\Phone;
use App\Support\Tax;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Quotation Builder (Schedule A8). A quote is editable while Draft or in
 * Negotiation; once sent the price is locked. Accepting a quote does not hold
 * the car / yacht — converting it to a booking re-checks availability.
 */
class QuotationResource extends Resource
{
    use GuardedResource;

    protected static ?string $model = Quotation::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'number';

    protected static function manageAbility(): string
    {
        return 'manage-quotations';
    }

    protected static function deleteAbility(): string
    {
        return 'delete-records';
    }

    /** The price can only change while Draft or Negotiation. */
    public static function isLocked(?Quotation $record): bool
    {
        return $record !== null && ! $record->status?->isEditable();
    }

    /* ------------------------------------------------------------------ */
    /*  Form                                                              */
    /* ------------------------------------------------------------------ */

    public static function form(Form $form): Form
    {
        $isCar = fn (Get $get): bool => $get('service_type') === ServiceType::Car->value;

        return $form
            ->disabled(fn (?Quotation $record): bool => ! static::canModify() || static::isLocked($record))
            ->columns(3)
            ->schema([
                Forms\Components\Group::make()->columnSpan(['lg' => 2])->schema([
                    Forms\Components\Section::make('Customer & service')
                        ->icon('heroicon-o-user')
                        ->columns(2)
                        ->schema([
                            Forms\Components\Select::make('customer_id')
                                ->label('Customer')
                                ->relationship('customer', 'name')
                                ->getOptionLabelFromRecordUsing(fn (Model $record): string => $record->name.($record->phone ? ' · '.$record->phone : ''))
                                ->searchable(['name', 'phone', 'whatsapp', 'email', 'company_name'])
                                ->preload()
                                ->required(fn (Get $get): bool => blank($get('lead_id')))
                                ->placeholder(fn (Get $get): string => filled($get('lead_id')) ? 'From the lead (profile created when converted)' : 'Select an option')
                                // 20 Nov (item 13): from a lead without a profile the lead's details are shown instead of an empty picker.
                                ->hidden(fn (Get $get, string $operation): bool => $operation === 'create' && filled($get('lead_id')) && blank($get('customer_id')))
                                // 20 Nov (Bilal, item 9): the same customer form as New Booking (add new / open details).
                                ->tap(fn (Forms\Components\Select $select) => \App\Filament\Concerns\CustomerDetails::picker($select))
                                ->live()
                                ->columnSpanFull(),
                            Forms\Components\Placeholder::make('lead_customer')
                                ->label('Customer')
                                ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && filled($get('lead_id')) && blank($get('customer_id')))
                                ->content(fn (Get $get): ?HtmlString => ($l = \App\Models\Lead::find($get('lead_id')))
                                    ? new HtmlString('<b>'.e($l->name).'</b>'.e(collect([$l->phone, $l->email, $l->company_name])->filter()->map(fn ($v) => ' · '.$v)->implode('')).'<br><span style="opacity:.7">From the lead. A customer profile is made from these details when you save.</span>')
                                    : null)
                                ->columnSpanFull(),
                            Forms\Components\Hidden::make('lead_id'),
                            Forms\Components\ToggleButtons::make('service_type')
                                ->label('Service')
                                ->options(fn (?Quotation $record): array => ServiceType::pickerOptions($record?->service_type))
                                ->icons(ServiceType::pickerIcons())
                                ->default(ServiceType::Car->value)
                                ->inline()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set) {
                                    $set('bookable_id', null);
                                    $set('trip_date_id', null);
                                    $set('package_departure_id', null);
                                })
                                ->columnSpanFull(),
                            Forms\Components\Select::make('bookable_id')
                                ->label(fn (Get $get): string => BookingResource::itemLabel($get('service_type')))
                                ->options(fn (Get $get): array => BookingResource::itemOptions($get('service_type'), $get('vehicle_category_id')))
                                ->searchable()
                                ->required(fn (Get $get): bool => $get('service_type') !== ServiceType::Transfer->value)
                                ->placeholder(fn (Get $get): string => $get('service_type') === ServiceType::Transfer->value ? 'Any car of the category' : 'Select an option')
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set) {
                                    $item = BookingResource::findItem($get('service_type'), $get('bookable_id'));
                                    if ($item instanceof Car) {
                                        $set('drive_mode', $item->self_drive ? DriveMode::SelfDrive->value : DriveMode::WithDriver->value);
                                    }
                                    if (($item instanceof Car || $item instanceof Yacht) && $item->deposit) {
                                        $set('security_deposit', (float) $item->deposit);
                                    }
                                    $set('package_departure_id', null);
                                    if ($item instanceof \App\Models\Package && $item->meeting_point) {
                                        $set('pickup_location', $item->meeting_point);
                                    }
                                    self::recalculate($get, $set);
                                })
                                ->columnSpanFull(),
                            Forms\Components\ToggleButtons::make('drive_mode')
                                ->label('Rental type')
                                ->options(DriveMode::class)
                                ->default(DriveMode::SelfDrive->value)
                                ->inline()
                                ->live()
                                ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set))
                                ->visible($isCar),
                            Forms\Components\ToggleButtons::make('crew_mode')
                                ->label('Crew')
                                ->options(['with_crew' => 'With captain & crew', 'bareboat' => 'Without crew (bareboat)'])
                                ->default('with_crew')
                                ->inline()
                                ->live()
                                ->disableOptionWhen(fn (string $value, Get $get): bool => $value === 'bareboat' && ! (BookingResource::findItem(ServiceType::Yacht->value, $get('bookable_id'))?->bareboat_allowed))
                                ->visible(fn (Get $get): bool => $get('service_type') === ServiceType::Yacht->value),
                            Forms\Components\Select::make('trip_date_id')
                                ->label('Trip date')
                                ->options(fn (Get $get): array => BookingResource::tripDateOptions($get('bookable_id')))
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set) {
                                    $date = TripDate::with('trip')->find($get('trip_date_id'));
                                    if ($date) {
                                        $start = Carbon::parse($date->date->toDateString().' '.($date->start_time ?? $date->trip?->start_time ?? '09:00'));
                                        $set('start_at', $start->toDateTimeString());
                                        $set('end_at', $start->copy()->addMinutes($date->trip?->duration_minutes ?: \App\Support\Rules::tripMinutes())->toDateTimeString());
                                    }
                                })
                                ->visible(fn (Get $get): bool => $get('service_type') === ServiceType::Trip->value),
                            BookingResource::packageDepartureField(),
                        ]),

                    \App\Filament\Concerns\TransferFields::section(),

                    Forms\Components\Section::make('Dates')
                        ->icon('heroicon-o-calendar')
                        ->columns(2)
                        ->schema([
                            Forms\Components\DateTimePicker::make('start_at')
                                ->label(fn (Get $get): string => $isCar($get) ? 'Pick-up' : 'Start')
                                ->native(false)->seconds(false)->minutesStep(15)->displayFormat('D, d M Y · H:i')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state) use ($isCar) {
                                    if ($state && ! $get('end_at')) {
                                        $set('end_at', BookingResource::defaultEnd($get('service_type'), $get('bookable_id'), $state));
                                    }
                                    self::recalculate($get, $set);
                                }),
                            Forms\Components\DateTimePicker::make('end_at')
                                ->label(fn (Get $get): string => $isCar($get) ? 'Return' : ($get('service_type') === ServiceType::Transfer->value ? 'Hire ends' : 'End'))
                                ->native(false)->seconds(false)->minutesStep(15)->displayFormat('D, d M Y · H:i')
                                ->required(fn (Get $get): bool => $get('service_type') !== ServiceType::Transfer->value || \App\Models\TransferService::find($get('transfer_service_id'))?->isHourly())
                                ->visible(fn (Get $get): bool => $get('service_type') !== ServiceType::Transfer->value || (bool) \App\Models\TransferService::find($get('transfer_service_id'))?->isHourly())
                                ->after('start_at')
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set))
                                ->helperText(function (Get $get): ?HtmlString {
                                    $type = $get('service_type');
                                    $text = BookingResource::durationText($get('start_at'), $get('end_at'));
                                    if (in_array($type, ['car', 'yacht'], true) && $get('bookable_id') && $get('start_at') && $get('end_at')
                                        && ($clash = \App\Support\Availability::assetConflict($type, (int) $get('bookable_id'), $get('start_at'), $get('end_at')))) {
                                        $text .= '<br><span style="color:rgb(var(--warning-600))">Heads-up: '.e($clash).' A quote does not hold the car.</span>';
                                    }

                                    return $text ? new HtmlString($text) : null;
                                }),
                            Forms\Components\TextInput::make('guests')
                                ->label(fn (Get $get): string => $get('service_type') === ServiceType::Transfer->value ? 'Passengers' : 'Guests')
                                ->numeric()->minValue(1)
                                ->required(fn (Get $get): bool => $get('service_type') === ServiceType::Package->value)
                                ->rules([fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if ($get('service_type') === ServiceType::Package->value
                                        && ($message = BookingResource::packageGuestProblem($get('bookable_id'), null, (int) $value))) {
                                        $fail($message); // limits only: a quote does not hold places
                                    }
                                }])
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set))
                                ->visible(fn (Get $get): bool => ! $isCar($get)),
                            Forms\Components\TextInput::make('pickup_location')
                                ->label(fn (Get $get): string => $get('service_type') === ServiceType::Transfer->value ? 'Pickup address' : ($isCar($get) ? 'Delivery / pick-up address' : 'Meeting point'))
                                ->required(fn (Get $get): bool => $get('service_type') === ServiceType::Transfer->value)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Get $get, Set $set) => $get('service_type') === ServiceType::Transfer->value ? self::recalculate($get, $set) : null)
                                ->maxLength(255),
                            ...\App\Filament\Concerns\PriceFields::handoverChoice(),
                        ]),

                    Forms\Components\Section::make('Price')
                        ->icon('heroicon-o-banknotes')
                        ->description('From the price list. Changes are saved in the history; bigger discounts and other changes need a reason.')
                        ->columns(3)
                        ->schema([
                            ...\App\Filament\Concerns\PriceFields::schema('quotation'),
                            Forms\Components\TextInput::make('security_deposit')
                                ->label('Security deposit')
                                ->helperText('Refundable. Not part of the price.')
                                ->numeric()->minValue(0)->prefix('AED')->default(0),
                            ...\App\Filament\Concerns\PriceFields::advance('quotation'),
                        ]),

                    Forms\Components\Section::make('Shown to the customer')
                        ->icon('heroicon-o-chat-bubble-left-ellipsis')
                        ->collapsible()
                        ->schema([
                            Forms\Components\Select::make('pdf_language')
                                ->label('PDF language')
                                ->options(\App\Support\Documents\PdfText::LANGUAGES)
                                ->placeholder('From the customer\'s language')
                                ->helperText(fn (): ?string => ($m = \App\Support\Documents\CompanyProfile::missing())
                                    ? 'Company profile incomplete ('.implode(', ', $m).'): the PDF leaves these out. Business settings › Company & messages.' : null)
                                ->native(false),
                            Forms\Components\Textarea::make('customer_notes')->label('Notes')->rows(3),
                            Forms\Components\Textarea::make('terms')
                                ->default(fn () => Setting::read('quote_terms'))
                                ->rows(5),
                        ]),
                ]),

                Forms\Components\Group::make()->columnSpan(['lg' => 1])->schema([
                    Forms\Components\Section::make('Status')
                        ->icon('heroicon-o-signal')
                        ->schema([
                            Forms\Components\Placeholder::make('status_view')
                                ->hiddenLabel()
                                ->content(fn (?Quotation $record): HtmlString => new HtmlString(self::statusHtml($record))),
                            Forms\Components\DatePicker::make('valid_until')
                                ->label('Valid until')
                                ->native(false)->displayFormat('d M Y')
                                ->minDate(fn (string $operation) => $operation === 'create' ? today() : null)
                                ->default(fn () => today()->addDays((int) Setting::read('quote_valid_days') ?: 7)) // 6 Nov (S4): H-37 default 7 days until Bilal decides
                                ->required(),
                        ]),
                    Forms\Components\Section::make('Progress')
                        ->icon('heroicon-o-map')
                        ->visibleOn('edit')
                        ->schema([
                            Forms\Components\Placeholder::make('journey')
                                ->hiddenLabel()
                                ->content(fn (?Quotation $record): HtmlString => new HtmlString($record ? \App\Support\Sales\Journey::html(\App\Support\Sales\Journey::forQuotation($record)) : '')),
                        ]),
                    Forms\Components\Section::make('Internal')
                        ->icon('heroicon-o-lock-closed')
                        ->schema([
                            Forms\Components\Textarea::make('internal_notes')->hiddenLabel()->placeholder('Not shown to the customer')->rows(4),
                        ]),
                ]),
            ]);
    }

    protected static function statusHtml(?Quotation $q): string
    {
        if (! $q) {
            return 'New quotation (Draft). Save, then send it to the customer.';
        }

        $lines = ['<span style="font-weight:600;color:rgb(var(--'.$q->status->getColor().'-500))">'.$q->status->getLabel().'</span>'];
        foreach ([
            'Sent' => $q->sent_at, 'Opened by customer' => $q->viewed_at, 'Accepted' => $q->accepted_at,
            'Rejected' => $q->rejected_at, 'Expired' => $q->expired_at,
        ] as $label => $at) {
            if ($at) {
                $lines[] = '<span style="opacity:.75">'.$label.': '.$at->format('d M Y, H:i').'</span>';
            }
        }
        if ($q->rejection_reason) {
            $lines[] = '<span style="opacity:.75">Reason: '.e($q->rejection_reason).'</span>';
        }
        if ($q->booking) {
            $lines[] = '<a style="color:rgb(var(--primary-500));font-weight:600" href="'.e(BookingResource::getUrl('edit', ['record' => $q->booking_id])).'">Booking '.e($q->booking->reference).' →</a>';
        }
        if (static::isLocked($q) && ! $q->booking_id) {
            $lines[] = '<span style="font-size:.8rem;opacity:.7">Price locked. Use "Revise" to change it.</span>';
        }

        return implode('<br>', $lines);
    }

    public static function recalculate(Get $get, Set $set): void
    {
        \App\Filament\Concerns\PriceFields::recalculate($get, $set);
    }

    /* ------------------------------------------------------------------ */
    /*  Table                                                             */
    /* ------------------------------------------------------------------ */

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Quote')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Quotation $record): ?string => $record->customerName()),
                Tables\Columns\TextColumn::make('bookable.name')
                    ->label('Service')
                    ->placeholder('-')
                    ->description(fn (Quotation $record): ?string => $record->start_at ? $record->start_at->format('d M').' → '.$record->end_at?->format('d M') : null),
                Tables\Columns\TextColumn::make('total')
                    ->money('AED')
                    ->alignEnd()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->description(fn (Quotation $record): ?string => $record->viewed_at && $record->status === QuotationStatus::Sent ? 'Opened '.$record->viewed_at->diffForHumans() : null),
                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Valid until')
                    ->date('d M Y')
                    ->sortable()
                    ->color(fn (Quotation $record): ?string => $record->valid_until?->isPast() && $record->status->isEditable() ? 'danger' : null),
                Tables\Columns\TextColumn::make('creator.name')->label('By')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Created')->since()->sortable()->toggleable(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'lead', 'bookable', 'creator']))
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(QuotationStatus::class)->multiple(),
                Tables\Filters\SelectFilter::make('service_type')->label('Service')->options(ServiceType::class),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    ...self::workflowActions(Tables\Actions\Action::class),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading('No quotations yet')
            ->emptyStateDescription('Create one here, or from a lead with "Create quotation".');
    }

    /* ------------------------------------------------------------------ */
    /*  Workflow actions (shared by table rows and the edit page)        */
    /* ------------------------------------------------------------------ */

    public static function workflowActions(string $class): array
    {
        return [
            self::approvePriceAction($class),
            self::rejectPriceAction($class),
            self::sendAction($class),
            $class::make('revise')
                ->label('Revise (negotiation)')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->authorize('manage-quotations')
                ->requiresConfirmation()
                ->modalDescription('Unlocks the price so you can change it. The change is recorded in the history. Send it again afterwards.')
                ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Sent)
                ->action(fn (Quotation $record) => $record->update(['status' => QuotationStatus::Negotiation])),
            $class::make('accept')
                ->label('Customer accepted')
                ->icon('heroicon-o-hand-thumb-up')
                ->color('success')
                ->authorize('manage-quotations')
                ->requiresConfirmation()
                ->modalDescription('For when the customer accepted by phone, WhatsApp or in person. Locks the agreed price, links the customer profile and asks for missing documents. It does not book the car yet: create the booking next.')
                ->form([Forms\Components\TextInput::make('name')->label('Who accepted (customer name)')->default(fn (Quotation $record) => $record->customerName())->required()->maxLength(120)])
                ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Sent)
                ->action(function (Quotation $record, array $data) {
                    try {
                        $result = \App\Support\Sales\QuotationDecision::accept($record, 'staff', $data['name']);
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                        return;
                    }
                    Notification::make()
                        ->title('Accepted. Customer profile linked.')
                        ->body(match (true) {
                            $result['documents_url'] === url('/account/documents') => 'Documents still needed: the customer is asked for them when they log in to their account.',
                            (bool) $result['documents_url'] => 'Documents asked for. Send the customer this upload link: '.$result['documents_url'],
                            (bool) $result['missing'] => 'Documents still needed.',
                            default => 'All documents are in.',
                        })
                        ->success()->persistent()->send();
                }),
            $class::make('reject')
                ->label('Customer declined')
                ->icon('heroicon-o-hand-thumb-down')
                ->color('danger')
                ->authorize('manage-quotations')
                ->form([Forms\Components\TextInput::make('reason')->datalist(\App\Support\Options::list('quote_decline_reasons'))->required()])
                ->modalWidth('md')
                ->visible(fn (Quotation $record): bool => in_array($record->status, [QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Negotiation], true))
                ->action(fn (Quotation $record, array $data) => \App\Support\Sales\QuotationDecision::decline($record, 'staff', $data['reason'])),
            self::convertAction($class),
            $class::make('duplicate')
                ->label('Duplicate as new draft')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->authorize('manage-quotations')
                ->action(function (Quotation $record, $livewire) {
                    $copy = $record->replicate(['number', 'status', 'booking_id', 'public_token', 'sent_at', 'viewed_at', 'accepted_at', 'rejected_at', 'rejection_reason', 'expired_at', 'created_by']);
                    $copy->status = QuotationStatus::Draft;
                    $copy->valid_until = ($days = (int) Setting::read('quote_valid_days')) ? today()->addDays($days) : null;
                    $copy->save();
                    $livewire->redirect(self::getUrl('edit', ['record' => $copy]));
                }),
            $class::make('pdf')
                ->label('PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (Quotation $record): string => route('pdf.quotation', $record), shouldOpenInNewTab: true),
            $class::make('preview')
                ->label('Open customer page')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (Quotation $record): bool => (bool) $record->sent_at)
                ->url(fn (Quotation $record): string => $record->publicUrl(), shouldOpenInNewTab: true),
        ];
    }

    /** Optional price approval (28 Oct-2): shown only while a price waits. */
    public static function approvePriceAction(string $class)
    {
        return $class::make('approvePrice')
            ->label('Approve price')
            ->icon('heroicon-o-check-badge')
            ->color('warning')
            ->authorize('approve-prices')
            ->visible(fn (Model $record): bool => $record->priceWaiting())
            ->requiresConfirmation()
            ->modalDescription(fn (Model $record): string => 'Agreed AED '.number_format((float) $record->total, 2).' against AED '.number_format((float) $record->standard_amount, 2).' on the price list'
                .($record->list_discount_percent ? ' ('.\App\Support\Pricing\Discounts::percentText((float) $record->list_discount_percent).' off)' : '').'. Reason: '.($record->adjustment_reason ?: '-'))
            ->action(fn (Model $record) => $record->approvePrice());
    }

    /** 4 Nov (10): the manager can also turn the price down; the agent is told. */
    public static function rejectPriceAction(string $class)
    {
        return $class::make('rejectPrice')
            ->label('Turn price down')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->authorize('approve-prices')
            ->visible(fn (Model $record): bool => $record->priceWaiting())
            ->modalDescription(fn (Model $record): string => 'The person who set the price is told and must change it before it can be sent or confirmed.')
            ->form([Forms\Components\TextInput::make('reason')->label('Why')->required()->maxLength(200)])
            ->action(fn (Model $record, array $data) => $record->rejectPrice($data['reason']));
    }

    public static function sendAction(string $class)
    {
        return $class::make('send')
            ->label(fn (Quotation $record): string => $record->sent_at ? 'Send again' : 'Send to customer')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->authorize('manage-quotations')
            ->visible(fn (Quotation $record): bool => in_array($record->status, [QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Negotiation], true))
            ->modalHeading('Send quotation')
            ->modalWidth('md')
            ->modalDescription(fn (Quotation $record): string => 'The price will be locked at AED '.number_format((float) $record->total, 2).'. The customer can view, download and accept it from the link.')
            ->form(fn (Quotation $record): array => [
                Forms\Components\Toggle::make('email')
                    ->label($record->customerEmail() ? 'Also email it to '.$record->customerEmail() : 'No email on file')
                    ->default((bool) $record->customerEmail())
                    ->disabled(! $record->customerEmail()),
            ])
            ->action(function (Quotation $record, array $data) {
                if ($record->priceBlocked()) {
                    Notification::make()->title($record->price_approval === 'rejected'
                        ? 'A manager turned this price down: '.$record->price_rejected_reason.'. Change the price first.'
                        : 'This price waits for a manager\'s OK before it can be sent.')->warning()->send();

                    return;
                }
                if ($record->valid_until && $record->valid_until->isPast() && ! $record->valid_until->isToday()) {
                    Notification::make()->title('This quotation has expired. Change "Valid until" first.')->danger()->send();

                    return;
                }

                $record->update(['status' => QuotationStatus::Sent, 'sent_at' => $record->sent_at ?? now()]);
                if ($record->lead && $record->lead->pipeline) {
                    $record->lead->advanceTo('quotation_sent');
                }

                // 4 Nov (13.1): the customer gets an account (setting "When a quotation is sent").
                $login = \App\Support\Portal::onQuoteSent($record);
                $loginUrl = ! empty($data['email']) && $login && ! $login->password_set_at && ! $login->last_login_at && $login->login_enabled
                    && mb_strtolower((string) $login->email) === mb_strtolower((string) $record->customerEmail())
                    ? rescue(fn () => \App\Support\Accounts::inviteUrl($login), null) : null;

                $emailNote = null;
                if (! empty($data['email']) && ($email = $record->customerEmail())) {
                    try {
                        \App\Support\Notifications\Notifier::mail($email, new \App\Notifications\QuotationSent($record, $loginUrl), $record);
                        $record->forceFill(['emailed_at' => now()])->saveQuietly();
                        $emailNote = 'Emailed to '.$email.'. ';
                    } catch (\Throwable $e) {
                        report($e);
                        $emailNote = 'Email could not be sent (check the mail settings). ';
                    }
                }

                $message = \App\Support\Options::message('msg_quote', ['name' => $record->customerName(), 'number' => $record->number, 'link' => $record->publicUrl(), 'amount' => number_format((float) $record->total, 2)]);
                $wa = Phone::whatsappUrl($record->customerPhone(), $message);

                Notification::make()
                    ->title('Quotation ready to send')
                    ->body($emailNote.($wa ? 'Tap the button to open WhatsApp with the message ready. Link: '.$record->publicUrl() : 'No phone number on file. Copy the link: '.$record->publicUrl()))
                    ->success()
                    ->persistent()
                    ->actions(array_filter([
                        $wa ? NotificationAction::make('wa')->label('Send on WhatsApp')->button()->url($wa, shouldOpenInNewTab: true) : null,
                        NotificationAction::make('open')->label('Open quotation')->url($record->publicUrl(), shouldOpenInNewTab: true),
                    ]))
                    ->send();
            });
    }

    /** Accepted quote → booking at the agreed price, after re-checking availability. */
    public static function convertAction(string $class)
    {
        return $class::make('convert')
            ->label('Create booking')
            ->icon('heroicon-o-calendar-days')
            ->color('success')
            ->authorize('manage-bookings')
            ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Accepted && ! $record->booking_id)
            ->modalWidth('lg')
            ->modalHeading(fn (Quotation $record): string => 'Create booking from '.$record->number)
            ->modalDescription(fn (Quotation $record): string => 'Everything is copied from the accepted quotation at the agreed AED '.number_format((float) $record->total, 2).'. Availability is checked again now.')
            ->form([
                Forms\Components\Placeholder::make('checks')
                    ->hiddenLabel()
                    ->content(fn (Quotation $record): HtmlString => new HtmlString(\App\Support\Sales\BookingChecks::html(\App\Support\Sales\BookingChecks::forQuotation($record)))),
                Forms\Components\ToggleButtons::make('status')
                    ->options([BookingStatus::Confirmed->value => 'Confirmed', BookingStatus::PendingPayment->value => 'Waiting for payment'])
                    ->colors([BookingStatus::Confirmed->value => 'success', BookingStatus::PendingPayment->value => 'warning'])
                    ->default(fn (Quotation $record): string => (float) $record->advance_required > 0 ? BookingStatus::PendingPayment->value : BookingStatus::Confirmed->value)
                    ->disableOptionWhen(fn (string $value, Quotation $record): bool => $value === BookingStatus::Confirmed->value && \App\Support\Sales\BookingChecks::confirmBlocked($record) !== null)
                    ->helperText(fn (Quotation $record): ?string => \App\Support\Sales\BookingChecks::confirmBlocked($record))
                    ->inline()
                    ->required(),
                Forms\Components\TextInput::make('advance')->label('Advance received now')->numeric()->minValue(0)->prefix('AED')
                    ->default(fn (Quotation $record) => max(0, (float) $record->advance_required - \App\Support\Payments\Payments::paidOnQuotation($record)) ?: null)
                    ->helperText(fn (Quotation $record): ?string => ($online = \App\Support\Payments\Payments::paidOnQuotation($record)) > 0 ? 'AED '.number_format($online, 2).' already paid online: it moves onto the booking. Enter only other money received now.' : null),
                Forms\Components\Select::make('method')->label('Paid by')->options(PaymentMethod::class)->default(PaymentMethod::Card->value)->native(false),
            ])
            ->action(function (Quotation $record, array $data, $livewire) {
                if ($data['status'] === BookingStatus::Confirmed->value && ($why = \App\Support\Sales\BookingChecks::confirmBlocked($record))) {
                    Notification::make()->title($why)->danger()->send();

                    return;
                }
                try {
                    $booking = DB::transaction(function () use ($record, $data) {
                        $customerId = $record->customer_id ?? $record->lead?->convertToCustomer()->id;

                        $booking = Booking::create([
                            'status' => $data['status'],
                            'customer_id' => $customerId,
                            'lead_id' => $record->lead_id,
                            'quotation_id' => $record->id,
                            'service_type' => $record->service_type,
                            'bookable_type' => $record->bookable_type,
                            'bookable_id' => $record->bookable_id,
                            'trip_date_id' => $record->trip_date_id,
                            'package_departure_id' => $record->package_departure_id,
                            'drive_mode' => $record->drive_mode,
                            'crew_mode' => $record->crew_mode,
                            'start_at' => $record->start_at,
                            'end_at' => $record->end_at,
                            'guests' => $record->guests,
                            'pickup_location' => $record->pickup_location,
                            'rate_type' => $record->rate_type,
                            'quantity' => $record->quantity,
                            'rate' => $record->rate,
                            'base_amount' => $record->base_amount,
                            'extras_amount' => $record->extras_amount,
                            'extras' => $record->extras,
                            'delivery_method' => $record->delivery_method,
                            'return_method' => $record->return_method,
                            'discount' => $record->discount,
                            'base_discount_percent' => $record->base_discount_percent,
                            'discount_mode' => $record->discount_mode,
                            'discount_percent' => $record->discount_percent,
                            'list_discount_amount' => $record->list_discount_amount,
                            'list_discount_percent' => $record->list_discount_percent,
                            'price_approval' => $record->price_approval === 'approved' ? 'approved' : null,
                            'price_approved_by' => $record->price_approval === 'approved' ? $record->price_approved_by : null,
                            'price_approved_at' => $record->price_approval === 'approved' ? $record->price_approved_at : null,
                            'advance_percent' => $record->advance_percent ?? ((float) $record->total > 0 && (float) $record->advance_required > 0 ? round((float) $record->advance_required / (float) $record->total * 100, 2) : null),
                            'advance_reason' => $record->advance_reason,
                            'standard_amount' => $record->standard_amount,
                            'adjustment_reason' => $record->adjustment_reason,
                            'adjusted_by' => $record->adjusted_by,
                            'price_breakdown' => $record->price_breakdown ?? ['source' => 'quotation', 'number' => $record->number],
                            'tax_rate' => $record->tax_rate,
                            'tax_inclusive' => $record->tax_inclusive,
                            'security_deposit' => $record->security_deposit,
                            ...collect(\App\Support\Transfers\TransferJourney::FIELDS)->mapWithKeys(fn ($f) => [$f => $record->getAttribute($f)])->all(),
                            'notes' => trim('From quotation '.$record->number."\n".($record->internal_notes ?? '')),
                        ]);

                        $record->update(['booking_id' => $booking->id, 'customer_id' => $customerId]);

                        // Money already paid online on the quotation moves onto the booking (28 Oct-2).
                        \App\Support\Payments\Payments::moveToBooking($record, $booking);

                        if (($advance = (float) ($data['advance'] ?? 0)) > 0) {
                            $booking->recordPayment($advance, $data['method'] ?? null, null, 'Advance · '.$booking->reference);
                        }

                        return $booking;
                    });
                } catch (ValidationException $e) {
                    $record->lead?->activities()->create(['type' => \App\Enums\ActivityType::Note, 'user_id' => auth()->id(),
                        'body' => 'Booking from '.$record->number.' could not be created: '.collect($e->errors())->flatten()->first()]);
                    Notification::make()
                        ->title('Not available any more')
                        ->body(collect($e->errors())->flatten()->first().' The quotation stays accepted at the agreed price; change the dates on a revised quote or pick another car.')
                        ->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Booking '.$booking->reference.' created at the agreed price')->success()->send();
                $livewire->redirect(BookingResource::getUrl('edit', ['record' => $booking]));
            });
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\RelationManagers\HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotations::route('/'),
            'create' => Pages\CreateQuotation::route('/create'),
            'edit' => Pages\EditQuotation::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return \App\Support\Access::scopeOwn(parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]), 'quotation');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->withoutTrashed()->where('status', QuotationStatus::Sent)->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Sent, waiting for the customer';
    }
}
