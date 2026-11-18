@php
    $b = $job->booking;
    $car = $b?->bookable;
    $late = $job->isOpen() && $job->scheduled_at->lt(now()->subMinutes(15));
    $isNext = $isNext ?? false;
    $timeline = $timeline ?? false;
    $nav = $job->isOpen() ? $job->navigateUrl() : null;
    $url = \App\Filament\Pages\DriverJob::getUrl(['job' => $job->id]);
@endphp
<div class="rp-dv-card {{ $late ? 'is-late' : '' }} {{ $isNext ? 'is-next' : '' }} {{ $timeline ? 'rp-dv-tl-item' : '' }}" wire:key="job-{{ $job->id }}">
    <a href="{{ $url }}" class="rp-dv-card-link">
        <div class="rp-dv-card-top">
            <div class="rp-dv-time">
                <span>{{ $job->scheduled_at->isToday() ? $job->scheduled_at->format('H:i') : $job->scheduled_at->translatedFormat('D d M · H:i') }}</span>
                @if ($isNext)<small class="rp-dv-now">{{ in_array($job->status, ['on_the_way', 'arrived', 'waiting'], true) ? __('driver.now') : __('driver.next') }}</small>@endif
                @if ($late)<small>{{ __('driver.late') }}</small>@endif
            </div>
            <span class="rp-dv-badge rp-dv-{{ $job->type }}">{{ __('driver.type_'.$job->typeKey()) }}</span>
            <span class="rp-dv-badge rp-dv-s-{{ $job->statusColor() }}">{{ __('driver.status_'.$job->status) }}</span>
        </div>
        <div class="rp-dv-car">{{ $car?->name ?? __('driver.car') }}@if ($car?->registration_number) <span class="rp-dv-plate">{{ $car->registration_number }}</span>@endif</div>
        <div class="rp-dv-meta">{{ $b?->reference }} · {{ $b?->customer?->name ?? __('driver.customer') }}</div>
        @if ($job->address)
            <div class="rp-dv-addr"><x-filament::icon icon="heroicon-m-map-pin" class="h-4 w-4" /> {{ $job->address }}</div>
        @endif
        @if ($job->late_minutes && $job->isOpen())
            <div class="rp-dv-meta rp-dv-late">{{ __('driver.late_reported', ['n' => $job->late_minutes]) }}</div>
        @endif
        @if ($job->status === 'declined')
            <div class="rp-dv-meta">{{ __('driver.declined_note', ['reason' => $job->decline_reason]) }}</div>
        @endif
    </a>
    @if ($job->isOpen() && ($nav || $job->status === 'assigned'))
        <div class="rp-dv-card-actions">
            @if ($job->status === 'assigned')
                <button type="button" class="rp-dv-pill rp-dv-pill-primary" x-data x-on:click="RPDriver.act({{ $job->id }}, 'acknowledged').then(() => $wire.$refresh())">{{ __('driver.accept') }}</button>
                @if (\App\Support\Drivers\DriverFlow::canDecline($job))
                    <a class="rp-dv-pill" href="{{ $url }}?decline=1">{{ __('driver.decline') }}</a>
                @endif
            @endif
            @if ($nav)
                <a class="rp-dv-pill" href="{{ $nav }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-m-map" class="h-4 w-4" /> {{ __('driver.navigate') }}</a>
            @endif
        </div>
    @endif
</div>
