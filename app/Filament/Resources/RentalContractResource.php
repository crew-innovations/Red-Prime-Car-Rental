<?php

namespace App\Filament\Resources;

use App\Enums\BookingStatus;
use App\Filament\Resources\RentalContractResource\Pages;
use App\Models\Booking;
use App\Models\RentalContract;
use App\Support\Access;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

/**
 * 18 Nov 2026: rental contracts (meeting item 1, Bilal: "same as on fleet"). Contracts are made
 * from a car booking; this is where they are listed, signed, changed and closed.
 */
class RentalContractResource extends Resource
{
    protected static ?string $model = RentalContract::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Bookings & drivers';

    protected static ?string $navigationLabel = 'Contracts';

    protected static ?string $modelLabel = 'rental contract';

    protected static ?string $slug = 'contracts';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canViewAny(): bool
    {
        return Access::allows('manage-contracts') && ! Access::isDriverOnly();
    }

    public static function canCreate(): bool
    {
        return false; // made from a booking ("Make rental contract", or "New contract" on the list)
    }

    public static function canEdit($record): bool
    {
        return false; // changes go through the contract buttons, each saving a new PDF version
    }

    public static function canDelete($record): bool
    {
        return Access::allows('delete-records') && $record->status === 'draft' && ! $record->signed_at;
    }

