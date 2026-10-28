<x-filament-panels::page>
    <div class="rp-acc">
        @forelse ($this->quotations() as $q)
            <a href="{{ $q->publicUrl() }}" target="_blank" rel="noopener" class="rp-acc-card rp-acc-row">
                <div>
                    <div class="rp-acc-big">{{ $q->number ?? 'Quotation' }} · {{ $q->bookable?->name ?? ucfirst((string) $q->service_type) }}</div>
                    <div class="rp-acc-muted">
                        {{ $q->start_at?->format('d M Y') }}{{ $q->valid_until ? ' · valid until '.$q->valid_until->format('d M Y') : '' }}
                    </div>
                </div>
                <div class="rp-acc-right">
                    <x-filament::badge :color="$q->status->getColor()">{{ $q->status->getLabel() }}</x-filament::badge>
                    <div class="rp-acc-muted">AED {{ number_format((float) $q->total, 2) }}</div>
                    @if (! $q->acceptProblem())<div class="rp-acc-cta">Review &amp; accept →</div>@endif
                </div>
            </a>
        @empty
            <div class="rp-acc-card rp-acc-muted">No quotations yet.</div>
        @endforelse
    </div>
</x-filament-panels::page>
