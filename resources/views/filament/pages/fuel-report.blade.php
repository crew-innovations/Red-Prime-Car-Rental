@php
    $rows = collect($this->rows());
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $m = $this->monthDate();
    $litres = $rows->sum('litres');
    $spend = $rows->sum('spend');
    $charged = $rows->sum('charged');
@endphp
<x-filament-panels::page>
    <div class="rp-rep-bar">
        <div class="rp-rep-quick">
            <button type="button" wire:click="shift(-1)">‹ {{ $m->copy()->subMonthNoOverflow()->format('M Y') }}</button>
            <strong style="padding:0 .6rem">{{ $m->format('F Y') }}</strong>
            @if ($m->lt(now()->startOfMonth()))<button type="button" wire:click="shift(1)">{{ $m->copy()->addMonthNoOverflow()->format('M Y') }} ›</button>@endif
        </div>
    </div>

    <div class="rp-rep-cards">
        <div class="rp-rep-card rp-rep-main"><span>Fuel spend</span><strong>{{ $aed($spend) }}</strong><em>{{ number_format($litres, 1) }} L, confirmed fill-ups</em></div>
        <div class="rp-rep-card"><span>Charged to customers</span><strong>{{ $aed($charged) }}</strong><em>Fuel refill on returns this month</em></div>
        <div class="rp-rep-card"><span>Company paid</span><strong>{{ $aed(max(0, $spend - $charged)) }}</strong><em>Spend minus charged</em></div>
    </div>

    <div class="rp-rep-table-wrap">
        <table class="rp-rep-table">
            <thead>
                <tr>
                    <th>Car</th>
                    <th class="r">Fill-ups</th>
                    <th class="r">Litres</th>
                    <th class="r">Spend</th>
                    <th class="r">km driven</th>
                    <th class="r">km / L</th>
                    <th class="r">Cost / km</th>
                    <th class="r">Charged</th>
                    <th class="r">Difference</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td><a href="{{ \App\Filament\Resources\CarResource::getUrl('edit', ['record' => $r['car']]) }}" style="color:rgb(var(--primary-600));font-weight:600">{{ $r['car']->name }}</a> <span class="rp-muted">{{ $r['car']->registration_number }}</span></td>
                        <td class="r">{{ $r['fills'] }}</td>
                        <td class="r">{{ number_format($r['litres'], 1) }}</td>
                        <td class="r"><strong>{{ $aed($r['spend']) }}</strong></td>
                        <td class="r">{{ $r['km'] !== null ? number_format($r['km']) : '—' }}</td>
                        <td class="r">{{ $r['km_per_litre'] ?? '—' }}</td>
                        <td class="r">{{ $r['cost_per_km'] !== null ? number_format($r['cost_per_km'], 2) : '—' }}</td>
                        <td class="r">{{ $aed($r['charged']) }}</td>
                        <td class="r" style="color:{{ $r['difference'] < 0 ? 'rgb(var(--danger-600))' : 'rgb(var(--success-600))' }}">{{ $aed($r['difference']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="rp-muted">No confirmed fill-ups or fuel charges in {{ $m->format('F Y') }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-filament::section heading="How these numbers are worked out" icon="heroicon-o-information-circle" collapsible collapsed compact>
        <ul class="rp-rep-defs">
            <li><strong>Spend and litres:</strong> confirmed fill-ups in the month (waiting and rejected ones don't count).</li>
            <li><strong>km driven:</strong> highest minus lowest odometer typed on this month's fill-ups. Needs at least two fill-ups with an odometer.</li>
            <li><strong>km / L:</strong> km driven ÷ the litres put in after the first odometer reading of the month (the first fill-up's fuel was used before it). <strong>Cost / km:</strong> what those fill-ups cost ÷ km driven.</li>
            <li><strong>Charged:</strong> the "Fuel refill" return charge on bookings returned this month. <strong>Difference:</strong> charged minus spend (red = the company paid more than it charged).</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
