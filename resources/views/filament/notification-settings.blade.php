<x-filament-panels::page>
    @php($channels = $this->channels())
    <form wire:submit="save" class="rp-stack">
        <x-filament::section icon="heroicon-o-device-phone-mobile" heading="This device">
            <div id="rp-push-status" class="rp-muted">Checking…</div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem">
                <x-filament::button type="button" id="rp-push-enable" color="primary" style="display:none">Allow notifications on this device</x-filament::button>
                <x-filament::button type="button" color="gray" wire:click="testPush">Send me a test notification</x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-adjustments-horizontal" heading="What reaches me" description="Switch off what you do not need. Urgent job changes always come through.">
            <div class="rp-np-table">
                <div class="rp-np-row rp-np-head">
                    <div></div>
                    @foreach ($channels as $label)<div>{{ $label }}</div>@endforeach
                </div>
                @foreach ($this->events() as $event => $label)
                    <div class="rp-np-row" wire:key="ev-{{ $event }}">
                        <div>{{ $label }} @if ($this->urgent($event))<span class="rp-np-urgent">urgent</span>@endif</div>
                        @foreach ($channels as $ch => $chLabel)
                            <label class="rp-np-cell"><span class="rp-np-mobile">{{ $chLabel }}</span>
                                <input type="checkbox" class="fi-checkbox-input rounded border-none bg-white shadow-sm ring-1 ring-gray-950/10 checked:ring-0 dark:bg-white/5 dark:ring-white/20" style="color:#d7141a" wire:model="prefs.{{ $event }}.{{ $ch }}">
                            </label>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-moon" heading="Quiet hours" description="No phone notifications in this time (they wait in the bell). Urgent job changes still come through.">
            <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:end">
                <label>From<br><x-filament::input.wrapper><x-filament::input type="time" wire:model="quietFrom" /></x-filament::input.wrapper></label>
                <label>Until<br><x-filament::input.wrapper><x-filament::input type="time" wire:model="quietTo" /></x-filament::input.wrapper></label>
                <x-filament::link tag="button" type="button" color="gray" x-on:click="$wire.set('quietFrom', null); $wire.set('quietTo', null)">No quiet hours</x-filament::link>
            </div>
            @error('quietFrom')<p style="color:#d7141a">{{ $message }}</p>@enderror
            @error('quietTo')<p style="color:#d7141a">{{ $message }}</p>@enderror
        </x-filament::section>

        <div><x-filament::button type="submit" size="lg">Save</x-filament::button></div>

        @php($devices = $this->devices())
        @if ($devices->isNotEmpty())
            <x-filament::section icon="heroicon-o-computer-desktop" heading="My devices" collapsible>
                @foreach ($devices as $d)
                    <div class="rp-np-device" wire:key="dev-{{ $d->id }}">
                        <div>
                            <strong>{{ $d->label ?: 'Device' }}</strong>
                            <div class="rp-muted" style="font-size:.85rem">{{ $d->installed ? 'App installed' : 'In the browser' }} · notifications {{ $d->push_endpoint ? 'on' : ($d->permission === 'denied' ? 'blocked' : 'off') }} · last seen {{ $d->last_seen_at?->diffForHumans() ?? 'never' }}</div>
                        </div>
                        <x-filament::button size="xs" color="gray" wire:click="removeDevice({{ $d->id }})" wire:confirm="Remove this device? It gets no more notifications.">Remove</x-filament::button>
                    </div>
                @endforeach
            </x-filament::section>
        @endif
    </form>

    <script>
        (function () {
            var el = document.getElementById('rp-push-status'), btn = document.getElementById('rp-push-enable');
            if (!el) return;
            var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
            var ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
            if (!('Notification' in window) || !('serviceWorker' in navigator)) {
                el.textContent = ios && !standalone ? 'On iPhone: tap Share › "Add to Home Screen", open the app from the icon, then allow notifications here.' : 'This browser cannot show notifications.';
                return;
            }
            var p = Notification.permission;
            if (p === 'granted') { el.textContent = 'Notifications are on for this device.'; return; }
            if (p === 'denied') { el.textContent = 'Notifications are blocked for this site. Turn them on in the browser or phone settings, then reload.'; return; }
            el.textContent = 'Notifications are not on for this device yet.';
            btn.style.display = '';
            btn.addEventListener('click', function () {
                if (window.RPPush) { window.RPPush.enable().then(function (r) { el.textContent = r === 'granted' ? 'Notifications are on for this device.' : 'Notifications were not allowed.'; if (r === 'granted') btn.style.display = 'none'; }); }
            });
        })();
    </script>
</x-filament-panels::page>
