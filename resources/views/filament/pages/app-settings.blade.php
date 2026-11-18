<x-filament-panels::page>
    <div class="rp-apps-grid">
        @foreach ($this->apps() as $key => $a)
            <x-filament::section>
                <div class="rp-app-card">
                    <img src="{{ $a['icon_url'] }}" alt="" class="rp-app-icon" style="background: {{ $a['background'] }}">
                    <div>
                        <div class="rp-app-name">{{ $a['name'] }}</div>
                        <div class="rp-muted" style="font-size:.85rem">"{{ $a['short_name'] }}" · version {{ $a['version'] }} · opens {{ $a['start'] }}</div>
                        <div class="rp-muted" style="font-size:.85rem">{{ $a['installed'] }} installed · {{ $a['devices'] }} {{ \Illuminate\Support\Str::plural('device', $a['devices']) }}</div>
                    </div>
                </div>
                <div class="rp-app-install">
                    @if ($a['qr'])<img src="{{ $a['qr'] }}" alt="QR code" class="rp-app-qr">@endif
                    <div style="min-width:0">
                        <div class="rp-muted" style="font-size:.8rem">Install link</div>
                        <a href="{{ $a['install'] }}" target="_blank" rel="noopener" class="rp-app-link">{{ $a['install'] }}</a>
                        <div style="margin-top:.4rem">
                            <x-filament::button size="xs" color="gray" x-data x-on:click="navigator.clipboard && navigator.clipboard.writeText('{{ $a['install'] }}'); $tooltip('Copied')">Copy link</x-filament::button>
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    {{ $this->form }}

    @php($drivers = $this->drivers())
    <x-filament::section icon="heroicon-o-truck" heading="Drivers" description="Who has the driver app and notifications. A driver with notifications off only sees new jobs when they open the app.">
        @if ($drivers->isEmpty())
            <p class="rp-muted">No drivers yet.</p>
        @else
            <div class="rp-table-wrap">
                <table class="rp-simple-table">
                    <thead><tr><th>Driver</th><th>App</th><th>Notifications</th><th>Devices</th><th>Last seen</th><th>Version</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($drivers as $u)
                            @php($s = \App\Filament\Pages\AppSettings::driverStatus($u))
                            <tr wire:key="drv-{{ $u->id }}">
                                <td><strong>{{ $u->name }}</strong><div class="rp-muted" style="font-size:.8rem">{{ $u->phone }}</div></td>
                                <td>{!! $s['installed'] ? '<span class="rp-pill c-success">Installed</span>' : '<span class="rp-pill">Not installed</span>' !!}</td>
                                <td>{!! $s['push'] ? '<span class="rp-pill c-success">On</span>' : ($s['blocked'] ? '<span class="rp-pill c-danger">Blocked</span>' : '<span class="rp-pill c-warning">Off</span>') !!}</td>
                                <td>
                                    @foreach ($u->devices as $d)
                                        <div style="font-size:.85rem">{{ $d->label ?: 'Device' }}
                                            <x-filament::link tag="button" size="sm" color="danger" wire:click="removeDevice({{ $d->id }})" wire:confirm="Remove this device? It is signed out and gets no more notifications (lost or changed phone).">remove</x-filament::link>
                                        </div>
                                    @endforeach
                                    @if ($u->devices->isEmpty())<span class="rp-muted">—</span>@endif
                                </td>
                                <td>{{ $s['last_seen'] ? \Illuminate\Support\Carbon::parse($s['last_seen'])->diffForHumans() : '—' }}</td>
                                <td>{{ $s['version'] ?: '—' }}</td>
                                <td style="white-space:nowrap">
                                    <x-filament::button size="xs" color="gray" wire:click="testDriver({{ $u->id }})">Test notification</x-filament::button>
                                    @if ($wa = $this->whatsappLink($u))
                                        <x-filament::button size="xs" color="gray" tag="a" href="{{ $wa }}" target="_blank" rel="noopener">WhatsApp link</x-filament::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
