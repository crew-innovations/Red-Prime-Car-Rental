<x-filament-widgets::widget>
    <x-filament::section heading="Fleet status" description="Live status of every car and yacht" icon="heroicon-o-chart-bar">
        <div class="rp-fleet">
            @foreach ($groups as $group)
                <div>
                    <div class="rp-fleet-head">
                        <a href="{{ $group['url'] }}"><b>{{ $group['label'] }}</b></a>
                        <span>{{ $group['total'] }} total</span>
                    </div>
                    <div class="rp-bar">
                        @foreach ($group['segments'] as $s)
                            @if ($s['count'])
                                <i style="width: {{ $s['pct'] }}%; background: rgb({{ $s['color'] }})" title="{{ $s['label'] }}: {{ $s['count'] }}"></i>
                            @endif
                        @endforeach
                    </div>
                    <div class="rp-legend">
                        @foreach ($group['segments'] as $s)
                            <span><i style="background: rgb({{ $s['color'] }})"></i>{{ $s['label'] }} <b>{{ $s['count'] }}</b></span>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="rp-attn">
                <div class="rp-attn-title">Needs attention</div>
                @forelse ($attention as $item)
                    <a href="{{ $item['url'] }}" class="rp-row">
                        <span class="rp-dot"></span>
                        <div class="rp-row-main">
                            <div class="rp-row-title">{{ $item['name'] }}</div>
                            <div class="rp-row-sub">{{ $item['alerts'] }}</div>
                        </div>
                    </a>
                @empty
                    <div class="rp-empty">All registrations, insurance and services are up to date.</div>
                @endforelse
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
