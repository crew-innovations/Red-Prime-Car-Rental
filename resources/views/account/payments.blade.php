@php
    $due = $this->due();
    $payments = $this->payments();
@endphp
<x-filament-panels::page>
    <div class="rp-acc">
        <div class="rp-acc-card">
            <div class="rp-acc-label">To pay</div>
            @forelse ($due as $b)
                <div class="rp-acc-row rp-acc-line">
                    <div>{{ $b->reference }} · {{ $b->bookable?->name }}<br><span class="rp-acc-muted">{{ $b->start_at->format('d M Y') }}</span></div>
                    <div class="r"><strong>AED {{ number_format((float) $b->balance, 2) }}</strong></div>
                </div>
            @empty
                <div class="rp-acc-muted">Nothing to pay right now.</div>
            @endforelse
            @if ($due->isNotEmpty() && filled(\App\Models\Setting::read('bank_details')))
                <div class="rp-acc-label" style="margin-top:1rem">How to pay</div>
                <div class="rp-acc-muted" style="white-space:pre-line">{{ \App\Models\Setting::read('bank_details') }}</div>
            @endif
        </div>

        <div class="rp-acc-card">
            <div class="rp-acc-label">Payments received</div>
            <table class="rp-acc-table">
                @forelse ($payments as $p)
                    <tr>
                        <td>{{ $p->date?->format('d M Y') }} · {{ $p->booking?->reference }}<br>
                            <span class="rp-acc-muted">{{ $p->type === \App\Enums\TransactionType::Expense ? 'Deposit refund' : ($p->category === 'deposit' ? 'Security deposit' : 'Payment') }}{{ $p->method ? ' · '.$p->method->getLabel() : '' }}{{ $p->reference ? ' · ref '.$p->reference : '' }}</span></td>
                        <td class="r">{{ $p->type === \App\Enums\TransactionType::Expense ? '−' : '' }}AED {{ number_format((float) ($p->amount_paid ?: $p->amount), 2) }}</td>
                    </tr>
                @empty
                    <tr><td class="rp-acc-muted">No payments yet.</td></tr>
                @endforelse
            </table>
        </div>
    </div>
</x-filament-panels::page>
