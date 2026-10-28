<?php

namespace App\Filament\Resources;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Car;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\Yacht;
use App\Filament\Concerns\GuardedResource;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** Accounts: revenue and expenses. */
class TransactionResource extends Resource
{
    use GuardedResource;

    protected static function manageAbility(): string
    {
        return 'manage-finance';
    }

    protected static function deleteAbility(): string
    {
        return 'manage-finance';
    }

    public static function canCreate(): bool
    {
        return Access::allows('record-payments');
    }

    public static function canViewAny(): bool
    {
        return Access::allows('record-payments');
    }

    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Transactions';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->disabled(fn (): bool => ! static::canModify())->columns(3)->schema(self::formSchema());
    }

    /**
     * Shared with the "Accounts" tab on cars, yachts and customers, where the
     * link to the car/yacht or customer is filled in automatically.
     */
    public static function formSchema(bool $withRelated = true, bool $withCustomer = true): array
    {
        $isIncome = fn (Get $get): bool => $get('type') === TransactionType::Income->value;
        $status = fn (Get $get): ?string => $get('status');

        return [
            Forms\Components\Section::make()
                ->columnSpan(['lg' => 2])
                ->columns(2)
                ->schema([
                    Forms\Components\ToggleButtons::make('type')
                        ->options(TransactionType::class)
                        ->default(TransactionType::Income->value)
                        ->inline()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('category', null))
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('amount')
                        ->numeric()
                        ->minValue(0.01)
                        ->prefix('AED')
                        ->required()
                        ->live(onBlur: true),
                    Forms\Components\DatePicker::make('date')
                        ->default(today())
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->required(),
                    Forms\Components\Select::make('category')
                        ->options(fn (Get $get): array => Transaction::categories($get('type')))
                        ->searchable()
                        ->required(),
                    Forms\Components\Select::make('method')
                        ->label('Payment method')
                        ->options(PaymentMethod::class)
                        ->native(false),
                    Forms\Components\TextInput::make('description')
                        ->placeholder(fn (Get $get): string => $isIncome($get) ? 'e.g. Urus rental 12-15 Oct' : 'e.g. Oil change, ENOC fuel')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('vendor')
                        ->label('Paid to (vendor)')
                        ->placeholder('ENOC, Al Futtaim service, car wash…')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => ! $isIncome($get)),
                    Forms\Components\Select::make('booking_id')
                        ->label('Booking')
                        ->relationship('booking', 'reference', fn (Builder $query) => \App\Support\Access::scopeOwn($query, 'booking'))
                        ->searchable()
                        ->helperText(fn (Get $get): ?string => $isIncome($get) ? null : 'Link a cost to a booking to see that booking\'s profit.')
                        ->visible($withRelated),
                    Forms\Components\Toggle::make('is_estimate')
                        ->label('Estimate (not paid / not final yet)')
                        ->helperText('Shown apart from actual costs until you untick it.')
                        ->visible(fn (Get $get): bool => ! $isIncome($get))
                        ->inline(false),
                    Forms\Components\SpatieMediaLibraryFileUpload::make('receipts')
                        ->label('Receipt / invoice')
                        ->collection('receipts')->disk('private')->visibility('private')
                        ->multiple()->maxFiles(5)->maxSize(10240)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->columnSpanFull(),
                    Forms\Components\Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'name')
                        ->searchable(['name', 'phone', 'company_name'])
                        ->preload()
                        ->visible(fn (Get $get): bool => $withCustomer && $isIncome($get)),
                    MorphToSelect::make('related')
                        ->label('For car, yacht or trip')
                        ->types([
                            MorphToSelect\Type::make(Car::class)->titleAttribute('name')->label('Car'),
                            MorphToSelect\Type::make(Trip::class)->titleAttribute('name')->label('Yacht trip'),
                            MorphToSelect\Type::make(\App\Models\Package::class)->titleAttribute('name')->label('Trip / package'),
                            MorphToSelect\Type::make(Yacht::class)->titleAttribute('name')->label('Yacht (old)'),
                        ])
                        ->searchable()
                        ->preload()
                        ->visible($withRelated)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Payment')
                ->columnSpan(['lg' => 1])
                ->schema([
                    Forms\Components\ToggleButtons::make('status')
                        ->hiddenLabel()
                        ->options(PaymentStatus::class)
                        ->default(PaymentStatus::Paid->value)
                        ->required()
                        ->live()
                        ->columns(2),
                    Forms\Components\TextInput::make('amount_paid')
                        ->label('Amount received so far')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(fn (Get $get) => $get('amount') ?: null)
                        ->prefix('AED')
                        ->required(fn (Get $get): bool => $status($get) === PaymentStatus::PartiallyPaid->value)
                        ->visible(fn (Get $get): bool => $status($get) === PaymentStatus::PartiallyPaid->value),
                    Forms\Components\DatePicker::make('due_date')
                        ->label('Due by')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->visible(fn (Get $get): bool => in_array($status($get), [PaymentStatus::Pending->value, PaymentStatus::Overdue->value, PaymentStatus::PartiallyPaid->value], true)),
                    Forms\Components\TextInput::make('reference')
                        ->label('Reference')
                        ->placeholder('Invoice / receipt / bank ref')
                        ->maxLength(100),
                    Forms\Components\Textarea::make('notes')
                        ->rows(3),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::tableColumns())
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'related']))
            ->defaultSort('date', 'desc')
            ->striped()
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options(TransactionType::class),
                Tables\Filters\SelectFilter::make('category')->options(Transaction::categories())->multiple()->searchable(),
                Tables\Filters\SelectFilter::make('status')->options(PaymentStatus::class)->multiple(),
                Tables\Filters\SelectFilter::make('method')->label('Payment method')->options(PaymentMethod::class),
                Tables\Filters\SelectFilter::make('related_type')->label('For')->options(['car' => 'Cars', 'trip' => 'Yacht trips', 'package' => 'Trips & packages', 'yacht' => 'Yacht charters (old)']),
                Tables\Filters\SelectFilter::make('customer_id')->label('Customer')->relationship('customer', 'name')->searchable(),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->native(false),
                        Forms\Components\DatePicker::make('until')->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '<=', $d)))
                    ->indicateUsing(fn (array $data): ?string => ($data['from'] ?? null) || ($data['until'] ?? null)
                        ? 'Date: '.($data['from'] ?? '…').' to '.($data['until'] ?? '…')
                        : null),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                self::markPaidAction(),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->authorize('manage-finance')
                    ->visible(fn (Transaction $record): bool => $record->approval_status === 'pending')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Transaction $record): string => 'Counts AED '.number_format((float) $record->amount, 2).' as a cost from now on. Entered by '.($record->user?->name ?? 'someone').'.')
                    ->action(fn (Transaction $record) => $record->approve()),
                Tables\Actions\EditAction::make()->iconButton()
                    ->visible(fn (Transaction $record): bool => ! $record->reversed_at),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('reject')
                        ->label('Reject')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->authorize('manage-finance')
                        ->visible(fn (Transaction $record): bool => $record->approval_status === 'pending')
                        ->form([Forms\Components\TextInput::make('reason')->required()->maxLength(200)])
                        ->action(fn (Transaction $record, array $data) => $record->reject($data['reason'])),
                    Tables\Actions\Action::make('reverse')
                        ->label('Reverse (correction)')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('danger')
                        ->authorize('manage-finance')
                        ->visible(fn (Transaction $record): bool => ! $record->reversed_at && ! $record->is_balance)
                        ->form([Forms\Components\TextInput::make('reason')->label('Why?')->required()->maxLength(200)])
                        ->modalDescription('The row stays in the list, marked reversed with your reason, and leaves every total and balance. Enter the correct amount as a new row if needed.')
                        ->action(function (Transaction $record, array $data) {
                            try {
                                $record->reverse($data['reason']);
                            } catch (\Illuminate\Validation\ValidationException $e) {
                                \Filament\Notifications\Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                            }
                        }),
                    Tables\Actions\Action::make('receipt')
                        ->label('Receipt (PDF)')
                        ->icon('heroicon-o-receipt-percent')
                        ->visible(fn (Transaction $record): bool => $record->type === \App\Enums\TransactionType::Income && ! $record->is_balance && $record->status === \App\Enums\PaymentStatus::Paid)
                        ->url(fn (Transaction $record): string => route('pdf.receipt', $record), shouldOpenInNewTab: true),
                    Tables\Actions\ReplicateAction::make()
                        ->label('Duplicate')
                        ->excludeAttributes(['reference', 'deleted_at'])
                        ->beforeReplicaSaved(function (Transaction $replica): void {
                            $replica->date = today();
                        })
                        ->successRedirectUrl(fn (Transaction $replica): string => self::getUrl('edit', ['record' => $replica])),
                    // Paid money is corrected with "Reverse", never deleted; unpaid rows typed by mistake can go.
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (Transaction $record): bool => ! $record->is_balance && $record->status !== PaymentStatus::Paid && $record->status !== PaymentStatus::PartiallyPaid),
                    Tables\Actions\RestoreAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('markPaid')
                        ->label('Mark as paid')
                        ->authorize('record-payments')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn (Collection $records) => $records->each->update(['status' => PaymentStatus::Paid, 'amount_paid' => null]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading('No transactions yet')
            ->emptyStateDescription('Record payments received and expenses paid to see revenue and profit.');
    }

    /** Columns shared with the Accounts tab on cars, yachts and customers. */
    public static function tableColumns(bool $withRelated = true): array
    {
        return array_values(array_filter([
            Tables\Columns\TextColumn::make('date')
                ->date('d M Y')
                ->sortable(),
            Tables\Columns\TextColumn::make('category')
                ->label('Details')
                ->formatStateUsing(fn (Transaction $record): string => $record->categoryLabel())
                ->weight(FontWeight::SemiBold)
                ->description(fn (Transaction $record): ?string => $record->description ?: $record->reference)
                ->searchable(['description', 'reference']),
            Tables\Columns\TextColumn::make('type')
                ->badge()
                ->toggleable(),
            $withRelated ? Tables\Columns\TextColumn::make('related.name')
                ->label('For')
                ->placeholder('-')
                ->toggleable() : null,
            Tables\Columns\TextColumn::make('customer.name')
                ->label('Customer')
                ->placeholder('-')
                ->toggleable(),
            Tables\Columns\TextColumn::make('method')
                ->label('Method')
                ->badge()
                ->placeholder('-')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('status')
                ->badge()
                ->formatStateUsing(fn (Transaction $record, $state): string => match (true) {
                    (bool) $record->reversed_at => 'Reversed',
                    $record->approval_status === 'pending' => 'Waiting for approval',
                    $record->approval_status === 'rejected' => 'Rejected',
                    $record->is_estimate => 'Estimate',
                    default => $state instanceof PaymentStatus ? $state->getLabel() : (string) $state,
                })
                ->color(fn (Transaction $record): string => match (true) {
                    (bool) $record->reversed_at, $record->approval_status === 'rejected' => 'gray',
                    $record->approval_status === 'pending', $record->is_estimate => 'warning',
                    default => $record->status?->getColor() ?? 'gray',
                })
                ->description(fn (Transaction $record): ?string => match (true) {
                    (bool) $record->reversed_at => $record->reversal_reason,
                    $record->status === PaymentStatus::PartiallyPaid => 'AED '.number_format((float) $record->amount_paid).' received',
                    $record->due_date && in_array($record->status, [PaymentStatus::Pending, PaymentStatus::Overdue], true) => 'Due '.$record->due_date->format('d M'),
                    default => $record->vendor,
                }),
            Tables\Columns\TextColumn::make('amount')
                ->alignEnd()
                ->sortable()
                ->weight(FontWeight::Bold)
                ->formatStateUsing(fn (Transaction $record): string => ($record->type === TransactionType::Expense ? '− ' : '+ ').'AED '.number_format((float) $record->amount, 2))
                ->color(fn (Transaction $record): string => $record->type === TransactionType::Expense ? 'danger' : 'success')
                ->summarize([
                    Tables\Columns\Summarizers\Sum::make()
                        ->label('Income')
                        ->money('AED')
                        ->query(fn ($query) => $query->where('type', TransactionType::Income->value)->whereRaw(Transaction::COUNTS_SQL)),
                    Tables\Columns\Summarizers\Sum::make()
                        ->label('Expenses')
                        ->money('AED')
                        ->query(fn ($query) => $query->where('type', TransactionType::Expense->value)->whereRaw(Transaction::COUNTS_SQL)),
                ]),
        ]));
    }

    public static function markPaidAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('markPaid')
            ->label('')
            ->tooltip('Mark as paid')
            ->authorize('record-payments')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Mark as paid?')
            ->modalDescription(fn (Transaction $record): string => 'AED '.number_format((float) $record->amount, 2).' will be recorded as fully paid today.')
            ->visible(fn (Transaction $record): bool => in_array($record->status, [PaymentStatus::Pending, PaymentStatus::Overdue, PaymentStatus::PartiallyPaid], true))
            ->action(fn (Transaction $record) => $record->update(['status' => PaymentStatus::Paid, 'amount_paid' => null]));
    }

    public static function getWidgets(): array
    {
        return [
            TransactionResource\Widgets\FinanceOverview::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return \App\Support\Access::scopeOwn(parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]), 'transaction');
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = static::getEloquentQuery()->withoutTrashed()->where('status', PaymentStatus::Overdue)->count();

        return $overdue ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Overdue payments';
    }
}
