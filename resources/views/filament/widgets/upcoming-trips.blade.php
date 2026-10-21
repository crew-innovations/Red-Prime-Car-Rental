<x-filament-widgets::widget>
    <x-filament::section heading="Departures this week" description="Yacht trips and packages in the next 7 days" icon="heroicon-o-sun">
        <x-slot name="headerEnd">
            <x-filament::link :href="$allUrl" size="sm">All trips</x-filament::link>
        </x-slot>

        @if ($dates->isEmpty())
            <div class="rp-empty">Nothing departs in the next 7 days. Add dates on a yacht trip or package to see them here.</div>
        @else
            <div class="rp-departures">
                @foreach ($dates as $d)
                    <a href="{{ $d['url'] }}" @class(['rp-departure', 'is-today' => $d['today']])>
                        <span class="rp-departure-when">{{ $d['when'] }} · {{ $d['kind'] }}</span>
                        <span class="rp-departure-day">{{ $d['day'] }} <small>{{ $d['month'] }}</small></span>
                        <span class="rp-departure-name">{{ $d['name'] }}</span>
                        <span class="rp-departure-meta">
                            @if ($d['time'])<span>{{ $d['time'] }}</span>@endif
                            @if (! is_null($d['seats']))<span>{{ $d['seats'] }} seats left</span>@endif
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
