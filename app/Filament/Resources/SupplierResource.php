<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Filament\Resources\SupplierResource\RelationManagers;
use App\Models\Supplier;
use App\Support\Access;
use App\Support\Suppliers\SupplierLedger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** Fleet › Suppliers (29 Oct): owners and rental partners, their cost rates, bills and payments. */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Fleet';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-suppliers');
    }

    public static function canCreate(): bool
    {
        return Access::allows('manage-suppliers');
    }

    public static function canEdit(Model $record): bool
    {
        return Access::allows('manage-suppliers');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // set to Inactive: history keeps pointing to it
    }

    public static function canModify(): bool
    {
        return Access::allows('manage-suppliers');
    }

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\Section::make('Supplier')->columnSpan(2)->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('Company / owner name')->required()->maxLength(150),
                Forms\Components\Select::make('type')->options(Supplier::TYPES)->default('car_owner')->required()->native(false),
                Forms\Components\TextInput::make('contact_person')->maxLength(120),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(40),
                Forms\Components\TextInput::make('email')->email()->maxLength(150),
                Forms\Components\TextInput::make('trn')->label('TRN')->maxLength(30),
                Forms\Components\TextInput::make('address')->columnSpanFull()->maxLength(255),
                Forms\Components\Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),
            Forms\Components\Group::make()->columnSpan(1)->schema([
                Forms\Components\Section::make('Status')->schema([
                    Forms\Components\ToggleButtons::make('status')->options(Supplier::STATUSES)->colors(Supplier::COLORS)->default('active')->required()
                        ->helperText('Blocked suppliers can\'t be used on new bookings.'),
                ]),
                Forms\Components\Section::make('Money')->schema([
                    Forms\Components\TextInput::make('payment_terms')->placeholder('e.g. 30 days after the job')->maxLength(120),
                    Forms\Components\TextInput::make('opening_balance')->label('Opening balance we owe')->numeric()->prefix('AED')->default(0)
                        ->helperText('What RED PRIME owed this supplier before using the CRM.')
                        ->disabled(fn (?Supplier $record): bool => $record && ! Access::allows('manage-finance'))
                        ->dehydrated(fn (?Supplier $record): bool => ! $record || Access::allows('manage-finance')),
                    Forms\Components\DatePicker::make('opening_balance_date')->label('As of')
                        ->disabled(fn (?Supplier $record): bool => $record && ! Access::allows('manage-finance'))
                        ->dehydrated(fn (?Supplier $record): bool => ! $record || Access::allows('manage-finance')),
                ]),
                Forms\Components\Section::make('Documents')->schema([
                    Forms\Components\SpatieMediaLibraryFileUpload::make('documents')->collection('documents')->disk('private')->visibility('private')
                        ->multiple()->maxSize(10240)->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->hiddenLabel()
                        ->helperText('Contract, trade licence… (PDF or photo).'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $finance = fn (): bool => Access::allows('manage-finance');

        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight(FontWeight::SemiBold)->searchable()->sortable()
                    ->description(fn (Supplier $r): string => Supplier::TYPES[$r->type] ?? $r->type),
                Tables\Columns\TextColumn::make('contact_person')->label('Contact')->searchable()
                    ->description(fn (Supplier $r): ?string => $r->phone),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn (string $state): string => Supplier::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => Supplier::COLORS[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('vehicles')->label('Vehicles')
                    ->state(fn (Supplier $r): int => $r->cars()->count() + $r->yachts()->count()),
                Tables\Columns\TextColumn::make('rates_count')->label('Cost rates')->counts(['rates' => fn ($q) => $q->where('is_active', true)])
                    ->color(fn ($state): ?string => $state ? null : 'danger')
                    ->formatStateUsing(fn ($state): string => $state ? (string) $state : 'None set'),
                Tables\Columns\TextColumn::make('balance')->label('We owe')->visible($finance)
                    ->state(fn (Supplier $r): float => SupplierLedger::balance($r)['balance'])->money('AED')
                    ->color(fn ($state): ?string => $state > 0 ? 'warning' : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(Supplier::STATUSES)->multiple(),
                Tables\Filters\SelectFilter::make('type')->options(Supplier::TYPES),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RatesRelationManager::class,
            RelationManagers\BillsRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
            \App\Filament\RelationManagers\HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
