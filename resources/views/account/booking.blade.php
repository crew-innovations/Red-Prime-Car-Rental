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
                    @if ((float) $b->advance_required > 0)
                        <tr><td>Advance to confirm{{ $b->advance_percent !== null ? ' ('.\App\Support\Pricing\Discounts::percentText((float) $b->advance_percent).')' : '' }}</td>
                            <td class="r">AED {{ number_format((float) $b->advance_required, 2) }}@if ($b->advance_reached_at)<br><span class="rp-acc-muted">Paid</span>@endif</td></tr>
                    @endif
                    <tr><td><strong>Balance due</strong>@if ((float) $b->balance > 0 && ($due = \App\Support\Pricing\Advance::balanceDue($b)))<br><span class="rp-acc-muted">by {{ $due->format('d M Y') }}</span>@endif</td><td class="r"><strong>AED {{ number_format((float) $b->balance, 2) }}</strong></td></tr>
                    @if ((float) $b->security_deposit > 0)<tr><td class="rp-acc-muted">Refundable security deposit</td><td class="r rp-acc-muted">AED {{ number_format((float) $b->security_deposit, 2) }}</td></tr>@endif
                </table>
                @php($online = $this->onlinePayment())
                @if ($online['open']->isNotEmpty() || $online['balance'] > 0 || ($online['advance'] ?? 0) > 0)
                    <div class="rp-acc-links" style="margin-top:1rem">
                        @foreach ($online['open'] as $link)
                            <a class="rp-acc-link" href="{{ $link->url() }}">Pay AED {{ number_format((float) $link->amount, 2) }} online · {{ \App\Models\PaymentRequest::PURPOSES[$link->purpose] ?? 'Payment' }}</a>
                        @endforeach
                        @if (($online['advance'] ?? 0) > 0)
                            <x-filament::button wire:click="payOnline('advance')" icon="heroicon-o-credit-card">Pay the advance (AED {{ number_format($online['advance'], 2) }})</x-filament::button>
                        @endif
                        @if ($online['balance'] > 0)
                            <x-filament::button wire:click="payOnline('balance')" :color="($online['advance'] ?? 0) > 0 ? 'gray' : 'primary'" icon="heroicon-o-credit-card">Pay the balance (AED {{ number_format($online['balance'], 2) }})</x-filament::button>
                        @endif
                    </div>
                @endif
                @if ($payments->isNotEmpty())
                    <div class="rp-acc-label" style="margin-top:1rem">Payments</div>
                    <table class="rp-acc-table">
                        @foreach ($payments as $p)
                            <tr>
                                <td>{{ $p->date?->format('d M Y') }}<br><span class="rp-acc-muted">{{ $p->type === \App\Enums\TransactionType::Expense ? 'Deposit refund' : ($p->category === 'deposit' ? 'Security deposit' : 'Payment') }}{{ $p->method ? ' · '.$p->method->getLabel() : '' }}</span></td>
                                <td class="r">{{ $p->type === \App\Enums\TransactionType::Expense ? '−' : '' }}AED {{ number_format((float) ($p->amount_paid ?: $p->amount), 2) }}
                                    @if ($p->type === \App\Enums\TransactionType::Income && $p->status === \App\Enums\PaymentStatus::Paid)<br><a class="rp-acc-link" href="{{ route('pdf.receipt', $p) }}" target="_blank" rel="noopener">Receipt</a>@endif</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </div>
        @endif

        {{-- 4 Nov (Phase 4): fines, tolls and fees added after the booking, and the deposit settlement. --}}
        @php($chargeLines = $b->chargeLines()->whereNull('voided_at')->orderBy('id')->get())
        @if ($chargeLines->isNotEmpty())
            <div class="rp-acc-card">
                <div class="rp-acc-label">Fines, tolls and other charges</div>
                <table class="rp-acc-table">
                    @foreach ($chargeLines as $l)
                        <tr><td>{{ $l->label }}@if ($l->isPassThrough())<br><span class="rp-acc-muted">Paid to the authority on your behalf</span>@endif</td><td class="r">AED {{ number_format((float) $l->amount, 2) }}</td></tr>
                        @if (in_array($l->type, ['salik', 'darb', 'parking'], true))
                            @foreach (\App\Models\TollTrip::where('booking_charge_id', $l->id)->orderBy('passed_at')->get() as $trip)
                                <tr><td class="rp-acc-muted" style="padding-inline-start:1rem">{{ $trip->passed_at->format('d M H:i') }}{{ $trip->gate ? ' · '.$trip->gate : '' }}</td><td class="r rp-acc-muted">{{ number_format((float) $trip->amount, 2) }}</td></tr>
                            @endforeach
                        @endif
                    @endforeach
                </table>
            </div>
        @endif
        @foreach ($b->depositSettlements()->latest('id')->get() as $s)
            <div class="rp-acc-card">
                <div class="rp-acc-label">Deposit settled · {{ $s->created_at->format('d M Y') }}</div>
                <table class="rp-acc-table">
                    <tr><td>Deposit held</td><td class="r">AED {{ number_format((float) $s->held, 2) }}</td></tr>
                    <tr><td>Used for what was still owed</td><td class="r">AED {{ number_format((float) $s->applied, 2) }}</td></tr>
                    <tr><td><strong>Refunded to you</strong></td><td class="r"><strong>AED {{ number_format((float) $s->refunded, 2) }}</strong></td></tr>
                    @if ((float) $s->to_collect > 0)<tr><td>Still to pay</td><td class="r">AED {{ number_format((float) $s->to_collect, 2) }}</td></tr>@endif
                </table>
            </div>
        @endforeach
        @if ($b->deposit_refundable_on && $b->deposit_refundable_on->isFuture() && $b->depositHeld() > 0)
            <div class="rp-acc-card"><div class="rp-acc-muted">Your deposit is refundable on {{ $b->deposit_refundable_on->format('d M Y') }}, after any traffic fines and tolls have arrived.</div></div>
        @endif

        <div class="rp-acc-card">
            <div class="rp-acc-label">Progress</div>
            {!! \App\Support\Sales\Journey::html(collect(\App\Support\Sales\Journey::forBooking($b))->reject(fn ($s) => in_array($s[0], ['Quotation sent'], true))->values()->all()) !!}
        </div>

        <div class="rp-acc-card">
            <div class="rp-acc-label">Your documents</div>
            <div class="rp-acc-links">
                <a class="rp-acc-link" href="{{ route('pdf.confirmation', $b) }}" target="_blank" rel="noopener">Booking confirmation (PDF)</a>
                @foreach (\App\Models\Invoice::where('booking_id', $b->id)->where('status', 'issued')->orderBy('id')->get() as $inv)
                    <a class="rp-acc-link" href="{{ route('pdf.invoice', $inv) }}" target="_blank" rel="noopener">{{ $inv->title() }} {{ $inv->number }} (PDF)</a>
                @endforeach
                @if ($b->quotation && $b->quotation->sent_at)
                    <a class="rp-acc-link" href="{{ route('pdf.quotation', $b->quotation) }}" target="_blank" rel="noopener">Quotation {{ $b->quotation->number }} (PDF)</a>
                @endif
                @if ($b->returned_at)
                    <a class="rp-acc-link" href="{{ route('pdf.statement', $b) }}" target="_blank" rel="noopener">{{ $b->isCar() ? 'Return statement' : 'End of service statement' }} (PDF)</a>
                @endif
                <a class="rp-acc-link" href="#" wire:click.prevent="messageUs">Message us about this booking</a>
                @if ($jobReport = \App\Support\Documents\JobReports::current($b))
                    <a class="rp-acc-link" href="{{ $jobReport->url() }}" target="_blank" rel="noopener">Job report {{ $jobReport->number }} ({{ strtoupper($jobReport->format) }})</a>
                @endif
            </div>
        </div>

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
