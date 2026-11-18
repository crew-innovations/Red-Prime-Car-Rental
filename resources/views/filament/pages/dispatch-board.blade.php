@php
    $cols = $this->columns();
    $need = $this->unassigned();
    $date = $this->date();
    $bookingUrl = fn ($b) => $b ? \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $b]) : null;
@endphp
<x-filament-panels::page>
    <div class="rp-dp" x-data="{
            dragging: null, over: null,
            drop(driver) { const key = this.dragging; this.dragging = null; this.over = null; if (key) $wire.move(key, driver); },
        }" wire:poll.60s>
        <div class="rp-dp-bar">
            <x-filament::button color="gray" size="sm" icon="heroicon-m-chevron-left" wire:click="shift(-1)">Previous day</x-filament::button>
            <div class="rp-dp-date">{{ $date->isToday() ? 'Today · ' : ($date->isTomorrow() ? 'Tomorrow · ' : '') }}{{ $date->format('l d M Y') }}</div>
            <x-filament::button color="gray" size="sm" wire:click="goToday" :disabled="$date->isToday()">Today</x-filament::button>
            <x-filament::button color="gray" size="sm" icon="heroicon-m-chevron-right" icon-position="after" wire:click="shift(1)">Next day</x-filament::button>
            @if (\App\Filament\Pages\LiveDrivers::canAccess())
                <x-filament::button color="gray" size="sm" icon="heroicon-m-map" tag="a" :href="\App\Filament\Pages\LiveDrivers::getUrl()">Live drivers map</x-filament::button>
            @endif
        </div>

        <div class="rp-dp-board" wire:loading.class="rp-dv-loading" wire:target="move,shift,goToday">
            {{-- Needs a driver --}}
            <section class="rp-dp-col rp-dp-need">
                <header><strong>Needs a driver</strong><span class="rp-dp-count">{{ $need->count() }}</span></header>
                @forelse ($need as $c)
                    <article class="rp-dp-card {{ $c['movable'] ? '' : 'is-locked' }}" @if ($c['movable']) draggable="true" x-on:dragstart="dragging = @js($c['key']); $event.dataTransfer.effectAllowed = 'move'" x-on:dragend="dragging = null" @endif wire:key="n-{{ $c['key'] }}">
                        <div class="rp-dp-top"><b>{{ $c['at']->format('H:i') }}</b><span class="rp-dv-badge rp-dv-{{ $c['type'] }}">{{ $c['typeLabel'] }}</span></div>
                        <a href="{{ $bookingUrl($c['booking']) }}" class="rp-dp-ref">{{ $c['booking']?->reference }} · {{ $c['booking']?->bookable?->name ?? $c['booking']?->vehicleCategory?->name }}</a>
                        @if ($c['address'])<div class="rp-dp-addr">{{ $c['address'] }}</div>@endif
                        <div class="rp-dp-note">{{ $c['note'] }}</div>
                        @if ($c['movable'])
                            <select class="rp-dp-pick" x-on:change="if ($event.target.value) { $wire.move(@js($c['key']), parseInt($event.target.value)); $event.target.value = '' }" aria-label="Give to">
                                <option value="">Give to…</option>
                                @foreach ($cols as $col)<option value="{{ $col['user']->id }}">{{ $col['user']->name }}</option>@endforeach
                            </select>
                        @endif
                    </article>
                @empty
                    <p class="rp-muted rp-dp-empty">Every job has a driver.</p>
                @endforelse
            </section>

            @foreach ($cols as $col)
                @php($u = $col['user'])
                <section class="rp-dp-col" x-on:dragover.prevent="over = {{ $u->id }}" x-on:dragleave="over = null" x-on:drop.prevent="drop({{ $u->id }})"
                    x-bind:class="{ 'is-over': over === {{ $u->id }} }" wire:key="col-{{ $u->id }}">
                    <header>
                        <div><strong>{{ $u->name }}</strong>@if ($col['live'])<span class="rp-dp-live" title="Sharing location now"></span>@endif
                            <div class="rp-muted" style="font-size:.75rem">{{ $col['does'] }}@if ($u->phone) · {{ $u->phone }}@endif</div></div>
                        <span class="rp-dp-count">{{ $col['jobs']->count() }}</span>
                    </header>
                    @foreach ($col['off'] as $o)
                        <div class="rp-dp-off">Not available · {{ $o->reason ?: 'off' }} · {{ $o->starts_at->isSameDay($date) ? $o->starts_at->format('H:i') : '00:00' }}–{{ $o->ends_at->isSameDay($date) ? $o->ends_at->format('H:i') : '24:00' }}</div>
                    @endforeach
                    @forelse ($col['jobs'] as $j)
                        @php($movable = $j->isOpen() && $j->type !== 'charter')
                        <article class="rp-dp-card {{ $j->status === 'done' ? 'is-done' : '' }} {{ $movable ? '' : 'is-locked' }}" wire:key="j-{{ $j->id }}"
                            @if ($movable) draggable="true" x-on:dragstart="dragging = 'job:{{ $j->id }}'; $event.dataTransfer.effectAllowed = 'move'" x-on:dragend="dragging = null" @endif>
                            <div class="rp-dp-top"><b>{{ $j->scheduled_at->format('H:i') }}</b><span class="rp-dv-badge rp-dv-{{ $j->type }}">{{ $j->typeLabel() }}</span>
                                <span class="rp-dv-badge rp-dv-s-{{ $j->statusColor() }}">{{ $j->statusLabel() }}</span></div>
                            <a href="{{ $bookingUrl($j->booking) }}" class="rp-dp-ref">{{ $j->booking?->reference }} · {{ $j->booking?->bookable?->name ?? $j->booking?->vehicleCategory?->name }}</a>
                            @if ($j->address)<div class="rp-dp-addr">{{ $j->address }}</div>@endif
                            @if ($j->late_minutes && $j->isOpen())<div class="rp-dp-late">Running about {{ $j->late_minutes }} min late</div>@endif
                            @if ($movable)
                                <select class="rp-dp-pick" x-on:change="if ($event.target.value) { $wire.move('job:{{ $j->id }}', parseInt($event.target.value)); $event.target.value = '' }" aria-label="Move to">
                                    <option value="">Move to…</option>
                                    @foreach ($cols as $other)@if ($other['user']->id !== $u->id)<option value="{{ $other['user']->id }}">{{ $other['user']->name }}</option>@endif @endforeach
                                </select>
                            @endif
                        </article>
                    @empty
                        <p class="rp-muted rp-dp-empty">{{ $col['off']->isNotEmpty() ? 'Not available' : 'Free' }}</p>
                    @endforelse
                </section>
            @endforeach
        </div>

        @php($log = $this->activity())
        @if ($log->isNotEmpty())
            <x-filament::section heading="Driver activity" icon="heroicon-o-clock" collapsible>
                @foreach ($log as $a)
                    <div class="rp-dp-act"><span class="rp-muted">{{ $a->created_at->format('d M H:i') }}</span> {{ $a->note }}</div>
                @endforeach
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
