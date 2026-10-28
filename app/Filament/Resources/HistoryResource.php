<?php

namespace App\Filament\Resources;

use App\Filament\RelationManagers\HistoryRelationManager;
use App\Filament\Resources\HistoryResource\Pages;
use App\Models\AuditLog;
use App\Support\Access;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/** All changes across the CRM in one read-only list. Each record also keeps its own History tab. */
class HistoryResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $slug = 'history';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'History';

    protected static ?string $modelLabel = 'change';

    protected static ?string $pluralModelLabel = 'History';

    protected static ?int $navigationSort = 5;

    /** Record types people can filter by, with the page that opens them. */
    public const TYPES = [
        'lead' => ['Lead', LeadResource::class],
        'customer' => ['Customer', CustomerResource::class],
        'quotation' => ['Quotation', QuotationResource::class],
        'booking' => ['Booking', BookingResource::class],
        'transaction' => ['Transaction', TransactionResource::class],
        'car' => ['Car', CarResource::class],
        'trip' => ['Yacht trip', TripResource::class],
        'package' => ['Trip / package', PackageResource::class],
        'customer_document' => ['Document', null],
        'user' => ['Login / team member', null],
        'driver_profile' => ['Driver profile', null],
        'driver_document' => ['Driver document', null],
        'booking_job' => ['Driver job', null],
        'damage_report' => ['Damage report', null],
        'yacht' => ['Yacht (old)', YachtResource::class],
    ];

    public static function canViewAny(): bool
    {
        return Access::allows('view-history');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function recordLabel(AuditLog $log): string
    {
        $type = self::TYPES[$log->auditable_type][0] ?? ucfirst(str_replace('_', ' ', (string) $log->auditable_type));
        $item = $log->auditable;
        $name = $item ? ($item->reference ?? $item->number ?? $item->name ?? $item->title ?? null) : null;

        return $type.' · '.($name ?: '#'.$log->auditable_id);
    }

    public static function recordUrl(AuditLog $log): ?string
    {
        $resource = self::TYPES[$log->auditable_type][1] ?? null;
        if (! $resource || ! $log->auditable || $log->event === 'deleted') {
            return null;
        }

        return $resource::getUrl('edit', ['record' => $log->auditable_id]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'auditable']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Who')->placeholder('System'),
                Tables\Columns\TextColumn::make('record')
                    ->label('Record')
                    ->state(fn (AuditLog $record): string => self::recordLabel($record))
                    ->url(fn (AuditLog $record): ?string => self::recordUrl($record))
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('event')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        'override' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('changes')
                    ->label('What changed')
                    ->state(fn (AuditLog $record): HtmlString => new HtmlString(HistoryRelationManager::describe($record)))
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('auditable_type')
                    ->label('Record type')
                    ->options(collect(self::TYPES)->map(fn (array $t): string => $t[0])->all()),
                Tables\Filters\SelectFilter::make('event')
                    ->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted', 'restored' => 'Restored', 'override' => 'Override']),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Who')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->native(false)->displayFormat('d M Y'),
                        \Filament\Forms\Components\DatePicker::make('until')->native(false)->displayFormat('d M Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading('No changes recorded yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHistory::route('/'),
        ];
    }
}
