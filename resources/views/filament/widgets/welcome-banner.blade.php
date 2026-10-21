<x-filament-widgets::widget>
    <section class="rp-console">

        <div class="rp-console-top">
            <div class="rp-console-hello">
                <p class="rp-console-date">{{ $date }}</p>
                <h2 class="rp-console-title">{{ $greeting }}{{ $name ? ', '.$name : '' }}</h2>
                <p class="rp-console-text">{{ $summary }}</p>
            </div>

            <div class="rp-console-actions">
                @foreach ($actions as $action)
                    <a href="{{ $action['url'] }}" @class(['rp-cbtn', 'is-primary' => $action['primary']])>
                        <x-filament::icon :icon="$action['icon']" class="rp-cbtn-icon" />
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="rp-gauges">
            @foreach ($gauges as $g)
                <a href="{{ $g['url'] }}" class="rp-gauge">
                    <span class="rp-gauge-value">
                        @if (($g['unitFirst'] ?? false) && ($g['unit'] ?? null))<small>{{ $g['unit'] }}</small>@endif{{ $g['value'] }}@if (! ($g['unitFirst'] ?? false) && ($g['unit'] ?? null))<small>{{ $g['unit'] }}</small>@endif
                    </span>
                    <span class="rp-gauge-label">
                        {{ $g['label'] }}
                        @if ($g['note'])<em>{{ $g['note'] }}</em>@endif
                    </span>
                </a>
            @endforeach
        </div>

        <div class="rp-console-foot">
            <div class="rp-alerts">
                @forelse ($alerts as $alert)
                    @if ($alert['url'])
                        <a href="{{ $alert['url'] }}" class="rp-alert"><b>{{ $alert['value'] }}</b> {{ $alert['label'] }}</a>
                    @else
                        <span class="rp-alert"><b>{{ $alert['value'] }}</b> {{ $alert['label'] }}</span>
                    @endif
                @empty
                    <span class="rp-alert is-clear">All clear</span>
                @endforelse
            </div>

            <div class="rp-fleet-add">
                <span>Add</span>
                @foreach ($fleetActions as $action)
                    <a href="{{ $action['url'] }}" class="rp-fleet-add-btn">
                        <x-filament::icon :icon="$action['icon']" class="rp-cbtn-icon" />
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    </section>
</x-filament-widgets::widget>