    public static function getEloquentQuery(): Builder
    {
        $own = Access::scopeOwn(Booking::query()->withoutGlobalScopes([SoftDeletingScope::class]), 'booking')->select('id');

        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])->whereIn('booking_id', $own);
    }

    public static function table(Table $table): Table
    {
        $aed = fn ($v) => 'AED '.number_format((float) $v);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['booking.bookable', 'customer']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Contract')->searchable()->sortable()->weight(FontWeight::SemiBold)
                    ->description(fn (RentalContract $r): ?string => $r->customer?->name),
                Tables\Columns\TextColumn::make('booking.bookable.name')->label('Car')
                    ->description(fn (RentalContract $r): ?string => collect([$r->booking?->bookable?->registration_number, $r->booking?->drive_mode?->getLabel()])->filter()->implode(' · ') ?: null),
                Tables\Columns\TextColumn::make('booking.start_at')->label('Dates')
                    ->formatStateUsing(fn (RentalContract $r): string => $r->booking ? $r->booking->start_at->format('d M').' → '.$r->booking->end_at->format('d M Y') : '-')
                    ->description(fn (RentalContract $r): ?string => $r->booking && $r->status === 'open' && $r->booking->end_at->isPast() ? 'overdue' : ($r->booking?->reference))
                    ->color(fn (RentalContract $r): ?string => $r->booking && $r->status === 'open' && $r->booking->end_at->isPast() ? 'danger' : null),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => RentalContract::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => RentalContract::STATUS_COLORS[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('type')->formatStateUsing(fn (string $state): string => RentalContract::TYPES[$state] ?? $state)->toggleable(),
                Tables\Columns\IconColumn::make('signed_at')->label('Signed')->boolean()->getStateUsing(fn (RentalContract $r): bool => (bool) $r->signed_at),
                Tables\Columns\TextColumn::make('booking.balance')->label('Balance')->alignEnd()
                    ->formatStateUsing(fn ($state): string => $aed($state))
                    ->color(fn ($state): ?string => (float) $state > 0 ? 'danger' : null),
                Tables\Columns\TextColumn::make('booking.deposit_mode')->label('Deposit')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state): string => $state === 'none' ? 'No deposit' : 'Security deposit'),
                Tables\Columns\TextColumn::make('booking.sales_source')->label('Source')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(RentalContract::STATUSES)->multiple(),
                Tables\Filters\SelectFilter::make('type')->options(RentalContract::TYPES),
                Tables\Filters\TernaryFilter::make('signed')->label('Signed')
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('signed_at'), false: fn (Builder $query) => $query->whereNull('signed_at')),
                Tables\Filters\SelectFilter::make('deposit')->label('Deposit')->options(['security' => 'Security deposit', 'none' => 'No deposit'])
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('booking', fn ($b) => $data['value'] === 'none' ? $b->where('deposit_mode', 'none') : $b->where(fn ($w) => $w->whereNull('deposit_mode')->orWhere('deposit_mode', 'security')))
                        : $query),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')->label('PDF')->icon('heroicon-o-document-arrow-down')->iconButton()->tooltip('Latest PDF')
                    ->url(fn (RentalContract $r): string => route('pdf.contract', $r), true),
                Tables\Actions\ViewAction::make()->iconButton(),
            ])
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading('No contracts here')
            ->emptyStateDescription('Open a car booking and click "Make rental contract", or use "New contract" above.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
        $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : '—';

        return $infolist->columns(3)->schema([
            Infolists\Components\Group::make()->columnSpan(['lg' => 2])->schema([
                Infolists\Components\Section::make('Customer and drivers')->icon('heroicon-o-user')->schema([
                    Infolists\Components\TextEntry::make('customer_line')->hiddenLabel()->html()
                        ->state(function (RentalContract $r): string {
                            $c = $r->customer;
                            $warn = $c?->isBlacklisted() ? '<div style="color:rgb(var(--danger-600));font-weight:700">Blacklisted'.($c->blacklist_reason ? ': '.e($c->blacklist_reason) : '').'</div>' : '';
                            $missing = $r->booking ? \App\Support\Contracts\ConfirmCheck::missing($r->booking) : [];

                            return $warn.'<div style="font-weight:600">'.e($c?->name).($c?->company_name ? ' · '.e($c->company_name) : '').'</div>'
                                .'<div style="opacity:.75">'.e(collect([$c?->phone, $c?->email, $c?->nationality, ['resident' => 'UAE resident', 'gcc' => 'GCC', 'tourist' => 'Tourist'][$c?->residency] ?? null])->filter()->implode(' · ')).'</div>'
                                .($missing ? '<div style="margin-top:.4rem;color:rgb(var(--warning-600))">Still missing: '.e(implode(', ', $missing)).'. Add them from the booking (customer pencil).</div>' : '');
                        }),
                    Infolists\Components\RepeatableEntry::make('drivers')->hiddenLabel()->columns(4)->schema([
                        Infolists\Components\TextEntry::make('name')->label(fn ($record) => 'Driver')->weight(FontWeight::SemiBold)
                            ->formatStateUsing(fn ($state, $record) => $state.($record->is_main ? ' (main)' : ' (additional)')),
                        Infolists\Components\TextEntry::make('id_number')->label('ID / passport')->placeholder('—'),
                        Infolists\Components\TextEntry::make('licence_number')->label('Licence')->placeholder('missing')
                            ->formatStateUsing(fn ($state, $record) => $state.($record->licence_country ? ' ('.$record->licence_country.')' : '')),
                        Infolists\Components\TextEntry::make('licence_expires_on')->label('Licence expiry')->date('d M Y')->placeholder('missing')
                            ->color(fn ($record) => $record->licence_expires_on && $record->contract?->booking?->end_at && $record->licence_expires_on->lt($record->contract->booking->end_at) ? 'danger' : null),
                    ]),
                ]),
                Infolists\Components\Section::make('Car and dates')->icon('heroicon-o-truck')->columns(3)->schema([
                    Infolists\Components\TextEntry::make('booking.bookable.name')->label('Car')
                        ->formatStateUsing(fn ($state, RentalContract $r) => $state.($r->booking?->bookable?->registration_number ? ' · '.$r->booking->bookable->registration_number : '')),
                    Infolists\Components\TextEntry::make('booking.start_at')->label('Pick-up')->dateTime('D d M Y, H:i'),
                    Infolists\Components\TextEntry::make('booking.end_at')->label('Return')->dateTime('D d M Y, H:i'),
                    Infolists\Components\TextEntry::make('booking.drive_mode')->label('Rental type')->badge(),
                    Infolists\Components\TextEntry::make('km')->label('Kilometres')
                        ->state(fn (RentalContract $r): string => ($l = $r->booking?->kmLimitPerDay()) ? number_format($l).' km/day'.(($x = $r->booking->extraKmRate()) ? ', then AED '.number_format($x, 2).'/km' : '') : 'No limit'),
                    Infolists\Components\TextEntry::make('handover')->label('Out / in')
                        ->state(fn (RentalContract $r): string => $r->booking?->delivered_at
                            ? 'Out '.$r->booking->delivered_at->format('d M H:i').($r->booking->delivery_km ? ' · '.number_format($r->booking->delivery_km).' km' : '')
                                .($r->booking->returned_at ? ' · In '.$r->booking->returned_at->format('d M H:i').($r->booking->return_km ? ' · '.number_format($r->booking->return_km).' km' : '') : '')
                            : 'Not handed over yet'),
                ]),
                Infolists\Components\Section::make('Replaced cars')->icon('heroicon-o-arrows-right-left')->collapsible()
                    ->visible(fn (RentalContract $r): bool => $r->swaps()->exists())
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('swaps')->hiddenLabel()->columns(4)->schema([
                            Infolists\Components\TextEntry::make('swapped_at')->label('When')->dateTime('d M Y H:i'),
                            Infolists\Components\TextEntry::make('fromCar.name')->label('From'),
                            Infolists\Components\TextEntry::make('toCar.name')->label('To'),
                            Infolists\Components\TextEntry::make('reason')->label('Why'),
                        ]),
                    ]),
                Infolists\Components\Section::make('Money')->icon('heroicon-o-banknotes')->columns(4)->schema([
                    Infolists\Components\TextEntry::make('total')->label('Total')->state(fn (RentalContract $r): string => $aed($r->booking?->amountDue())),
                    Infolists\Components\TextEntry::make('booking.paid_amount')->label('Paid')->formatStateUsing(fn ($state) => $aed($state)),
                    Infolists\Components\TextEntry::make('booking.balance')->label('Balance')->formatStateUsing(fn ($state) => $aed($state))->color(fn ($state) => (float) $state > 0 ? 'danger' : 'success'),
                    Infolists\Components\TextEntry::make('deposit')->label('Deposit')
                        ->state(fn (RentalContract $r): string => $r->booking?->deposit_mode === 'none' ? 'No deposit'.((float) $r->booking->no_deposit_fee > 0 ? ' (fee AED '.number_format((float) $r->booking->no_deposit_fee, 2).')' : '') : $aed($r->booking?->security_deposit).' · held '.$aed($r->booking?->depositHeld())),
                    Infolists\Components\TextEntry::make('invoices_list')->label('Period invoices')->html()->columnSpanFull()
                        ->visible(fn (RentalContract $r): bool => $r->billing_period === 'monthly')
                        ->state(fn (RentalContract $r): string => ($list = $r->invoices()->where('type', 'invoice')->orderBy('period_start')->get())->isEmpty()
                            ? 'None yet'.($r->next_invoice_on ? ' · first on '.$r->next_invoice_on->format('d M Y') : '')
                            : $list->map(fn ($i) => '<a target="_blank" style="text-decoration:underline" href="'.e(route('pdf.invoice', $i)).'">'.e($i->number).'</a> '.e(optional($i->period_start)->format('d M').' – '.optional($i->period_end)->format('d M Y')).' · AED '.number_format((float) $i->total, 2).($i->status !== 'issued' ? ' ('.$i->status.')' : ''))->implode('<br>')
                                .($r->next_invoice_on ? '<br><span style="opacity:.7">Next on '.$r->next_invoice_on->format('d M Y').'</span>' : '')),
                ]),
                Infolists\Components\Section::make('Damage reports')->icon('heroicon-o-exclamation-triangle')->collapsible()
                    ->visible(fn (RentalContract $r): bool => $r->booking && $r->booking->damageReports()->exists())
                    ->schema([
                        Infolists\Components\TextEntry::make('damage_list')->hiddenLabel()->html()
                            ->state(fn (RentalContract $r): string => $r->booking->damageReports()->latest()->get()
                                ->map(fn ($d) => '<div><b>'.e(['delivery' => 'OUT', 'collection' => 'IN'][$d->stage] ?? 'During').'</b> · '.e(collect([$d->area, $d->description])->filter()->implode(': ')).' <span style="opacity:.7">· '.e($d->statusLabel()).' · '.$d->created_at->format('d M Y').'</span></div>')->implode('')),
                    ]),
            ]),
            Infolists\Components\Group::make()->columnSpan(['lg' => 1])->schema([
                Infolists\Components\Section::make('Contract')->icon('heroicon-o-document-text')->schema([
                    Infolists\Components\TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (string $state): string => RentalContract::STATUSES[$state] ?? $state)
                        ->color(fn (string $state): string => RentalContract::STATUS_COLORS[$state] ?? 'gray'),
                    Infolists\Components\TextEntry::make('type')->formatStateUsing(fn ($state) => RentalContract::TYPES[$state] ?? $state),
                    Infolists\Components\TextEntry::make('signed')->label('Signed')->html()
                        ->state(fn (RentalContract $r): string => $r->signed_at
                            ? '<span style="color:rgb(var(--success-600));font-weight:600">'.e($r->signed_by_name).'</span> · '.$r->signed_at->format('d M Y H:i').' ('.e($r->signed_via).')'
                            : '<span style="color:rgb(var(--warning-600));font-weight:600">Not signed yet</span>'),
                    Infolists\Components\TextEntry::make('paper_number')->label('Paper contract no.')->visible(fn (RentalContract $r): bool => filled($r->paper_number)),
                    Infolists\Components\TextEntry::make('lpo_number')->label('LPO')->visible(fn (RentalContract $r): bool => filled($r->lpo_number)),
                    Infolists\Components\TextEntry::make('billing_period')->label('Invoices')->formatStateUsing(fn ($state) => $state === 'monthly' ? 'One per month'.'' : 'One for the rental')
                        ->suffix(fn (RentalContract $r): string => $r->auto_renew ? ' · renews automatically' : ''),
                    Infolists\Components\TextEntry::make('booking.reference')->label('Booking')
                        ->url(fn (RentalContract $r): ?string => $r->booking ? BookingResource::getUrl('edit', ['record' => $r->booking]) : null),
                    Infolists\Components\TextEntry::make('notes')->placeholder('—')->visible(fn (RentalContract $r): bool => filled($r->notes)),
                ]),
                Infolists\Components\Section::make('Rental agreement history')->icon('heroicon-o-clock')->schema([
                    Infolists\Components\TextEntry::make('versions_list')->hiddenLabel()->html()
                        ->state(fn (RentalContract $r): string => $r->versions()->with('creator')->get()
                            ->map(fn ($v) => '<div style="padding:.2rem 0"><a target="_blank" style="font-weight:600;text-decoration:underline" href="'.e($v->url()).'">v'.$v->version.'</a> · '
                                .$v->created_at->format('d M Y H:i').' · '.e($v->creator?->name ?? 'System').'<div style="opacity:.75;font-size:.85em">'.e($v->reason).'</div></div>')->implode('') ?: 'No PDF yet'),
                ]),
                Infolists\Components\Section::make('Attachments')->icon('heroicon-o-paper-clip')->collapsible()
                    ->visible(fn (RentalContract $r): bool => $r->getMedia('attachments')->isNotEmpty())
                    ->schema([
                        Infolists\Components\TextEntry::make('attachments_list')->hiddenLabel()->html()
                            ->state(fn (RentalContract $r): string => $r->getMedia('attachments')
                                ->map(fn ($m) => '<div><a target="_blank" style="text-decoration:underline" href="'.e($m->getTemporaryUrl(now()->addMinutes(30))).'">'.e($m->file_name).'</a></div>')->implode('')),
                    ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRentalContracts::route('/'),
            'view' => Pages\ViewRentalContract::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'customer.name'];
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = static::getEloquentQuery()->withoutTrashed()->where('status', 'draft')->whereNull('signed_at')
            ->whereHas('booking', fn ($q) => $q->where('status', BookingStatus::Confirmed)->where('start_at', '<=', now()->addDay()))->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Not signed yet, car goes out within a day';
    }

    /** Car bookings that can get a contract (for "New contract"). */
    public static function bookingsWithoutContract(): array
    {
        return Access::scopeOwn(Booking::query(), 'booking')
            ->where('service_type', 'car')
            ->whereIn('status', [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::InProgress])
            ->whereDoesntHave('contract')
            ->with(['customer', 'bookable'])->orderBy('start_at')->limit(200)->get()
            ->mapWithKeys(fn (Booking $b) => [$b->id => $b->reference.' · '.($b->customer?->name ?? '?').' · '.($b->bookable?->name ?? '').' · '.$b->start_at->format('d M')])
            ->all();
    }
}
