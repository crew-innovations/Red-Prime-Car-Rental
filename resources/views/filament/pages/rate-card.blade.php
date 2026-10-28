<x-filament-panels::page>
    <x-filament::tabs label="Service">
        @foreach ($this->services() as $key => $label)
            <x-filament::tabs.item :active="$service === $key" wire:click="$set('service', '{{ $key }}')">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
