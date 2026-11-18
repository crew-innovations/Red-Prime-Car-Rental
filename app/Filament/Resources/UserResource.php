<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Internal CRM users and their roles (Schedule A14). */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Team & roles';

    protected static ?string $modelLabel = 'team member';

    protected static ?int $navigationSort = 2;

    /** 5 Nov: the four staff roles (App\Support\Access). Drivers are added under Drivers & crew. */
    public const ROLE_DESCRIPTIONS = \App\Support\Access::ROLE_DESCRIPTIONS;

    public static function canViewAny(): bool
    {
        return \App\Support\Access::allows('manage-team'); // Admin (and old Manager until moved); Super Admin always
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Section::make('Profile')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(30),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->rule('min:8')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state)) // hashed by the User model
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leave empty to keep the current password.' : 'At least 8 characters.'),
                    ]),
                Forms\Components\Section::make('Access')
                    ->columnSpan(1)
                    ->schema([
                        // 5 Nov: one of the four staff roles. Old roles (Manager, Sales…) and the hidden
                        // Super Admin are not offered; the form keeps them only if the person still has one.
                        Forms\Components\CheckboxList::make('roles')
                            ->label('Role')
                            ->relationship('roles', 'name', fn ($query, ?User $record) => $query->where(fn ($q) => $q
                                ->whereIn('name', \App\Support\Access::STAFF_ROLES)
                                ->when($record, fn ($q) => $q->orWhereIn('id', $record->roles()->where('name', '!=', 'Customer')->pluck('roles.id'))))
                                ->orderByRaw("CASE name WHEN 'Admin' THEN 1 WHEN 'Agent' THEN 2 WHEN 'Accounts' THEN 3 WHEN 'Driver' THEN 4 ELSE 9 END"))
                            // Options are keyed by role id, so the descriptions are too.
                            ->descriptions(fn (): array => \Spatie\Permission\Models\Role::query()->whereIn('name', array_keys(self::ROLE_DESCRIPTIONS))
                                ->pluck('name', 'id')->map(fn ($name) => self::ROLE_DESCRIPTIONS[$name])->all())
                            ->helperText('Usually one role. Admin can do everything.')
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Can log in')
                            ->default(true)
                            ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                            ->helperText('Switch off instead of deleting, so their history stays.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (User $record): string => $record->email),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match (\App\Support\Access::canonical($state)) {
                        'Super Admin', 'Admin' => 'danger',
                        'Agent' => 'success',
                        'Accounts' => 'info',
                        'Driver' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('phone')->placeholder('-'),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active')
                    ->disabled(fn (User $record): bool => $record->is(auth()->user())),
                Tables\Columns\TextColumn::make('created_at')->label('Added')->date('d M Y')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')->label('Role')
                    ->relationship('roles', 'name', fn ($query) => $query->whereIn('name', \App\Support\Access::STAFF_ROLES))->preload(),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
            ]);
    }

    /** Customer logins are managed from the customer's page, not here. The hidden technical account is never listed (5 Nov). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->whereNull('customer_id')->where('is_system', false);
    }

    public static function canDelete(Model $record): bool
    {
        return false; // deactivate instead, so leads and history keep their owner
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
