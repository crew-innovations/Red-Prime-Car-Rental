<x-filament-widgets::widget>
    <div class="rp-banner">
        <img src="{{ asset('images/logo-mark.png') }}" alt="" class="rp-banner-logo">

        <div class="rp-banner-inner">
            <div class="rp-banner-date">{{ $date }}</div>
            <div class="rp-banner-title">{{ $greeting }}{{ $name ? ', '.$name : '' }}</div>
            <div class="rp-banner-text">{{ $summary }}</div>

            <div class="rp-chips">
                @foreach ($chips as $chip)
                    <span @class(['rp-chip', 'is-alert' => $chip['alert']])>
                        <b>{{ $chip['value'] }}</b> {{ $chip['label'] }}
                    </span>
                @endforeach
            </div>

            <div class="rp-actions">
                @foreach ($actions as $action)
                    <a href="{{ $action['url'] }}" @class(['rp-btn', 'is-primary' => $action['primary']])>
                        <x-filament::icon :icon="$action['icon']" />
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
