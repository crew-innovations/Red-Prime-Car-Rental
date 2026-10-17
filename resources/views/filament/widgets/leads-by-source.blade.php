<x-filament-widgets::widget>
    <x-filament::section heading="Leads by source" :description="$total ? $total.' enquiries in this period' : 'Where your enquiries come from'">
        <x-slot name="headerEnd">
            <x-filament::input.wrapper class="w-36">
                <x-filament::input.select wire:model.live="filter">
                    @foreach ($filters as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </x-slot>

        @if ($rows->isEmpty())
            <div class="rp-empty">No leads in this period yet.</div>
        @else
            <ol class="rp-ranks" wire:loading.class="opacity-50">
                @foreach ($rows as $row)
                    <li class="rp-rankrow" title="{{ $row['source'] }}: {{ $row['total'] }} lead(s), {{ $row['share'] }}%">
                        <span class="rp-rankrow-name">{{ $row['source'] }}</span>
                        <span class="rp-rankrow-num">{{ $row['total'] }} <small>{{ $row['share'] }}%</small></span>
                        <span class="rp-rankrow-track"><i style="width: {{ $row['width'] }}%"></i></span>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
