<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentRequestResource\Pages;
use App\Models\PaymentRequest;
use App\Support\Access;
use App\Support\Payments\GatewayError;
use App\Support\Payments\Payments;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Accounts › Online payments: every payment link, its gateway, status and refunds (28 Oct-2, Phase 13). */
class PaymentRequestResource extends Resource
{
    protected static ?string $model = PaymentRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Accounts & reports';

    protected static ?string $navigationLabel = 'Online payments';

    protected static ?string $modelLabel = 'payment link';

    protected static ?string $pluralModelLabel = 'online payments';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canViewAny(): bool
    {
        return ! Access::isDriverOnly() && (Access::allows('record-payments') || Access::allows('manage-finance'));
    }

    public static function canCreate(): bool
    {
        return false; // asked for from a booking or quotation
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
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Link')->weight(FontWeight::SemiBold)->searchable()->sortable()
                    ->description(fn (PaymentRequest $r): string => PaymentRequest::PURPOSES[$r->purpose] ?? $r->purpose),
                Tables\Columns\TextColumn::make('for')->label('For')->state(fn (PaymentRequest $r): string => $r->forLabel())
                    ->description(fn (PaymentRequest $r): ?string => $r->customerName())
                    ->url(fn (PaymentRequest $r): ?string => $r->booking_id ? BookingResource::getUrl('edit', ['record' => $r->booking_id]) : ($r->quotation_id ? QuotationResource::getUrl('edit', ['record' => $r->quotation_id]) : null)),
                Tables\Columns\TextColumn::make('amount')->label('Amount')->money('AED')->sortable()
                    ->description(fn (PaymentRequest $r): ?string => (float) $r->refunded_amount > 0 ? 'Refunded AED '.number_format((float) $r->refunded_amount, 2) : null),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state, PaymentRequest $r): string => $r->isPayable() || $r->isPaid() || $state !== 'open' ? (PaymentRequest::STATUSES[$state] ?? $state) : 'Expired')
                    ->color(fn (string $state): string => PaymentRequest::COLORS[$state] ?? 'gray')
                    ->description(fn (PaymentRequest $r): ?string => $r->last_error ? \Illuminate\Support\Str::limit($r->last_error, 70) : null),
                Tables\Columns\TextColumn::make('provider')->label('Paid with')->placeholder('—')
                    ->formatStateUsing(fn (?string $state, PaymentRequest $r): string => ucfirst((string) $state).($r->mode === 'test' ? ' · test' : ''))
                    ->color(fn (PaymentRequest $r): ?string => $r->mode === 'test' ? 'warning' : null),
                Tables\Columns\TextColumn::make('paid_at')->label('Paid')->dateTime('d M Y H:i')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('creator.name')->label('Made by')->placeholder('Customer')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Made')->dateTime('d M Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(PaymentRequest::STATUSES)->multiple(),
                Tables\Filters\SelectFilter::make('provider')->label('Gateway')->options(collect(Payments::GATEWAYS)->mapWithKeys(fn ($c, $k) => [$k => ucfirst($k)])->all()),
                Tables\Filters\SelectFilter::make('booking')->relationship('booking', 'reference')->searchable(),
                Tables\Filters\TernaryFilter::make('test')->label('Test mode')->queries(true: fn ($q) => $q->where('mode', 'test'), false: fn ($q) => $q->where('mode', 'live')),
            ])
            ->actions([
                Tables\Actions\Action::make('copy')->label('Link')->icon('heroicon-o-link')->color('gray')
                    ->visible(fn (PaymentRequest $r): bool => $r->isPayable())
                    ->modalHeading(fn (PaymentRequest $r): string => 'Payment link '.$r->number)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->form(fn (PaymentRequest $r): array => [
                        Forms\Components\TextInput::make('url')->label('Send this to the customer')->default($r->url())->readOnly()->extraInputAttributes(['onclick' => 'this.select()']),
                        Forms\Components\Placeholder::make('wa')->hiddenLabel()->content(fn () => ($phone = preg_replace('/\D+/', '', (string) $r->customerPhone()))
                            ? new \Illuminate\Support\HtmlString('<a target="_blank" rel="noopener" style="font-weight:600;color:rgb(var(--primary-600))" href="https://wa.me/'.$phone.'?text='.rawurlencode('Payment for '.$r->forLabel().': AED '.number_format((float) $r->amount, 2).' · '.$r->url()).'">Open WhatsApp with this link</a> (you press send)')
                            : 'No phone number on the customer.'),
                    ]),
                Tables\Actions\Action::make('cancel')->label('Cancel link')->icon('heroicon-o-x-circle')->color('gray')
                    ->visible(fn (PaymentRequest $r): bool => in_array($r->status, ['open', 'processing', 'failed'], true) && Access::allows('record-payments'))
                    ->requiresConfirmation()->modalDescription('The customer can no longer pay with this link. A payment already started at the provider is still recorded if it goes through.')
                    ->action(fn (PaymentRequest $r) => Payments::cancel($r, 'Cancelled by '.auth()->user()?->name)),
                Tables\Actions\Action::make('refund')->label('Refund')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (PaymentRequest $r): bool => $r->refundable() > 0 && Access::allows('manage-finance'))
                    ->modalDescription(fn (PaymentRequest $r): string => 'Sent to '.ucfirst((string) $r->provider).' now ('.($r->mode === 'live' ? 'LIVE: real money' : 'test mode').'). Up to AED '.number_format($r->refundable(), 2).'.')
                    ->form(fn (PaymentRequest $r): array => [
                        Forms\Components\TextInput::make('amount')->numeric()->prefix('AED')->minValue(0.01)->maxValue($r->refundable())->default($r->refundable())->required(),
                        Forms\Components\TextInput::make('reason')->label('Why?')->required()->maxLength(200),
                    ])
                    ->action(function (PaymentRequest $r, array $data) {
                        try {
                            $out = Payments::refund($r, (float) $data['amount'], $data['reason'], auth()->user());
                        } catch (GatewayError $e) {
                            Notification::make()->title('Refund refused')->body($e->getMessage())->danger()->persistent()->send();

                            return;
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Refund '.($out['status'] === 'pending' ? 'sent (the provider says pending)' : 'done'))->success()->send();
                    }),
            ])
            ->emptyStateHeading('No payment links yet')
            ->emptyStateDescription('Ask for a payment online from a booking or an accepted quotation (Money › Payment link). A gateway must be connected in Settings › Integrations.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPaymentRequests::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['booking', 'quotation', 'customer', 'creator']);

        return Access::agentScoped()
            ? $query->where(fn ($q) => $q->whereIn('booking_id', Access::scopeOwn(\App\Models\Booking::query(), 'booking')->select('id'))
                ->orWhereIn('quotation_id', Access::scopeOwn(\App\Models\Quotation::query(), 'quotation')->select('id')))
            : $query;
    }

    /** "Payment link" action for a booking or quotation page (Filament\Actions\Action). */
    public static function linkAction(string $class, callable $record)
    {
        return $class::make('paymentLink')
            ->label('Payment link (online)')
            ->icon('heroicon-o-credit-card')
            ->authorize('record-payments')
            ->modalWidth('lg')
            ->modalHeading('Ask for a payment online')
            ->modalSubmitActionLabel('Make the link')
            ->modalDescription(fn (): string => Payments::connected()
                ? 'The customer pays on a secure page with: '.collect(Payments::connected())->map->label()->implode(', ').'. The money is recorded automatically once the provider confirms it.'
                : 'No payment gateway is connected yet. An admin connects one in Settings › Integrations.')
            ->form(function () use ($record): array {
                $for = $record();
                $purposes = $for instanceof \App\Models\Booking
                    ? collect((float) $for->advance_required > 0 && ! $for->advance_reached_at ? ['advance', 'balance', 'security_deposit'] : ['balance', 'advance', 'security_deposit'])
                        ->filter(fn ($p) => Payments::limit($for, $p) > 0)
                    : collect(['advance', 'balance'])->filter(fn ($p) => Payments::limit($for, $p) > 0);

                return [
                    Forms\Components\ToggleButtons::make('purpose')->label('For')->inline()->required()->live()
                        ->options($purposes->mapWithKeys(fn ($p) => [$p => PaymentRequest::PURPOSES[$p]])->all())
                        ->default($purposes->first())
                        ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('amount', $state ? Payments::defaultAmount($record(), $state) : null)),
                    Forms\Components\TextInput::make('amount')->numeric()->prefix('AED')->minValue(0.01)->required()
                        ->default(fn () => $purposes->first() ? Payments::defaultAmount($for, $purposes->first()) : null)
                        ->helperText(fn (Forms\Get $get): ?string => $get('purpose') ? 'At most AED '.number_format(Payments::limit($record(), $get('purpose')), 2).' (open links count).' : null),
                    Forms\Components\CheckboxList::make('providers')->label('Payment methods offered')->helperText('None ticked = all connected ones.')
                        ->options(collect(Payments::connected())->map->label()->all())->columns(3),
                ];
            })
            ->action(function (array $data) use ($record) {
                try {
                    $link = Payments::request($record(), (string) $data['purpose'], (float) $data['amount'], $data['providers'] ?? null, null, auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }
                Notification::make()->title('Payment link '.$link->number.' made')->body($link->url())
                    ->actions([\Filament\Notifications\Actions\Action::make('open')->label('Open the payment page')->url($link->url(), shouldOpenInNewTab: true)])
                    ->success()->persistent()->send();
            });
    }
}
