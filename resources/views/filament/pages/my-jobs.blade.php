@php
    $s = $this->summary();
    $jobs = $this->jobs();
    $warnings = $this->paperWarnings();
    $next = $tab === 'today' ? $this->nextJobId($jobs) : null;
@endphp

<x-filament-panels::page>
    <div class="rp-driver" dir="{{ \App\Support\Drivers\DriverLang::dir() }}" wire:poll.60s>
        @if ($warnings)
            <div class="rp-dv-alert">
                <x-filament::icon icon="heroicon-o-identification" class="h-5 w-5" />
                <div>
                    <strong>{{ __('driver.your_documents') }}</strong>
                    @foreach ($warnings as $w)
                        <div>{{ $w }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="rp-dv-stats">
            <button type="button" wire:click="setTab('today')" class="rp-dv-stat {{ $tab === 'today' ? 'is-active' : '' }}">
                <span class="rp-dv-num">{{ $s['today'] }}</span><span>{{ __('driver.today') }}</span>
            </button>
            <button type="button" wire:click="setTab('today')" class="rp-dv-stat">
                <span class="rp-dv-num">{{ $s['moving'] }}</span><span>{{ __('driver.in_progress') }}</span>
            </button>
            <button type="button" wire:click="setTab('upcoming')" class="rp-dv-stat {{ $tab === 'upcoming' ? 'is-active' : '' }}">
                <span class="rp-dv-num">{{ $s['upcoming'] }}</span><span>{{ __('driver.upcoming') }}</span>
            </button>
            <button type="button" wire:click="setTab('done')" class="rp-dv-stat {{ $tab === 'done' ? 'is-active' : '' }}">
                <span class="rp-dv-num">{{ $s['done'] }}</span><span>{{ __('driver.done_today') }}</span>
            </button>
        </div>
        <div class="rp-dv-sub">
            {{ __('driver.still_to_do', ['h' => $s['handovers'], 'c' => $s['returns']]) }}
            @if ($s['late'])
                · <span class="rp-dv-late">{{ __('driver.past_time', ['n' => $s['late']]) }}</span>
            @endif
        </div>

        <div class="rp-dv-tabs" role="tablist">
            @foreach (['today' => __('driver.today'), 'upcoming' => __('driver.upcoming'), 'done' => __('driver.history')] as $key => $label)
                <button type="button" role="tab" wire:click="setTab('{{ $key }}')" class="{{ $tab === $key ? 'is-active' : '' }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>

        <div class="rp-dv-list {{ $tab === 'today' ? 'rp-dv-timeline' : '' }}" wire:loading.class="rp-dv-loading">
            @forelse ($jobs as $job)
                @include('filament.pages.driver.job-card', ['job' => $job, 'isNext' => $next === $job->id, 'timeline' => $tab === 'today'])
            @empty
                <div class="rp-dv-empty">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-8 w-8" />
                    <div>{{ match ($tab) { 'upcoming' => __('driver.no_upcoming'), 'done' => __('driver.no_done'), default => __('driver.no_jobs_today') } }}</div>
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
