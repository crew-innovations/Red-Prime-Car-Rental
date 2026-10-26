@php
    $b = $job->booking;
    $car = $b?->bookable;
    $late = $job->isOpen() && $job->scheduled_at->lt(now()->subMinutes(15));
@endphp
<a href="{{ \App\Filament\Pages\DriverJob::getUrl(['job' => $job->id]) }}" class="rp-dv-card {{ $late ? 'is-late' : '' }}">
    <div class="rp-dv-card-top">
        <div class="rp-dv-time">
            <span>{{ $job->scheduled_at->isToday() ? $job->scheduled_at->format('H:i') : $job->scheduled_at->format('D d M · H:i') }}</span>
            @if ($late)<small>late</small>@endif
        </div>
        <span class="rp-dv-badge rp-dv-{{ $job->type }}">{{ $job->typeLabel() }}</span>
        <span class="rp-dv-badge rp-dv-s-{{ $job->statusColor() }}">{{ $job->statusLabel() }}</span>
    </div>
    <div class="rp-dv-car">{{ $car?->name ?? 'Car' }}@if ($car?->registration_number) <span class="rp-dv-plate">{{ $car->registration_number }}</span>@endif</div>
    <div class="rp-dv-meta">{{ $b?->reference }} · {{ $b?->customer?->name ?? 'Customer' }}</div>
    @if ($job->address)
        <div class="rp-dv-addr"><x-filament::icon icon="heroicon-m-map-pin" class="h-4 w-4" /> {{ $job->address }}</div>
    @endif
</a>
