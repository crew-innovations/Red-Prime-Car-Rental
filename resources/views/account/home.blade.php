@php($d = $this->data())
<x-filament-panels::page>
    <div class="rp-acc">
        <div class="rp-acc-hello">
            <h2>Hi {{ strtok($d['customer']->name, ' ') }},</h2>
            <p>Your bookings, quotations, documents and payments with {{ $d['company'] }}.</p>
        </div>

        <div class="rp-acc-grid">
            <a href="{{ \App\Filament\Account\Pages\Bookings::getUrl() }}" class="rp-acc-card">
                <div class="rp-acc-label">Next booking</div>
                @if ($d['next'])
                    <div class="rp-acc-big">{{ $d['next']->bookable?->name ?? $d['next']->service_type?->getLabel() }}</div>
                    <div class="rp-acc-muted">{{ $d['next']->start_at->format('D d M, H:i') }} · {{ $d['next']->status->getLabel() }}</div>
                @else
                    <div class="rp-acc-muted">No upcoming booking.</div>
                @endif
            </a>
            @if (\App\Filament\Account\Pages\Payments::canAccess())
                <a href="{{ \App\Filament\Account\Pages\Payments::getUrl() }}" class="rp-acc-card">
                    <div class="rp-acc-label">Balance due</div>
                    <div class="rp-acc-big">AED {{ number_format($d['balance'], 2) }}</div>
                    <div class="rp-acc-muted">{{ $d['balance'] > 0 ? 'See what is due' : 'Nothing to pay right now' }}</div>
                </a>
            @endif
            @if (\App\Filament\Account\Pages\Quotations::canAccess())
                <a href="{{ \App\Filament\Account\Pages\Quotations::getUrl() }}" class="rp-acc-card">
                    <div class="rp-acc-label">Quotations</div>
                    <div class="rp-acc-big">{{ $d['quotes'] }}</div>
                    <div class="rp-acc-muted">waiting for your answer</div>
                </a>
            @endif
            <a href="{{ \App\Filament\Account\Pages\Documents::getUrl() }}" class="rp-acc-card">
                <div class="rp-acc-label">Documents</div>
                <div class="rp-acc-big">{{ $d['docsToFix'] ?: 'OK' }}</div>
                <div class="rp-acc-muted">{{ $d['docsToFix'] ? 'need a new upload' : 'Nothing needed from you' }}</div>
            </a>
        </div>

        @if ($d['phone'])
            <p class="rp-acc-muted">Questions? Call or WhatsApp us on {{ $d['phone'] }}.</p>
        @endif
    </div>
</x-filament-panels::page>
