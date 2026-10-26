@php
    $b = $this->record;
    $payments = $this->payments();
    $docs = $b->documentChecklist();
@endphp
<x-filament-panels::page>
    <div class="rp-acc">
        <div class="rp-acc-card">
            <div class="rp-acc-row">
                <div>
                    <div class="rp-acc-big">{{ $b->bookable?->name ?? $b->service_type?->getLabel() }}</div>
                    <div class="rp-acc-muted">{{ $b->reference }}</div>
                </div>
                <x-filament::badge :color="$b->status->getColor()">{{ $b->status->getLabel() }}</x-filament::badge>
            </div>
            <dl class="rp-acc-dl">
                <dt>{{ $b->isCar() ? 'Pick-up / delivery' : 'Starts' }}</dt><dd>{{ $b->start_at->format('D d M Y, H:i') }}@if ($b->pickup_location)<br><span class="rp-acc-muted">{{ $b->pickup_location }}</span>@endif</dd>
                <dt>{{ $b->isCar() ? 'Return' : 'Ends' }}</dt><dd>{{ $b->end_at->format('D d M Y, H:i') }}@if ($b->return_location)<br><span class="rp-acc-muted">{{ $b->return_location }}</span>@endif</dd>
                @if ($b->guests)<dt>Guests</dt><dd>{{ $b->guests }}</dd>@endif
            </dl>
        </div>

        @if (\App\Support\CustomerArea::allows('customer_sees_payments'))
            <div class="rp-acc-card">
                <div class="rp-acc-label">Price</div>
                <table class="rp-acc-table">
                    <tr><td>Total</td><td class="r">AED {{ number_format($b->amountDue(), 2) }}</td></tr>
                    @if ((float) $b->tax_amount > 0)<tr><td class="rp-acc-muted">Includes VAT</td><td class="r rp-acc-muted">AED {{ number_format((float) $b->tax_amount, 2) }}</td></tr>@endif
                    <tr><td>Paid</td><td class="r">AED {{ number_format((float) $b->paid_amount, 2) }}</td></tr>
                    <tr><td><strong>Balance due</strong></td><td class="r"><strong>AED {{ number_format((float) $b->balance, 2) }}</strong></td></tr>
                    @if ((float) $b->security_deposit > 0)<tr><td class="rp-acc-muted">Refundable security deposit</td><td class="r rp-acc-muted">AED {{ number_format((float) $b->security_deposit, 2) }}</td></tr>@endif
                </table>
                @if ($payments->isNotEmpty())
                    <div class="rp-acc-label" style="margin-top:1rem">Payments</div>
                    <table class="rp-acc-table">
                        @foreach ($payments as $p)
                            <tr>
                                <td>{{ $p->date?->format('d M Y') }}<br><span class="rp-acc-muted">{{ $p->type === \App\Enums\TransactionType::Expense ? 'Deposit refund' : ($p->category === 'deposit' ? 'Security deposit' : 'Payment') }}{{ $p->method ? ' · '.$p->method->getLabel() : '' }}</span></td>
                                <td class="r">{{ $p->type === \App\Enums\TransactionType::Expense ? '−' : '' }}AED {{ number_format((float) ($p->amount_paid ?: $p->amount), 2) }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </div>
        @endif

        @if ($docs)
            <div class="rp-acc-card">
                <div class="rp-acc-label">Documents for this booking</div>
                <table class="rp-acc-table">
                    @foreach ($docs as [$label, $text, $ok])
                        <tr><td>{{ $label }}</td><td class="r"><x-filament::badge :color="$ok ? 'success' : 'warning'">{{ $text }}</x-filament::badge></td></tr>
                    @endforeach
                </table>
                <a href="{{ \App\Filament\Account\Pages\Documents::getUrl() }}" class="rp-acc-link">Upload a document</a>
            </div>
        @endif
    </div>
</x-filament-panels::page>
