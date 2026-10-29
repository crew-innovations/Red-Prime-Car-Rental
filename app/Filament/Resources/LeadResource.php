<?php

namespace App\Filament\Resources;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Enums\Pipeline;
use App\Enums\ServiceType;
use App\Filament\Resources\LeadResource\Pages;
use App\Filament\Resources\LeadResource\RelationManagers;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Trip;
use App\Models\User;
use App\Models\Yacht;
use App\Support\Phone;
use App\Filament\Concerns\GuardedResource;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class LeadResource extends Resource
{
    use GuardedResource;

    protected static function manageAbility(): string
    {
        return 'manage-leads';
    }

    protected static function deleteAbility(): string
    {
        return 'delete-records';
    }

    protected static ?string $model = Lead::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->disabled(fn (): bool => ! static::canModify())
            ->columns(3)
            ->schema([
                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Forms\Components\Section::make('Contact')
                            ->icon('heroicon-o-user')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(fn (Get $get): string => $get('pipeline') === Pipeline::B2b->value ? 'Contact person' : 'Name')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(fn (Get $get): int|string => $get('pipeline') === Pipeline::B2b->value ? 1 : 'full'),
                                Forms\Components\TextInput::make('company_name')
                                    ->label('Company')
                                    ->required(fn (Get $get): bool => $get('pipeline') === Pipeline::B2b->value)
                                    ->maxLength(255)
                                    ->visible(fn (Get $get): bool => $get('pipeline') === Pipeline::B2b->value),
                                Forms\Components\TextInput::make('phone')
                                    ->tel()
                                    ->placeholder('+971 50 123 4567')
                                    ->maxLength(30)
                                    ->required(fn (Get $get): bool => blank($get('email')) && blank($get('whatsapp')))
                                    ->live(onBlur: true)
                                    ->hint(fn (?string $state, ?Lead $record): ?string => self::existingContactHint($state, $record))
                                    ->hintColor('warning')
                                    ->hintIcon(fn (?string $state, ?Lead $record): ?string => self::existingContactHint($state, $record) ? 'heroicon-o-exclamation-circle' : null),
                                Forms\Components\TextInput::make('whatsapp')
                                    ->label('WhatsApp')
                                    ->tel()
                                    ->placeholder('Same as phone')
                                    ->maxLength(30),
                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->hint(fn (?string $state, ?Lead $record): ?string => self::existingContactHint($state, $record))
                                    ->hintColor('warning'),
                                Forms\Components\Select::make('lead_source_id')
                                    ->label('Source')
                                    ->relationship('leadSource', 'name', fn (Builder $query) => $query->where('is_active', true)->orderBy('sort_order'))
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                            ]),

                        Forms\Components\Section::make('What they want')
                            ->icon('heroicon-o-sparkles')
                            ->columns(3)
                            ->schema([
                                Forms\Components\ToggleButtons::make('service_type')
                                    ->label('Interested in')
                                    ->options(fn (?Lead $record): array => ServiceType::pickerOptions($record?->service_type) + [ServiceType::Other->value => ServiceType::Other->getLabel()])
                                    ->icons(ServiceType::pickerIcons())
                                    ->inline()
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                        // The specific item follows the service, so staff don't pick the type twice.
                                        if (in_array($state, ['car', 'trip', 'package'], true) && $get('interested_in_type') !== $state) {
                                            $set('interested_in_type', $state);
                                            $set('interested_in_id', null);
                                        }
                                        if ($get('pipeline') === Pipeline::B2b->value) {
                                            return;
                                        }
                                        $pipeline = Pipeline::forService($state);
                                        if ($pipeline->value !== $get('pipeline')) {
                                            $set('pipeline', $pipeline->value);
                                            if (! $pipeline->hasStage($get('stage'))) {
                                                $set('stage', $pipeline->firstStage());
                                            }
                                        }
                                    })
                                    ->columnSpanFull(),
                                MorphToSelect::make('interestedIn')
                                    ->label('Specific car, trip or package')
                                    ->types(fn (?Lead $record): array => array_values(array_filter([
                                        MorphToSelect\Type::make(Car::class)->titleAttribute('name')->label('Car'),
                                        MorphToSelect\Type::make(Trip::class)->titleAttribute('name')->label('Yacht trip'),
                                        MorphToSelect\Type::make(\App\Models\Package::class)->titleAttribute('name')->label('Trip / package'),
                                        // Old charter enquiries keep showing their yacht.
                                        $record?->interested_in_type === 'yacht' ? MorphToSelect\Type::make(Yacht::class)->titleAttribute('name')->label('Yacht (old charter)') : null,
                                    ])))
                                    ->searchable()
                                    ->preload()
                                    ->columnSpanFull(),
                                Forms\Components\DatePicker::make('preferred_date')
                                    ->label(fn (Get $get): string => $get('service_type') === ServiceType::Car->value ? 'Pick-up date' : 'Preferred date')
                                    ->native(false)
                                    ->displayFormat('d M Y'),
                                Forms\Components\DatePicker::make('end_date')
                                    ->label('Return date')
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->afterOrEqual('preferred_date')
                                    ->visible(fn (Get $get): bool => $get('service_type') === ServiceType::Car->value),
                                Forms\Components\TextInput::make('guests')
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => in_array($get('service_type'), [ServiceType::Yacht->value, ServiceType::Trip->value, ServiceType::Package->value], true)),
                                Forms\Components\TextInput::make('budget')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('AED'),
                                Forms\Components\Textarea::make('message')
                                    ->label('Customer message / request')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('Tracking')
                            ->icon('heroicon-o-chart-bar')
                            ->description('Filled automatically by the website and ad integrations.')
                            ->collapsed()
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('utm_source')->label('UTM source'),
                                Forms\Components\TextInput::make('utm_medium')->label('UTM medium'),
                                Forms\Components\TextInput::make('utm_campaign')->label('Campaign'),
                                Forms\Components\TextInput::make('external_id')->label('Platform lead ID'),
                                Forms\Components\Placeholder::make('platform_info')->label('Ad / form')->columnSpanFull()
                                    ->visible(fn (?Lead $record): bool => (bool) ($record?->platform))
                                    ->content(fn (?Lead $record) => new \Illuminate\Support\HtmlString(collect((array) $record?->platform)
                                        ->map(fn ($v, $k) => '<div><span style="opacity:.6">'.e(str_replace('_', ' ', ucfirst((string) $k))).':</span> '.e(is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v).'</div>')->implode(''))),
                                Forms\Components\Placeholder::make('answers_info')->label('Answers on the form')->columnSpanFull()
                                    ->visible(fn (?Lead $record): bool => (bool) ($record?->answers))
                                    ->content(fn (?Lead $record) => new \Illuminate\Support\HtmlString(collect((array) $record?->answers)
                                        ->map(fn ($v, $k) => '<div><span style="opacity:.6">'.e((string) $k).':</span> '.e((string) $v).'</div>')->implode(''))),
                            ]),
                    ]),

                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Forms\Components\Section::make('Pipeline')
                            ->icon('heroicon-o-funnel')
                            ->schema([
                                Forms\Components\ToggleButtons::make('pipeline')
                                    ->hiddenLabel()
                                    ->options(Pipeline::class)
                                    ->default(Pipeline::Car->value)
                                    ->inline()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                        $pipeline = Pipeline::tryFrom((string) $state);
                                        if ($pipeline && ! $pipeline->hasStage($get('stage'))) {
                                            $set('stage', $pipeline->firstStage());
                                        }
                                    }),
                                Forms\Components\Select::make('stage')
                                    ->options(fn (Get $get): array => Pipeline::tryFrom((string) $get('pipeline'))?->stageOptions() ?? [])
                                    ->default('new')
                                    ->native(false)
                                    ->required()
                                    ->live()
                                    ->selectablePlaceholder(false),
                                Forms\Components\Placeholder::make('stage_progress')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get): HtmlString => self::stageProgress(Pipeline::tryFrom((string) $get('pipeline')), $get('stage'))),
                                Forms\Components\TextInput::make('lost_reason')
                                    ->validationMessages(['required' => 'Please add why this lead was lost.'])
                                    ->label('Why was it lost?')
                                    ->datalist(fn (): array => \App\Support\Options::list('lost_reasons'))
                                    ->required(fn (Get $get): bool => $get('stage') === Pipeline::LOST)
                                    ->visible(fn (Get $get): bool => $get('stage') === Pipeline::LOST),
                                Forms\Components\ToggleButtons::make('priority')
                                    ->options(LeadPriority::class)
                                    ->default(LeadPriority::Warm->value)
                                    ->inline(),
                            ]),

                        Forms\Components\Section::make('Follow-up')
                            ->icon('heroicon-o-clock')
                            ->schema([
                                Forms\Components\Select::make('assigned_to')
                                    ->label('Salesperson')
                                    ->relationship('assignee', 'name', fn (Builder $query) => $query->where('is_active', true)->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', ['Customer', 'Driver'])))
                                    ->default(fn () => auth()->id())
                                    ->disabled(fn (string $operation): bool => $operation !== 'create' && ! \App\Support\Access::allows('assign-leads'))
                                    ->helperText(fn (?Lead $record): ?string => match ($record?->assignment_status) {
                                        'pending' => 'Waiting for '.$record->assignee?->name.' to accept.',
                                        'accepted' => 'Accepted '.$record->accepted_at?->format('d M, H:i').'.',
                                        default => null,
                                    })
                                    ->searchable()
                                    ->preload(),
                                Forms\Components\DateTimePicker::make('next_follow_up_at')
                                    ->label('Next follow-up')
                                    ->native(false)
                                    ->seconds(false)
                                    ->displayFormat('d M Y, H:i')
                                    ->minDate(fn (string $operation) => $operation === 'create' ? now()->startOfDay() : null),
                                Forms\Components\Placeholder::make('last_contacted_at')
                                    ->label('Last contacted')
                                    ->inlineLabel()
                                    ->hiddenOn('create')
                                    ->content(fn (?Lead $record): string => $record?->last_contacted_at?->diffForHumans() ?? 'Not yet'),
                            ]),

                        Forms\Components\Section::make('Customer')
                            ->icon('heroicon-o-user-circle')
                            ->visible(fn (?Lead $record): bool => (bool) $record?->customer_id)
                            ->schema([
                                Forms\Components\Placeholder::make('customer_link')
                                    ->hiddenLabel()
                                    ->content(fn (?Lead $record): HtmlString => new HtmlString(
                                        '<a href="'.e(CustomerResource::getUrl('edit', ['record' => $record->customer_id])).'" style="color: rgb(var(--primary-500)); font-weight: 600">'
                                        .e($record->customer?->name).' →</a><div style="font-size: .8rem; opacity: .7">Converted '.e($record->converted_at?->format('d M Y')).'</div>'
                                    )),
                            ]),

                        Forms\Components\Section::make('Internal notes')
                            ->icon('heroicon-o-lock-closed')
                            ->collapsible()
                            ->schema([
                                Forms\Components\Textarea::make('notes')
                                    ->hiddenLabel()
                                    ->rows(4),
                            ]),
                    ]),
            ]);
    }

    /** Small step bar: "Step 4 of 11" with the won part highlighted. */
    public static function stageProgress(?Pipeline $pipeline, ?string $stage): HtmlString
    {
        if (! $pipeline) {
            return new HtmlString('');
        }
        if ($stage === Pipeline::LOST) {
            return new HtmlString('<span style="color:rgb(var(--danger-500));font-weight:600">Lost</span>');
        }

        $stages = array_keys($pipeline->stages());
        $current = max(0, $pipeline->stageIndex($stage));
        $bars = '';
        foreach ($stages as $i => $key) {
            $color = $i <= $current ? ($pipeline->isWon($key) ? 'var(--success-500)' : 'var(--primary-500)') : 'var(--gray-400)';
            $opacity = $i <= $current ? '1' : '.25';
            $bars .= '<span title="'.e($pipeline->stageLabel($key)).'" style="flex:1;height:6px;border-radius:3px;background:rgb('.$color.');opacity:'.$opacity.'"></span>';
        }
        $next = $stages[$current + 1] ?? null;

        return new HtmlString('<div style="display:flex;gap:3px">'.$bars.'</div><div style="font-size:.75rem;opacity:.7;margin-top:.35rem">Step '.($current + 1).' of '.count($stages)
            .($next ? ' · next: '.e($pipeline->stageLabel($next)) : ' · final stage').'</div>');
    }

    /** Warns while typing if the phone/email already belongs to a customer or another open lead. */
    protected static function existingContactHint(?string $value, ?Lead $record): ?string
    {
        if (blank($value) || strlen($value) < 6) {
            return null;
        }

        $isEmail = str_contains($value, '@');
        $match = fn (Builder $q) => $isEmail
            ? $q->where('email', $value)
            : Phone::whereMatches($q, ['phone', 'whatsapp'], $value);

        $customer = Customer::query()->where($match)->first();
        if ($customer) {
            return "Existing customer: {$customer->name}";
        }

        $lead = Lead::open()
            ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
            ->where($match)
            ->first();

        return $lead ? "Already an open lead: {$lead->name}" : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(['name', 'company_name', 'phone', 'whatsapp', 'email'])
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Lead $record): ?string => collect([$record->company_name, $record->phone ?: $record->email])->filter()->implode(' · ') ?: null),
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('service_type')
                    ->label('Interest')
                    ->badge()
                    ->description(fn (Lead $record): ?string => $record->interestedIn?->name),
                Tables\Columns\TextColumn::make('leadSource.name')
                    ->label('Source')
                    ->badge()
                    ->color('gray')
                    ->description(fn (Lead $record): ?string => data_get($record->platform, 'campaign_name') ?? data_get($record->platform, 'form_name'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('stage')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (Lead $record): string => $record->stageLabel())
                    ->color(fn (Lead $record): string => $record->pipeline?->stageColor($record->stage) ?? 'gray')
                    ->description(fn (Lead $record): ?string => $record->pipeline?->getLabel()),
                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('Salesperson')
                    ->placeholder('Unassigned')
                    ->description(fn (Lead $record): ?string => $record->assignment_status === 'pending' ? 'Not accepted yet' : null)
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('next_follow_up_at')
                    ->label('Follow-up')
                    ->dateTime('d M, H:i')
                    ->sortable()
                    ->placeholder('-')
                    ->color(fn (Lead $record): ?string => $record->next_follow_up_at?->isPast() && ! in_array($record->status, [LeadStatus::Converted, LeadStatus::Lost], true) ? 'danger' : null)
                    ->icon(fn (Lead $record): ?string => $record->next_follow_up_at?->isPast() && ! in_array($record->status, [LeadStatus::Converted, LeadStatus::Lost], true) ? 'heroicon-m-exclamation-circle' : null),
                Tables\Columns\TextColumn::make('preferred_date')
                    ->label('Wants it on')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('utm_campaign')
                    ->label('Campaign')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->since()
                    ->dateTimeTooltip('d M Y, H:i')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['leadSource', 'assignee', 'interestedIn']))
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\SelectFilter::make('pipeline')->options(Pipeline::class),
                Tables\Filters\SelectFilter::make('status')->options(LeadStatus::class)->multiple(),
                Tables\Filters\SelectFilter::make('lead_source_id')->label('Source')->relationship('leadSource', 'name')->multiple()->preload(),
                Tables\Filters\SelectFilter::make('channel')->label('Came in by')->options(\App\Support\Leads\LeadIntake::CHANNELS)->multiple(),
                Tables\Filters\SelectFilter::make('service_type')->label('Interest')->options(ServiceType::class),
                Tables\Filters\SelectFilter::make('priority')->options(LeadPriority::class),
                Tables\Filters\SelectFilter::make('assigned_to')->label('Salesperson')->relationship('assignee', 'name')->preload(),
                Tables\Filters\Filter::make('received')
                    ->form([
                        Forms\Components\DatePicker::make('from')->native(false),
                        Forms\Components\DatePicker::make('until')->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('whatsapp')
                    ->label('')
                    ->tooltip('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->url(fn (Lead $record): ?string => Phone::whatsappUrl($record->whatsapp ?: $record->phone, \App\Support\Options::message('msg_greeting', ['name' => $record->name])))
                    ->openUrlInNewTab()
                    ->visible(fn (Lead $record): bool => filled($record->whatsapp ?: $record->phone)),
                Tables\Actions\Action::make('call')
                    ->label('')
                    ->tooltip('Call')
                    ->icon('heroicon-o-phone')
                    ->color('info')
                    ->url(fn (Lead $record): ?string => Phone::telUrl($record->phone))
                    ->visible(fn (Lead $record): bool => filled($record->phone)),
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('quote')
                        ->label('Create quotation')
                        ->authorize('manage-quotations')
                        ->icon('heroicon-o-document-text')
                        ->url(fn (Lead $record): string => QuotationResource::getUrl('create', ['lead' => $record->id]))
                        ->visible(fn (Lead $record): bool => $record->stage !== Pipeline::LOST && ! $record->trashed()),
                    Tables\Actions\Action::make('book')
                        ->label('Create booking')
                        ->authorize('manage-bookings')
                        ->icon('heroicon-o-calendar-days')
                        ->color('success')
                        ->url(fn (Lead $record): string => BookingResource::getUrl('create', ['lead' => $record->id]))
                        ->visible(fn (Lead $record): bool => $record->pipeline !== Pipeline::B2b && $record->stage !== Pipeline::LOST && ! $record->trashed()),
                    self::convertAction(),
                    self::markLostAction(),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('assign')
                        ->authorize('assign-leads')
                        ->label('Assign to salesperson')
                        ->icon('heroicon-o-user-plus')
                        ->form([
                            Forms\Components\Select::make('assigned_to')
                                ->label('Salesperson')
                                ->options(fn () => User::where('is_active', true)->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', ['Customer', 'Driver']))->pluck('name', 'id'))
                                ->required(),
                        ])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['assigned_to' => $data['assigned_to']]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('markLost')
                        ->authorize('manage-leads')
                        ->label('Mark as lost')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->form([
                            Forms\Components\TextInput::make('lost_reason')
                                ->validationMessages(['required' => 'Please add why this lead was lost.'])
                                ->label('Reason')
                                ->datalist(fn (): array => \App\Support\Options::list('lost_reasons'))
                                ->required(),
                        ])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['stage' => Pipeline::LOST, 'lost_reason' => $data['lost_reason']]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-inbox-arrow-down')
            ->emptyStateHeading('No leads yet')
            ->emptyStateDescription('Leads from the website, WhatsApp and social media will appear here. You can also add one manually.');
    }

    public static function convertAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('convert')
            ->label('Create customer profile')
            ->authorize('manage-leads')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Creates a customer profile from this lead, or links the existing customer with the same phone or email. The pipeline stage stays the same.')
            ->visible(fn (Lead $record): bool => ! $record->customer_id && ! $record->trashed())
            ->action(function (Lead $record) {
                $customer = $record->convertToCustomer();

                Notification::make()
                    ->title('Customer profile ready')
                    ->body("Customer profile: {$customer->name}")
                    ->success()
                    ->actions([
                        \Filament\Notifications\Actions\Action::make('open')
                            ->label('Open customer')
                            ->url(CustomerResource::getUrl('edit', ['record' => $customer])),
                    ])
                    ->send();
            });
    }

    public static function markLostAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('lost')
            ->label('Mark as lost')
            ->authorize('manage-leads')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->form([
                Forms\Components\TextInput::make('lost_reason')
                    ->validationMessages(['required' => 'Please add why this lead was lost.'])
                    ->label('Reason')
                    ->datalist(fn (): array => \App\Support\Options::list('lost_reasons'))
                    ->required(),
            ])
            ->visible(fn (Lead $record): bool => $record->stage !== Pipeline::LOST)
            ->action(fn (Lead $record, array $data) => $record->update(['stage' => Pipeline::LOST, 'lost_reason' => $data['lost_reason']]));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ActivitiesRelationManager::class,
            \App\Filament\Resources\QuotationResource\RelationManagers\QuotationsRelationManager::class,
            \App\Filament\Resources\BookingResource\RelationManagers\BookingsRelationManager::class,
            \App\Filament\RelationManagers\HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
            'create' => Pages\CreateLead::route('/create'),
            'edit' => Pages\EditLead::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return \App\Support\Access::scopeOwn(parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]), 'lead');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'company_name', 'phone', 'whatsapp', 'email'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Stage' => $record->stageLabel(), 'Phone' => $record->phone];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) (static::getEloquentQuery()->withoutTrashed()->where('status', LeadStatus::New)->count() ?: '') ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'New leads';
    }
}
