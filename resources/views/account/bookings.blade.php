<x-filament-panels::page>
    <div class="rp-acc">
        @forelse ($this->bookings() as $b)
            <a href="{{ \App\Filament\Account\Pages\BookingDetail::getUrl(['booking' => $b->id]) }}" class="rp-acc-card rp-acc-row">
                <div>
                    <div class="rp-acc-big">{{ $b->bookable?->name ?? $b->service_type?->getLabel() }}</div>
                    <div class="rp-acc-muted">{{ $b->reference }} · {{ $b->start_at->format('d M Y, H:i') }} → {{ $b->end_at->format('d M, H:i') }}</div>
                </div>
                <div class="rp-acc-right">
                    <x-filament::badge :color="$b->status->getColor()">{{ $b->status->getLabel() }}</x-filament::badge>
                    @if ((float) $b->balance > 0)<div class="rp-acc-muted">Due AED {{ number_format((float) $b->balance, 2) }}</div>@endif
                </div>
            </a>
        @empty
            <div class="rp-acc-card rp-acc-muted">No bookings yet.</div>
        @endforelse
    </div>
</x-filament-panels::page>
