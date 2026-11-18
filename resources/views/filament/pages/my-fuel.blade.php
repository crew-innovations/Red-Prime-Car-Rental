<x-filament-panels::page>
    <div class="rp-driver" dir="{{ \App\Support\Drivers\DriverLang::dir() }}">
        <button type="button" class="rp-dv-fuel-add" wire:click="mountAction('addFuel')">
            <x-filament::icon icon="heroicon-o-plus-circle" class="h-6 w-6" />
            <span>{{ __('driver.fuel_add') }}</span>
        </button>

        <div class="rp-dv-section">
            <div class="rp-dv-label">{{ __('driver.fuel_mine') }}</div>
            @forelse ($this->mine() as $f)
                <div class="rp-dv-docrow" wire:key="fuel-{{ $f->id }}">
                    <div>
                        <div class="rp-dv-value">{{ $f->car?->name }}</div>
                        <div class="rp-dv-meta" dir="ltr">{{ $f->filled_at->translatedFormat('D d M, H:i') }} · {{ rtrim(rtrim(number_format((float) $f->litres, 2), '0'), '.') }} L @if ($f->km)· {{ number_format($f->km) }} km @endif</div>
                        @if ($f->status === 'rejected' && $f->reject_reason)<div class="rp-dv-meta rp-dv-warn">{{ $f->reject_reason }}</div>@endif
                    </div>
                    <span class="rp-dv-badge rp-dv-fuel-{{ $f->status }}">{{ match ($f->status) { 'confirmed' => __('driver.fuel_ok'), 'rejected' => __('driver.fuel_no'), default => __('driver.fuel_waiting') } }}</span>
                </div>
            @empty
                <div class="rp-dv-meta">{{ __('driver.fuel_none') }}</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
