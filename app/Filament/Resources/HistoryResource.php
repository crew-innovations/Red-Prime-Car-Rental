<?php

namespace App\Filament\Resources;

use App\Filament\RelationManagers\HistoryRelationManager;
use App\Filament\Resources\HistoryResource\Pages;
use App\Models\AuditLog;
use App\Support\Audit\AuditView;
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

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'log entry';

    protected static ?string $pluralModelLabel = 'Audit log';

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
        'yacht' => ['Yacht', YachtResource::class],
        'invoice' => ['Invoice', InvoiceResource::class],
        'price_rule' => ['Special rate', null],
        'price_extra' => ['Extra / fee', null],
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
        return AuditView::recordLabel($log);
    }

    public static function recordUrl(AuditLog $log): ?string
    {
        return AuditView::recordUrl($log);
    }

    /** 19 Nov: who did it, which module and record, what changed, when. Safe for any old line. */
    public static function table(Table $table): Table
    {
        return $table
            // Only the user is loaded with the list. Records are loaded line by line by AuditView, so a
            // line whose record type no longer exists can never stop the screen (the old server error).
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i')->sortable()
                    ->description(fn (AuditLog $record): ?string => $record->created_at?->diffForHumans()),
                Tables\Columns\TextColumn::make('user.name')->label('Who')->placeholder('System / website')
                    ->description(fn (AuditLog $record): ?string => $record->role)
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('event')->label('Action')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AuditLog::EVENTS[$state] ?? ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state, AuditLog $record): string => match (true) {
                        $record->outcome === 'failed', $record->outcome === 'denied', $state === 'login_failed', $state === 'deleted' => 'danger',
                        $state === 'created' => 'success',
                        in_array($state, ['login', 'logout'], true) => 'info',
                        in_array($state, ['override', 'permissions', 'settings', 'export', 'archive'], true) => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (AuditLog $record): ?string => match ($record->outcome) {
                        'failed' => 'Failed', 'denied' => 'Refused', default => null,
                    }),
                Tables\Columns\TextColumn::make('module')->label('Module')
                    ->state(fn (AuditLog $record): string => AuditView::module($record))
                    ->description(fn (AuditLog $record): ?string => $record->auditable_type ? AuditView::recordLabel($record) : null)
                    ->url(fn (AuditLog $record): ?string => AuditView::recordUrl($record)),
                Tables\Columns\TextColumn::make('changes')
                    ->label('Details')
                    ->state(fn (AuditLog $record): HtmlString => new HtmlString(AuditView::summary($record)))
                    ->wrap(),
                Tables\Columns\TextColumn::make('ip_address')->label('IP')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('user_agent')->label('Device')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('request_id')->label('Request')->limit(8)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->searchable()
            ->searchPlaceholder('Search: name, record number, words in the details, IP')
            ->modifyQueryUsing(function (Builder $query, $livewire) {
                $query->with('user');
                $search = trim((string) ($livewire->getTableSearch() ?? ''));
                if ($search === '') {
                    return $query;
                }
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

                return $query->where(fn (Builder $q) => $q
                    ->where('note', 'like', $like)
                    ->orWhere('event', 'like', $like)
                    ->orWhere('ip_address', 'like', $like)
                    ->orWhere('new_values', 'like', $like)
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like))
                    ->when(ctype_digit($search), fn (Builder $q2) => $q2->orWhere('auditable_id', (int) $search)));
            })
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Who (agent / staff)')
                    ->relationship('user', 'name', fn (Builder $query) => $query->whereHas('roles', fn ($r) => $r->whereNotIn('name', ['Customer'])))
                    ->searchable()
                    ->preload()
                    ->multiple(),
                Tables\Filters\SelectFilter::make('module')
                    ->label('Module')
                    ->options(fn (): array => collect(AuditView::moduleOptions())->keys()->mapWithKeys(fn ($m) => [$m => $m])->all())
                    ->multiple()
                    ->query(function (Builder $query, array $data) {
                        $picked = (array) ($data['values'] ?? []);
                        if ($picked === []) {
                            return $query;
                        }
                        $map = AuditView::moduleOptions();
                        $types = collect($picked)->flatMap(fn ($m) => $map[$m]['types'] ?? [])->all();
                        $events = collect($picked)->flatMap(fn ($m) => $map[$m]['events'] ?? [])->all();

                        $signIns = array_values(array_intersect($events, ['login', 'logout', 'login_failed']));
                        $other = array_values(array_diff($events, $signIns));

                        return $query->where(fn (Builder $q) => $q
                            ->when($types, fn ($q2) => $q2->orWhere(fn ($q3) => $q3->whereIn('auditable_type', $types)->whereNotIn('event', ['login', 'logout', 'login_failed'])))
                            ->when($signIns, fn ($q2) => $q2->orWhereIn('event', $signIns))
                            ->when($other, fn ($q2) => $q2->orWhere(fn ($q3) => $q3->whereNull('auditable_type')->whereIn('event', $other))));
                    }),
                Tables\Filters\SelectFilter::make('event')
                    ->label('Action')
                    ->multiple()
                    ->options(AuditLog::EVENTS),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\Select::make('period')->label('Date')->native(false)->placeholder('Any time')
                            ->options(['today' => 'Today', 'yesterday' => 'Yesterday', '7' => 'Last 7 days', '30' => 'Last 30 days', 'custom' => 'Choose dates'])->live(),
                        \Filament\Forms\Components\DatePicker::make('from')->native(false)->displayFormat('d M Y')
                            ->visible(fn (\Filament\Forms\Get $get) => $get('period') === 'custom'),
                        \Filament\Forms\Components\DatePicker::make('until')->native(false)->displayFormat('d M Y')
                            ->visible(fn (\Filament\Forms\Get $get) => $get('period') === 'custom'),
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['period'] ?? null) {
                        'today' => $query->where('created_at', '>=', today()),
                        'yesterday' => $query->whereBetween('created_at', [today()->subDay(), today()]),
                        '7', '30' => $query->where('created_at', '>=', today()->subDays((int) $data['period'] - 1)),
                        'custom' => $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', \Illuminate\Support\Carbon::parse($d)->startOfDay()))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', \Illuminate\Support\Carbon::parse($d)->endOfDay())),
                        default => $query,
                    })
                    ->indicateUsing(fn (array $data): ?string => match ($data['period'] ?? null) {
                        'today' => 'Today', 'yesterday' => 'Yesterday', '7' => 'Last 7 days', '30' => 'Last 30 days',
                        'custom' => trim(($data['from'] ?? '').' – '.($data['until'] ?? ''), ' –') ?: null,
                        default => null,
                    }),
                Tables\Filters\SelectFilter::make('role')
                    ->options(fn (): array => collect(['Super Admin', ...Access::STAFF_ROLES, 'Customer', ...array_keys(Access::LEGACY)])->unique()->mapWithKeys(fn ($r) => [$r => $r])->all()),
                Tables\Filters\SelectFilter::make('outcome')
                    ->label('Result')
                    ->options(['ok' => 'Done', 'failed' => 'Failed', 'denied' => 'Refused'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'ok' => $query->whereNull('outcome'),
                        'failed', 'denied' => $query->where('outcome', $data['value']),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('auditable_type')
                    ->label('Record type')
                    ->options(collect(self::TYPES)->map(fn (array $t): string => $t[0])->all()),
                Tables\Filters\Filter::make('record')
                    ->form([\Filament\Forms\Components\TextInput::make('record_id')->label('Record number')->numeric()])
                    ->query(fn (Builder $query, array $data) => $query->when($data['record_id'] ?? null, fn ($q, $id) => $q->where('auditable_id', $id))),
            ], layout: Tables\Enums\FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->actions([
                Tables\Actions\Action::make('details')
                    ->label('Open')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (AuditLog $record): string => (AuditLog::EVENTS[$record->event] ?? ucfirst($record->event)).' · '.AuditView::module($record))
                    ->modalContent(fn (AuditLog $record) => view('filament.audit.details', [
                        'log' => $record,
                        'changes' => AuditView::changes($record),
                        'record' => AuditView::recordLabel($record),
                        'url' => AuditView::recordUrl($record),
                        'sameClick' => $record->request_id ? AuditLog::where('request_id', $record->request_id)->whereKeyNot($record->id)->count() : 0,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->recordAction('details')
            ->paginated([25, 50, 100])
            ->poll(null)
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading('Nothing found')
            ->emptyStateDescription('Try fewer filters or a wider date.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHistory::route('/'),
        ];
    }
}
