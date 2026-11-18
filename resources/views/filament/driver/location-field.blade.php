{{-- Where it happened (4 Nov, 15): typed, or the phone's location with one tap. Only sent with the report. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($base = preg_replace('/\.location$/', '', $getStatePath()))
    <div x-data="{
            text: $wire.$entangle('{{ $getStatePath() }}'),
            lat: $wire.$entangle('{{ $base }}.lat'), lng: $wire.$entangle('{{ $base }}.lng'),
            busy: false, msg: '',
            locate() {
                if (!navigator.geolocation) { this.msg = @js(__('driver.no_gps')); return; }
                this.busy = true; this.msg = '';
                navigator.geolocation.getCurrentPosition(p => {
                    this.lat = p.coords.latitude.toFixed(7); this.lng = p.coords.longitude.toFixed(7);
                    if (!this.text) this.text = this.lat + ', ' + this.lng;
                    this.busy = false; this.msg = @js(__('driver.location_added'));
                }, () => { this.busy = false; this.msg = @js(__('driver.no_gps')); }, { enableHighAccuracy: true, timeout: 15000 });
            }
        }">
        <x-filament::input.wrapper>
            <input type="text" x-model="text" maxlength="255" class="fi-input block w-full border-none bg-transparent py-1.5 text-base text-gray-950 outline-none dark:text-white sm:text-sm px-3" placeholder="{{ __('driver.where_placeholder') }}">
        </x-filament::input.wrapper>
        <div style="display:flex;align-items:center;gap:.6rem;margin-top:.4rem">
            <button type="button" class="rp-dv-pill" style="flex:none;padding:0 .9rem" x-on:click="locate()" x-bind:disabled="busy">
                <x-filament::icon icon="heroicon-m-map-pin" class="h-4 w-4" /> <span x-text="busy ? '…' : @js(__('driver.use_location'))"></span>
            </button>
            <span class="rp-dv-meta" x-text="msg"></span>
        </div>
    </div>
</x-dynamic-component>
