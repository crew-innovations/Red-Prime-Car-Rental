<x-filament-widgets::widget>
    <x-filament::section heading="Top earners" description="Profit per car, yacht and trip this year" icon="heroicon-o-trophy">
        <div class="rp-list">
            @forelse ($items as $i => $item)
                <a href="{{ $item['url'] }}" class="rp-row" style="display: block">
                    <div style="display: flex; align-items: center; gap: .75rem">
                        <div class="rp-rank">{{ $i + 1 }}</div>
                        <div class="rp-row-main">
                            <div class="rp-row-title">{{ $item['name'] }}</div>
                            <div class="rp-row-sub">{{ $item['kind'] }} · AED {{ number_format($item['received']) }} in · AED {{ number_format($item['expenses']) }} out</div>
                        </div>
                        <div class="rp-row-side">
                            <div @class(['rp-money', 'is-negative' => $item['net'] < 0])>AED {{ number_format($item['net']) }}</div>
                            <div class="rp-time">net</div>
                        </div>
                    </div>
                    <div class="rp-meter"><i style="width: {{ $item['pct'] }}%"></i></div>
                </a>
            @empty
                <div class="rp-empty">Link income and expenses to a car, yacht or trip to see which ones earn the most.</div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
