{{-- Home per role (6 Nov, S4 / C.4). --}}
@php $h = $this->home(); @endphp
<x-filament-widgets::widget>
    <div class="rp-home">
        @foreach ($h['groups'] as $g)
            <section class="rp-home-group">
                <h3>{{ $g['title'] }}</h3>
                <div class="rp-home-tiles">
                    @foreach ($g['tiles'] as $t)
                        @php $tag = $t['url'] ? 'a' : 'div'; @endphp
                        <{{ $tag }} @if ($t['url']) href="{{ $t['url'] }}" @endif class="rp-home-tile rp-tone-{{ $t['tone'] }}">
                            <span class="rp-home-value">{{ $t['value'] }}</span>
                            <span class="rp-home-label">{{ $t['label'] }}</span>
                            @if ($t['note'])<span class="rp-home-note">{{ $t['note'] }}</span>@endif
                        </{{ $tag }}>
                    @endforeach
                </div>
            </section>
        @endforeach

        @if ($h['agents'])
            <section class="rp-home-group">
                <h3>Agents this month</h3>
                <div class="rp-home-agents">
                    @foreach ($h['agents'] as $i => $a)
                        <div class="rp-home-agent">
                            <span class="rp-home-rank">{{ $i + 1 }}</span>
                            <span class="rp-home-name">{{ $a['user']->name }}</span>
                            <span class="rp-home-meta">{{ $a['leads'] }} leads · {{ $a['bookings'] }} bookings</span>
                            <span class="rp-home-money">AED {{ number_format($a['revenue'], 0) }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-filament-widgets::widget>
