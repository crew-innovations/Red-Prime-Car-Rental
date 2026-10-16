<x-filament-widgets::widget>
    <x-filament::section heading="Today's handovers & returns" icon="heroicon-o-key">
        <x-slot name="headerEnd">
            <x-filament::link :href="$allUrl" size="sm">All bookings</x-filament::link>
        </x-slot>

        <div class="rp-attn-title">Going out</div>
        <div class="rp-list">
            @forelse ($out as $row)
                <a href="{{ $row['url'] }}" class="rp-row">
                    <div class="rp-date"><div class="d" style="font-size:.85rem">{{ $row['time'] }}</div><div class="m">out</div></div>
                    <div class="rp-row-main">
                        <div class="rp-row-title">{{ $row['title'] }}</div>
                        <div class="rp-row-sub">{{ $row['sub'] }}</div>
                    </div>
                    <div class="rp-row-side">
                        @if ($row['notReady'])
                            <span class="rp-pill c-danger">Not ready</span>
                        @endif
                        @if ($row['balance'] > 0)
                            <span class="rp-pill c-warning">AED {{ number_format($row['balance']) }} due</span>
                        @endif
                    </div>
                </a>
            @empty
                <div class="rp-empty">Nothing going out today.</div>
            @endforelse
        </div>

        <div class="rp-attn-title" style="margin-top:1rem">Coming back</div>
        <div class="rp-list">
            @forelse ($back as $row)
                <a href="{{ $row['url'] }}" class="rp-row">
                    <div @class(['rp-date', 'is-today' => $row['late']])><div class="d" style="font-size:.85rem">{{ $row['lateDay'] ?? $row['time'] }}</div><div class="m">{{ $row['late'] ? 'late' : 'back' }}</div></div>
                    <div class="rp-row-main">
                        <div class="rp-row-title">{{ $row['title'] }}</div>
                        <div class="rp-row-sub">{{ $row['sub'] }}</div>
                    </div>
                    @if ($row['balance'] > 0)
                        <div class="rp-row-side"><span class="rp-pill c-danger">AED {{ number_format($row['balance']) }} due</span></div>
                    @endif
                </a>
            @empty
                <div class="rp-empty">Nothing due back today.</div>
            @endforelse
        </div>

        @if ($attention->isNotEmpty())
            <div class="rp-attn-title" style="margin-top:1rem">Waiting for cleaning / inspection</div>
            <div class="rp-list">
                @foreach ($attention as $row)
                    <a href="{{ $row['url'] }}" class="rp-row">
                        <div class="rp-row-main">
                            <div class="rp-row-title">{{ $row['title'] }}</div>
                            @if ($row['sub'])<div class="rp-row-sub">{{ $row['sub'] }}</div>@endif
                        </div>
                        <div class="rp-row-side"><span class="rp-pill c-warning">{{ $row['state'] }}</span></div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
