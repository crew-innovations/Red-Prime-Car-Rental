<x-filament-widgets::widget>
    <x-filament::section heading="Latest enquiries" description="Newest leads from every channel" icon="heroicon-o-inbox-arrow-down">
        <x-slot name="headerEnd">
            <x-filament::link :href="$allUrl" size="sm">All leads</x-filament::link>
        </x-slot>

        <div class="rp-list">
            @forelse ($leads as $lead)
                <a href="{{ $lead['url'] }}" class="rp-row">
                    <div class="rp-avatar">{{ strtoupper($lead['initials']) }}</div>
                    <div class="rp-row-main">
                        <div class="rp-row-title">{{ $lead['name'] }}</div>
                        <div class="rp-row-sub">{{ $lead['interest'] }}{{ $lead['source'] ? ' · '.$lead['source'] : '' }}</div>
                    </div>
                    <div class="rp-row-side">
                        <span class="rp-pill c-{{ $lead['color'] }}">{{ $lead['status'] }}</span>
                        <div class="rp-time">{{ $lead['time'] }}</div>
                    </div>
                </a>
            @empty
                <div class="rp-empty">No leads yet. New enquiries will appear here.</div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
