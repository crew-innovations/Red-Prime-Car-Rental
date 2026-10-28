@php
    $events = $this->events();
    [$from, $to] = $this->range();
    $opt = $this->options();
    $byDay = $events->groupBy(fn ($e) => $e['start']->toDateString());
    $driverOnly = \App\Support\Access::isDriverOnly();
    $clashes = $events->where('conflict', true)->count();
    $noDriver = $events->where('needs_driver', true)->count();
    $time = fn ($e) => $e['start']->format('H:i').($e['end'] && $e['end']->ne($e['start']) ? '–'.($e['end']->isSameDay($e['start']) ? $e['end']->format('H:i') : $e['end']->format('d M H:i')) : '');
    $badges = function ($e) {
        $out = '';
        if ($e['conflict']) $out .= '<span class="rp-cal-flag is-clash">Clash</span>';
        if ($e['needs_driver']) $out .= '<span class="rp-cal-flag is-nodriver">No driver</span>';
        if (! empty($e['statusLabel']) && in_array($e['status'], ['pending_payment', 'in_progress', 'completed', 'cancelled'], true)) $out .= '<span class="rp-cal-flag">'.e($e['statusLabel']).'</span>';
        return $out;
    };
@endphp
<x-filament-panels::page>
    <div class="rp-cal-bar">
        <div class="rp-cal-nav">
            <button type="button" wire:click="move(-1)" aria-label="Previous"><x-filament::icon icon="heroicon-m-chevron-left" class="rp-cal-i" /></button>
            <button type="button" wire:click="today">Today</button>
            <button type="button" wire:click="move(1)" aria-label="Next"><x-filament::icon icon="heroicon-m-chevron-right" class="rp-cal-i" /></button>
            <h2 class="rp-cal-title">{{ $this->heading() }}</h2>
        </div>
        <div class="rp-cal-views" role="tablist">
            @foreach (\App\Filament\Pages\Calendar::VIEWS as $key => $label)
                <button type="button" role="tab" wire:click="$set('mode', '{{ $key }}')" @class(['is-on' => $mode === $key]) aria-selected="{{ $mode === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div x-data="{ open: false }" class="rp-cal-filterwrap">
    <button type="button" class="rp-cal-filtertoggle" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()">
        <x-filament::icon icon="heroicon-m-funnel" class="rp-cal-i" /> Filters
    </button>
    <div class="rp-cal-filters" x-bind:class="{ 'is-closed': ! open }">
        @if ($opt['agents'])
            <label><span>Agent</span><select wire:model.live="agent"><option value="">Everyone</option>@foreach ($opt['agents'] as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></label>
        @endif
        @if ($opt['drivers'])
            <label><span>Driver / crew</span><select wire:model.live="driver"><option value="">Everyone</option>@foreach ($opt['drivers'] as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></label>
        @endif
        @unless ($driverOnly)
            <label><span>Service</span><select wire:model.live="service"><option value="">All</option>@foreach ($opt['services'] as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select></label>
            <label><span>Car / yacht</span><select wire:model.live="item"><option value="">All</option>@foreach ($opt['items'] as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select></label>
            <label><span>Booking status</span><select wire:model.live="status"><option value="">Active</option>@foreach ($opt['statuses'] as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select></label>
            <label><span>What</span><select wire:model.live="kind"><option value="">Everything</option>@foreach ($opt['kinds'] as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select></label>
            <label class="rp-cal-check"><input type="checkbox" wire:model.live="problems"> Only clashes and jobs without a driver</label>
            <button type="button" class="rp-cal-clear" wire:click="clearFilters">Clear</button>
        @endunless
    </div>
    </div>

    @if ($clashes || $noDriver)
        <div class="rp-cal-alert" role="status">
            @if ($clashes)<span><b>{{ $clashes }}</b> {{ $clashes === 1 ? 'job clashes' : 'jobs clash' }} with another job of the same person</span>@endif
            @if ($noDriver)<span><b>{{ $noDriver }}</b> {{ $noDriver === 1 ? 'job needs' : 'jobs need' }} a driver</span>@endif
        </div>
    @endif

    <div wire:loading.delay class="rp-cal-loading">Loading…</div>

    @if ($mode === 'month')
        <div class="rp-cal-month" wire:loading.class="is-busy">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dn)<div class="rp-cal-dow">{{ $dn }}</div>@endforeach
            @for ($d = $from->copy(); $d->lte($to); $d->addDay())
                @php $list = $byDay->get($d->toDateString(), collect()); @endphp
                <div @class(['rp-cal-cell', 'is-out' => ! $d->isSameMonth($this->anchor()), 'is-today' => $d->isToday()])>
                    <button type="button" class="rp-cal-daynum" wire:click="openDay('{{ $d->toDateString() }}')">{{ $d->day }}</button>
                    @foreach ($list->take(3) as $e)
                        <a href="{{ $e['url'] }}" class="rp-cal-chip tone-{{ $e['tone'] }} {{ $e['conflict'] || $e['needs_driver'] ? 'is-problem' : '' }}" title="{{ $e['title'] }} · {{ $e['sub'] }}">
                            <b>{{ $e['start']->format('H:i') }}</b> {{ $e['title'] }}
                        </a>
                    @endforeach
                    @if ($list->count() > 3)
                        <button type="button" class="rp-cal-more" wire:click="openDay('{{ $d->toDateString() }}')">+{{ $list->count() - 3 }} more</button>
                    @endif
                </div>
            @endfor
        </div>
    @elseif ($mode === 'week')
        <div class="rp-cal-week" wire:loading.class="is-busy">
            @for ($d = $from->copy(); $d->lte($to); $d->addDay())
                @php $list = $byDay->get($d->toDateString(), collect()); @endphp
                <section @class(['rp-cal-col', 'is-today' => $d->isToday()])>
                    <button type="button" class="rp-cal-colhead" wire:click="openDay('{{ $d->toDateString() }}')"><span>{{ $d->format('D') }}</span> {{ $d->format('j M') }}</button>
                    @forelse ($list as $e)
                        <a href="{{ $e['url'] }}" class="rp-cal-card tone-{{ $e['tone'] }} {{ $e['conflict'] || $e['needs_driver'] ? 'is-problem' : '' }}">
                            <span class="rp-cal-time">{{ $time($e) }}</span>
                            <strong>{{ $e['title'] }}</strong>
                            @if ($e['sub'])<span class="rp-cal-sub">{{ $e['sub'] }}</span>@endif
                            {!! $badges($e) !!}
                        </a>
                    @empty
                        <p class="rp-cal-empty">Free</p>
                    @endforelse
                </section>
            @endfor
        </div>
    @else
        <div class="rp-cal-list" wire:loading.class="is-busy">
            @forelse ($byDay as $day => $list)
                <section class="rp-cal-group">
                    <h3 @class(['is-today' => \Illuminate\Support\Carbon::parse($day)->isToday()])>{{ \Illuminate\Support\Carbon::parse($day)->format('l, j F') }}</h3>
                    @foreach ($list as $e)
                        <a href="{{ $e['url'] }}" class="rp-cal-row tone-{{ $e['tone'] }} {{ $e['conflict'] || $e['needs_driver'] ? 'is-problem' : '' }}">
                            <span class="rp-cal-time">{{ $time($e) }}</span>
                            <span class="rp-cal-what"><strong>{{ $e['title'] }}</strong>@if ($e['sub'])<span class="rp-cal-sub">{{ $e['sub'] }}</span>@endif</span>
                            <span class="rp-cal-flags">{!! $badges($e) !!}</span>
                        </a>
                    @endforeach
                </section>
            @empty
                <div class="rp-cal-none">
                    <x-filament::icon icon="heroicon-o-calendar" class="rp-cal-none-i" />
                    <p>Nothing scheduled {{ $mode === 'day' ? 'on this day' : 'in these two weeks' }}{{ $events->isEmpty() && ($agent || $driver || $service || $item || $status || $kind || $problems) ? ' with these filters' : '' }}.</p>
                </div>
            @endforelse
        </div>
    @endif

    <div class="rp-cal-legend">
        <span class="tone-out">Handover / delivery</span><span class="tone-back">Return / collection</span><span class="tone-charter">Charter</span><span class="tone-transfer">Transfer</span><span class="tone-departure">Trip departure</span><span class="tone-task">Follow-up / quote</span><span class="tone-late">Late</span>
    </div>
</x-filament-panels::page>
