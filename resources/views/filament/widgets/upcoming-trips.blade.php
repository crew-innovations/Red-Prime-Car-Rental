<x-filament-widgets::widget>
    <x-filament::section heading="Upcoming yacht trips" description="Next 7 days" icon="heroicon-o-sun">
        <x-slot name="headerEnd">
            <x-filament::link :href="$allUrl" size="sm">All trips</x-filament::link>
        </x-slot>

        <div class="rp-list">
            @forelse ($dates as $d)
                <a href="{{ $d['url'] }}" class="rp-row">
                    <div @class(['rp-date', 'is-today' => $d['today']])>
                        <div class="d">{{ $d['day'] }}</div>
                        <div class="m">{{ $d['month'] }}</div>
                    </div>
                    <div class="rp-row-main">
                        <div class="rp-row-title">{{ $d['name'] }}</div>
                        <div class="rp-row-sub">{{ $d['when'] }}{{ $d['time'] ? ' · '.$d['time'] : '' }}</div>
                    </div>
                    @if (! is_null($d['seats']))
                        <div class="rp-row-side"><span class="rp-pill c-info">{{ $d['seats'] }} seats</span></div>
                    @endif
                </a>
            @empty
                <div class="rp-empty">No trips scheduled in the next 7 days.</div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
