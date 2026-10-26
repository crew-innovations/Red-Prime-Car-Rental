@php
    $s = $this->summary();
    $jobs = $this->jobs();
    $warnings = $this->paperWarnings();
@endphp

<x-filament-panels::page>
    <div class="rp-driver" wire:poll.60s>
        @if ($warnings)
            <div class="rp-dv-alert">
                <x-filament::icon icon="heroicon-o-identification" class="h-5 w-5" />
                <div>
                    <strong>Your documents</strong>
                    @foreach ($warnings as $w)
                        <div>{{ $w }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="rp-dv-stats">
            <button type="button" wire:click="setTab('today')" class="rp-dv-stat {{ $tab === 'today' ? 'is-active' : '' }}">
                <span class="rp-dv-num">{{ $s['today'] }}</span><span>Today</span>
            </button>
            <button type="button" wire:click="setTab('today')" class="rp-dv-stat">
                <span class="rp-dv-num">{{ $s['moving'] }}</span><span>In progress</span>
            </button>
            <button type="button" wire:click="setTab('upcoming')" class="rp-dv-stat {{ $tab === 'upcoming' ? 'is-active' : '' }}">
                <span class="rp-dv-num">{{ $s['upcoming'] }}</span><span>Upcoming</span>
            </button>
            <button type="button" wire:click="setTab('done')" class="rp-dv-stat {{ $tab === 'done' ? 'is-active' : '' }}">
                <span class="rp-dv-num">{{ $s['done'] }}</span><span>Done today</span>
            </button>
        </div>
        <div class="rp-dv-sub">
            {{ $s['handovers'] }} handover{{ $s['handovers'] === 1 ? '' : 's' }} and {{ $s['returns'] }} collection{{ $s['returns'] === 1 ? '' : 's' }} still to do
            @if ($s['late'])
                · <span class="rp-dv-late">{{ $s['late'] }} past the time</span>
            @endif
        </div>

        <div class="rp-dv-tabs" role="tablist">
            @foreach (['today' => 'Today', 'upcoming' => 'Upcoming', 'done' => 'Done'] as $key => $label)
                <button type="button" role="tab" wire:click="setTab('{{ $key }}')" class="{{ $tab === $key ? 'is-active' : '' }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>

        <div class="rp-dv-list" wire:loading.class="rp-dv-loading">
            @forelse ($jobs as $job)
                @include('filament.pages.driver.job-card', ['job' => $job])
            @empty
                <div class="rp-dv-empty">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-8 w-8" />
                    <div>{{ match ($tab) { 'upcoming' => 'No upcoming jobs.', 'done' => 'Nothing done in the last 30 days.', default => 'No jobs for today.' } }}</div>
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
