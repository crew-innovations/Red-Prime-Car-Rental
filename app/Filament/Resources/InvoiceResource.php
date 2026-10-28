<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Invoice;
use App\Support\Access;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Finance › Invoices (28 Oct, Priority 7.3 / 8.1): invoices and credit notes
 * issued from bookings. Issued documents are never edited or deleted; a wrong
 * one is voided (kept, with the reason) or corrected with a credit note.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationGroup = 'Accounts & reports';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canViewAny(): bool
    {
        return ! Access::isDriverOnly() && (Access::allows('record-payments') || Access::allows('manage-finance'));
    }

    public static function canCreate(): bool
    {
        return false; // issued from a booking
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')->weight(FontWeight::SemiBold)->searchable()->sortable()
                    ->description(fn (Invoice $r): string => $r->title()),
                Tables\Columns\TextColumn::make('buyer.name')->label('Customer')->searchable(query: fn (Builder $q, string $search) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')->placeholder('-')
                    ->url(fn (Invoice $r): ?string => $r->booking_id ? BookingResource::getUrl('edit', ['record' => $r->booking_id]) : null),
                Tables\Columns\TextColumn::make('issued_on')->label('Date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('total')->money('AED')->alignEnd()->sortable()
                    ->color(fn (Invoice $r): ?string => $r->isCreditNote() ? 'danger' : null)
                    ->formatStateUsing(fn (Invoice $r, $state) => ($r->isCreditNote() ? '− ' : '').'AED '.number_format((float) $state, 2)),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => $state === 'void' ? 'danger' : 'success')
                    ->formatStateUsing(fn (string $state, Invoice $r): string => $state === 'void' ? 'Void' : ($r->is_tax_invoice ? 'Issued · VAT' : 'Issued')),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options(['invoice' => 'Invoices', 'credit_note' => 'Credit notes']),
                Tables\Filters\SelectFilter::make('status')->options(['issued' => 'Issued', 'void' => 'Void']),
                Tables\Filters\Filter::make('month')
                    ->form([Forms\Components\DatePicker::make('from')->native(false), Forms\Components\DatePicker::make('until')->native(false)])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('issued_on', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('issued_on', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')->label('PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->url(fn (Invoice $r): string => route('pdf.invoice', $r), shouldOpenInNewTab: true),
                Tables\Actions\ActionGroup::make([
                    self::creditAction(Tables\Actions\Action::class),
                    self::voidAction(Tables\Actions\Action::class),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-document-currency-dollar')
            ->emptyStateHeading('No invoices yet')
            ->emptyStateDescription('Issue one from a booking: Documents › Issue invoice.');
    }

    public static function voidAction(string $class)
    {
        return $class::make('void')
            ->label('Void')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->authorize('manage-finance')
            ->visible(fn (Invoice $record): bool => $record->status === 'issued')
            ->form([Forms\Components\TextInput::make('reason')->required()->maxLength(200)])
            ->modalDescription('The document is kept and marked void with your reason. It cannot be undone; issue a new one if needed.')
            ->action(function (Invoice $record, array $data) {
                $record->void($data['reason']);
                Notification::make()->title($record->number.' voided')->success()->send();
            });
    }

    public static function creditAction(string $class)
    {
        return $class::make('credit')
            ->label('Credit note')
            ->icon('heroicon-o-receipt-refund')
            ->color('warning')
            ->authorize('manage-finance')
            ->visible(fn (Invoice $record): bool => ! $record->isCreditNote() && $record->status === 'issued')
            ->form(fn (Invoice $record): array => [
                Forms\Components\TextInput::make('amount')->numeric()->minValue(0.01)->prefix('AED')->required()
                    ->default(round((float) $record->total - (float) $record->creditNotes()->where('status', 'issued')->sum('total'), 2)),
                Forms\Components\TextInput::make('reason')->label('Reason (printed)')->required()->maxLength(200)->placeholder('Refund for early return'),
            ])
            ->modalDescription('A credit note reduces this invoice. Record the refund itself under Accounts.')
            ->action(function (Invoice $record, array $data) {
                try {
                    $cn = Invoice::creditFor($record, (float) $data['amount'], $data['reason']);
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }
                Notification::make()->title('Credit note '.$cn->number.' issued')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['booking', 'customer']);

        // Agents: only invoices of their own bookings.
        return Access::agentScoped()
            ? $query->whereIn('booking_id', Access::scopeOwn(\App\Models\Booking::query(), 'booking')->select('id'))
            : $query;
    }
}
