@php($points = $this->points())
<x-filament-panels::page>
    @if (! $this->enabled())
        <x-filament::section icon="heroicon-o-eye-slash" heading="Driver location is switched off">
            <p class="rp-muted">Switch on "Share driver location during jobs" in Business settings › Drivers. Tell the drivers in writing first. Locations are only sent while a job is active (on the way, arrived, during a chauffeur ride or charter) and are kept {{ (int) \App\Models\Setting::read('location_retention_days', 30) }} days.</p>
        </x-filament::section>
    @else
        <div wire:poll.30s class="rp-stack">
            @if ($key = $this->mapKey())
                <div id="rp-live-map" class="rp-live-map" wire:ignore
                     x-data x-init="window.rpLiveMap && rpLiveMap.init(@js($key))"></div>
                <script>
                    window.rpLiveMap = window.rpLiveMap || {
                        map: null, marks: [], points: [],
                        init(key) {
                            if (window.google && google.maps) return this.draw();
                            if (document.getElementById('rp-gmaps')) return;
                            window.rpLiveMapReady = () => this.draw();
                            const s = document.createElement('script'); s.id = 'rp-gmaps'; s.async = true;
                            s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&callback=rpLiveMapReady';
                            s.onerror = () => { document.getElementById('rp-live-map').innerHTML = '<p class="rp-muted" style="padding:1rem">The map could not load (check the browser key in Integrations). The list below still works.</p>'; };
                            document.head.appendChild(s);
                        },
                        draw() {
                            const el = document.getElementById('rp-live-map'); if (!el || !window.google) return;
                            this.map = this.map || new google.maps.Map(el, { center: { lat: 25.2048, lng: 55.2708 }, zoom: 11, mapTypeControl: false, streetViewControl: false });
                            this.marks.forEach(m => m.setMap(null)); this.marks = [];
                            const b = new google.maps.LatLngBounds();
                            (this.points || []).forEach(p => {
                                const m = new google.maps.Marker({ position: { lat: p.lat, lng: p.lng }, map: this.map, title: p.name, label: { text: (p.name || '?').slice(0, 1), color: '#fff' }, opacity: p.old ? .55 : 1 });
                                const info = new google.maps.InfoWindow({ content: '<b>' + (p.name || '') + '</b><br>' + (p.job || '') + '<br>' + (p.car || '') + '<br><small>' + p.at + '</small>' });
                                m.addListener('click', () => info.open({ anchor: m, map: this.map }));
                                this.marks.push(m); b.extend(m.getPosition());
                            });
                            if (this.marks.length === 1) { this.map.setCenter(b.getCenter()); this.map.setZoom(14); } else if (this.marks.length) this.map.fitBounds(b);
                        },
                    };
                    window.rpLiveMap.points = @js($points->values());
                    if (window.google && google.maps) window.rpLiveMap.draw();
                </script>
            @endif

            <x-filament::section icon="heroicon-o-truck" heading="{{ $points->count() }} {{ \Illuminate\Support\Str::plural('driver', $points->count()) }} on an active job">
                @forelse ($points as $p)
                    <div class="rp-np-device">
                        <div>
                            <strong>{{ $p['name'] }}</strong> @if ($p['old'])<span class="rp-pill c-warning">no update for a while</span>@endif
                            <div class="rp-muted" style="font-size:.85rem">{{ $p['job'] }} @if ($p['car'])· {{ $p['car'] }}@endif @if ($p['status'])· {{ $p['status'] }}@endif · {{ $p['at'] }}@if ($p['accuracy']) · ±{{ $p['accuracy'] }} m @endif</div>
                        </div>
                        <x-filament::button size="xs" color="gray" tag="a" :href="$p['link']" target="_blank">Open in Google Maps</x-filament::button>
                    </div>
                @empty
                    <p class="rp-muted">Nobody is sharing a location right now. Drivers share it only while a job is active, with the job open on their phone.</p>
                @endforelse
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
