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

    protected static ?int $navigationSort = 1;

    public const ROLE_DESCRIPTIONS = [
        'Super Admin' => 'Full access, including settings and users',
        'Admin' => 'Manages the CRM and team',
        'Manager' => 'Oversees sales and operations',
        'Sales' => 'Leads, customers and quotations',
        'Operations' => 'Fleet, bookings and deliveries',
        'Finance' => 'Payments and reports',
        'Staff' => 'Limited day-to-day access',
    ];

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['Super Admin', 'Admin']);
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
                        Forms\Components\CheckboxList::make('roles')
                            ->relationship('roles', 'name')
                            ->descriptions(self::ROLE_DESCRIPTIONS)
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
                    ->color(fn (string $state): string => match ($state) {
                        'Super Admin', 'Admin' => 'danger',
                        'Manager' => 'warning',
                        'Sales' => 'success',
                        'Operations' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('phone')->placeholder('-'),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active')
                    ->disabled(fn (User $record): bool => $record->is(auth()->user())),
                Tables\Columns\TextColumn::make('created_at')->label('Added')->date('d M Y')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')->relationship('roles', 'name')->preload(),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
            ]);
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
