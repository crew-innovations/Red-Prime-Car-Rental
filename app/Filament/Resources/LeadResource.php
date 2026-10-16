<?php

namespace App\Filament\Resources;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
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
use Filament\Forms;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Form;
use Filament\Forms\Get;
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
    protected static ?string $model = Lead::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
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
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
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
                                    ->options(ServiceType::class)
                                    ->inline()
                                    ->live()
                                    ->columnSpanFull(),
                                MorphToSelect::make('interestedIn')
                                    ->label('Specific car, yacht or trip')
                                    ->types([
                                        MorphToSelect\Type::make(Car::class)->titleAttribute('name')->label('Car'),
                                        MorphToSelect\Type::make(Yacht::class)->titleAttribute('name')->label('Yacht'),
                                        MorphToSelect\Type::make(Trip::class)->titleAttribute('name')->label('Yacht trip'),
                                    ])
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
                                    ->visible(fn (Get $get): bool => in_array($get('service_type'), [ServiceType::Yacht->value, ServiceType::Trip->value], true)),
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
                            ]),
                    ]),

                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Forms\Components\Section::make('Status')
                            ->icon('heroicon-o-signal')
                            ->schema([
                                Forms\Components\ToggleButtons::make('status')
                                    ->hiddenLabel()
                                    ->options(LeadStatus::class)
                                    ->default(LeadStatus::New)
                                    ->disableOptionWhen(fn (string $value, ?Lead $record): bool => $value === LeadStatus::Converted->value && ! $record?->customer_id)
                                    ->required()
                                    ->live()
                                    ->columns(2),
                                Forms\Components\TextInput::make('lost_reason')
                                    ->label('Why was it lost?')
                                    ->datalist(['Price too high', 'Car not available', 'Booked with competitor', 'No response', 'Changed plans', 'Not eligible (age / licence)'])
                                    ->required(fn (Get $get): bool => $get('status') === LeadStatus::Lost->value)
                                    ->visible(fn (Get $get): bool => $get('status') === LeadStatus::Lost->value),
                                Forms\Components\ToggleButtons::make('priority')
                                    ->options(LeadPriority::class)
                                    ->default(LeadPriority::Warm)
                                    ->inline(),
                            ]),

                        Forms\Components\Section::make('Follow-up')
                            ->icon('heroicon-o-clock')
                            ->schema([
                                Forms\Components\Select::make('assigned_to')
                                    ->label('Salesperson')
                                    ->relationship('assignee', 'name', fn (Builder $query) => $query->where('is_active', true))
                                    ->default(fn () => auth()->id())
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

    /** Warns while typing if the phone/email already belongs to a customer or another open lead. */
    protected static function existingContactHint(?string $value, ?Lead $record): ?string
    {
        if (blank($value) || strlen($value) < 6) {
            return null;
        }

        $customer = Customer::where('phone', $value)->orWhere('whatsapp', $value)->orWhere('email', $value)->first();
        if ($customer) {
            return "Existing customer: {$customer->name}";
        }

        $lead = Lead::open()
            ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
            ->where(fn (Builder $q) => $q->where('phone', $value)->orWhere('whatsapp', $value)->orWhere('email', $value))
            ->first();

        return $lead ? "Already an open lead: {$lead->name}" : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(['name', 'phone', 'whatsapp', 'email'])
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Lead $record): ?string => $record->phone ?: $record->email),
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
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('Salesperson')
                    ->placeholder('Unassigned')
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
                Tables\Filters\SelectFilter::make('status')->options(LeadStatus::class)->multiple(),
                Tables\Filters\SelectFilter::make('lead_source_id')->label('Source')->relationship('leadSource', 'name')->multiple()->preload(),
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
                    ->url(fn (Lead $record): ?string => Phone::whatsappUrl($record->whatsapp ?: $record->phone, "Hi {$record->name}, this is RED PRIME RENTALS. "))
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
                    self::convertAction(),
                    self::markLostAction(),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('assign')
                        ->label('Assign to salesperson')
                        ->icon('heroicon-o-user-plus')
                        ->form([
                            Forms\Components\Select::make('assigned_to')
                                ->label('Salesperson')
                                ->options(fn () => User::where('is_active', true)->pluck('name', 'id'))
                                ->required(),
                        ])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['assigned_to' => $data['assigned_to']]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('changeStatus')
                        ->label('Change status')
                        ->icon('heroicon-o-signal')
                        ->form([
                            Forms\Components\Select::make('status')
                                ->options(collect(LeadStatus::cases())->reject(fn ($s) => $s === LeadStatus::Converted)->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()]))
                                ->required(),
                        ])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['status' => $data['status']]))
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
            ->label('Convert to customer')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Creates a customer profile from this lead (or links the existing customer with the same phone or email) and marks the lead as converted.')
            ->visible(fn (Lead $record): bool => ! $record->customer_id && ! $record->trashed())
            ->action(function (Lead $record) {
                $customer = $record->convertToCustomer();

                Notification::make()
                    ->title('Lead converted')
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
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->form([
                Forms\Components\TextInput::make('lost_reason')
                    ->label('Reason')
                    ->datalist(['Price too high', 'Car not available', 'Booked with competitor', 'No response', 'Changed plans', 'Not eligible (age / licence)'])
                    ->required(),
            ])
            ->visible(fn (Lead $record): bool => ! in_array($record->status, [LeadStatus::Converted, LeadStatus::Lost], true))
            ->action(fn (Lead $record, array $data) => $record->update(['status' => LeadStatus::Lost, 'lost_reason' => $data['lost_reason']]));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ActivitiesRelationManager::class,
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
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'whatsapp', 'email'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Status' => $record->status?->getLabel(), 'Phone' => $record->phone];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) (static::getModel()::where('status', LeadStatus::New)->count() ?: '') ?: null;
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
