<?php

namespace App\Filament\Resources;

use App\Enums\CustomerType;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers;
use App\Models\Customer;
use App\Models\User;
use App\Support\Phone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public const NATIONALITIES = [
        'Emirati', 'Saudi', 'Kuwaiti', 'Qatari', 'Bahraini', 'Omani', 'Egyptian', 'Jordanian', 'Lebanese',
        'Syrian', 'Iraqi', 'Iranian', 'Turkish', 'Indian', 'Pakistani', 'Bangladeshi', 'Sri Lankan',
        'Filipino', 'Chinese', 'Japanese', 'Korean', 'Russian', 'Ukrainian', 'Kazakh', 'British', 'Irish',
        'French', 'German', 'Italian', 'Spanish', 'Dutch', 'Swiss', 'Swedish', 'Polish', 'Romanian',
        'American', 'Canadian', 'Brazilian', 'Mexican', 'Australian', 'New Zealander', 'South African',
        'Nigerian', 'Kenyan', 'Moroccan', 'Algerian', 'Tunisian', 'Other',
    ];

    public const LANGUAGES = [
        'English' => 'English', 'Arabic' => 'Arabic', 'Russian' => 'Russian', 'Hindi' => 'Hindi',
        'Urdu' => 'Urdu', 'Chinese' => 'Chinese', 'French' => 'French', 'German' => 'German',
        'Persian' => 'Persian', 'Turkish' => 'Turkish', 'Spanish' => 'Spanish',
    ];

    public const TAGS = ['Repeat customer', 'Influencer', 'Corporate account', 'Hotel guest', 'Long-term rental', 'Event booking', 'Late payer', 'Do not rent'];

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Forms\Components\Section::make('Customer')
                            ->icon('heroicon-o-user')
                            ->columns(2)
                            ->schema([
                                Forms\Components\ToggleButtons::make('type')
                                    ->options(CustomerType::class)
                                    ->default(CustomerType::Individual)
                                    ->inline()
                                    ->live()
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('name')
                                    ->label(fn (Get $get): string => $get('type') === CustomerType::Individual->value ? 'Full name' : 'Contact person')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('company_name')
                                    ->label('Company')
                                    ->maxLength(255)
                                    ->required(fn (Get $get): bool => $get('type') === CustomerType::Corporate->value)
                                    ->visible(fn (Get $get): bool => $get('type') !== CustomerType::Individual->value),
                                Forms\Components\TextInput::make('phone')
                                    ->tel()
                                    ->placeholder('+971 50 123 4567')
                                    ->maxLength(30)
                                    ->unique(ignoreRecord: true),
                                Forms\Components\TextInput::make('whatsapp')
                                    ->label('WhatsApp')
                                    ->tel()
                                    ->placeholder('Same as phone')
                                    ->maxLength(30),
                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true),
                                Forms\Components\ToggleButtons::make('preferred_contact')
                                    ->label('Prefers')
                                    ->options(['whatsapp' => 'WhatsApp', 'phone' => 'Call', 'email' => 'Email'])
                                    ->icons(['whatsapp' => 'heroicon-o-chat-bubble-left-right', 'phone' => 'heroicon-o-phone', 'email' => 'heroicon-o-envelope'])
                                    ->default('whatsapp')
                                    ->inline(),
                            ]),

                        Forms\Components\Section::make('Profile')
                            ->icon('heroicon-o-identification')
                            ->columns(3)
                            ->schema([
                                Forms\Components\Select::make('nationality')
                                    ->options(array_combine(self::NATIONALITIES, self::NATIONALITIES))
                                    ->searchable(),
                                Forms\Components\Select::make('residency')
                                    ->options(['resident' => 'UAE resident', 'tourist' => 'Tourist / visitor', 'gcc' => 'GCC national'])
                                    ->native(false),
                                Forms\Components\Select::make('language')
                                    ->label('Language')
                                    ->options(self::LANGUAGES)
                                    ->native(false),
                                Forms\Components\DatePicker::make('date_of_birth')
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->maxDate(today()->subYears(18))
                                    ->helperText('Used to check minimum driver age.'),
                                Forms\Components\TagsInput::make('tags')
                                    ->suggestions(self::TAGS)
                                    ->columnSpan(2),
                            ]),

                        Forms\Components\Placeholder::make('m2_note')
                            ->hiddenLabel()
                            ->content('Bookings, quotations, payments and ID documents for this customer are added in Milestone 2.'),
                    ]),

                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Forms\Components\Section::make('Account')
                            ->icon('heroicon-o-star')
                            ->schema([
                                Forms\Components\Toggle::make('is_vip')
                                    ->label('VIP customer')
                                    ->helperText('Shown with a star everywhere in the CRM.'),
                                Forms\Components\Select::make('lead_source_id')
                                    ->label('Came from')
                                    ->relationship('leadSource', 'name')
                                    ->searchable()
                                    ->preload(),
                                Forms\Components\Select::make('assigned_to')
                                    ->label('Account manager')
                                    ->relationship('assignee', 'name', fn (Builder $query) => $query->where('is_active', true))
                                    ->default(fn () => auth()->id())
                                    ->searchable()
                                    ->preload(),
                                Forms\Components\DateTimePicker::make('next_follow_up_at')
                                    ->label('Next follow-up')
                                    ->native(false)
                                    ->seconds(false)
                                    ->displayFormat('d M Y, H:i'),
                            ]),

                        Forms\Components\Section::make('Internal notes')
                            ->icon('heroicon-o-lock-closed')
                            ->collapsible()
                            ->schema([
                                Forms\Components\Textarea::make('notes')
                                    ->hiddenLabel()
                                    ->placeholder('Preferences, special requests, anything the team should know.')
                                    ->rows(5),
                            ]),

                        Forms\Components\Section::make()
                            ->hiddenOn('create')
                            ->schema([
                                Forms\Components\Placeholder::make('created_at')
                                    ->label('Customer since')
                                    ->inlineLabel()
                                    ->content(fn (?Customer $record): string => $record?->created_at?->format('d M Y') ?? '-'),
                                Forms\Components\Placeholder::make('leads_total')
                                    ->label('Enquiries')
                                    ->inlineLabel()
                                    ->content(fn (?Customer $record): string => (string) ($record?->leads()->count() ?? 0)),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(['name', 'company_name', 'phone', 'whatsapp', 'email'])
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->icon(fn (Customer $record): ?string => $record->is_vip ? 'heroicon-s-star' : null)
                    ->iconColor('warning')
                    ->iconPosition('after')
                    ->description(fn (Customer $record): ?string => $record->company_name ?: $record->email),
                Tables\Columns\TextColumn::make('phone')
                    ->copyable()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('nationality')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('tags')
                    ->badge()
                    ->color('gray')
                    ->separator(',')
                    ->limitList(2)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('leadSource.name')
                    ->label('Source')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('Manager')
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('leads_count')
                    ->label('Enquiries')
                    ->counts('leads')
                    ->sortable(),
                Tables\Columns\TextColumn::make('next_follow_up_at')
                    ->label('Follow-up')
                    ->dateTime('d M, H:i')
                    ->placeholder('-')
                    ->color(fn (Customer $record): ?string => $record->next_follow_up_at?->isPast() ? 'danger' : null)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Since')
                    ->date('d M Y')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['leadSource', 'assignee']))
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->filters([
                Tables\Filters\TernaryFilter::make('is_vip')->label('VIP'),
                Tables\Filters\SelectFilter::make('type')->options(CustomerType::class),
                Tables\Filters\SelectFilter::make('nationality')->options(array_combine(self::NATIONALITIES, self::NATIONALITIES))->searchable(),
                Tables\Filters\SelectFilter::make('lead_source_id')->label('Source')->relationship('leadSource', 'name')->preload(),
                Tables\Filters\SelectFilter::make('assigned_to')->label('Manager')->relationship('assignee', 'name')->preload(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('whatsapp')
                    ->label('')
                    ->tooltip('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->url(fn (Customer $record): ?string => Phone::whatsappUrl($record->whatsapp ?: $record->phone, "Hi {$record->name}, this is RED PRIME RENTALS. "))
                    ->openUrlInNewTab()
                    ->visible(fn (Customer $record): bool => filled($record->whatsapp ?: $record->phone)),
                Tables\Actions\Action::make('call')
                    ->label('')
                    ->tooltip('Call')
                    ->icon('heroicon-o-phone')
                    ->color('info')
                    ->url(fn (Customer $record): ?string => Phone::telUrl($record->phone))
                    ->visible(fn (Customer $record): bool => filled($record->phone)),
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('vip')
                        ->label('Mark as VIP')
                        ->icon('heroicon-o-star')
                        ->action(fn (Collection $records) => $records->each->update(['is_vip' => true]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('assign')
                        ->label('Assign account manager')
                        ->icon('heroicon-o-user-plus')
                        ->form([
                            Forms\Components\Select::make('assigned_to')
                                ->label('Manager')
                                ->options(fn () => User::where('is_active', true)->pluck('name', 'id'))
                                ->required(),
                        ])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['assigned_to' => $data['assigned_to']]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-users')
            ->emptyStateHeading('No customers yet')
            ->emptyStateDescription('Customers are created when you convert a lead, or you can add one directly.');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LeadsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'company_name', 'phone', 'whatsapp', 'email'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter(['Phone' => $record->phone, 'Company' => $record->company_name]);
    }
}
