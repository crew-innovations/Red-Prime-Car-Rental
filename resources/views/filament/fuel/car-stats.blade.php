{{-- Car › Fuel tab header (5 Nov, S5). --}}
<div style="width:100%">
    <div style="font-weight:600;margin-bottom:.55rem">Fuel
        <span class="rp-fuel-why">· {{ $car->fuel_grade ? \App\Support\Fuel\Fuel::GRADES[$car->fuel_grade] : 'grade not set' }} · {{ $car->tank_litres ? rtrim(rtrim(number_format((float) $car->tank_litres, 1), '0'), '.').' L tank' : 'tank size not set' }} · now {{ \App\Support\Fuel\Fuel::label($car->fuel_level_8) }}</span>
    </div>
    <div class="rp-fuel-stats">
        <div class="rp-fuel-stat"><small>This month</small><b>AED {{ number_format($s['month_spend'], 2) }}</b><span class="rp-fuel-why">{{ number_format($s['month_litres'], 1) }} L</span></div>
        <div class="rp-fuel-stat"><small>km per litre</small><b>{{ $s['km_per_litre'] ?? '—' }}</b><span class="rp-fuel-why">{{ $s['last_km_per_litre'] ? 'last fill-up '.$s['last_km_per_litre'] : 'needs two full-tank fill-ups with km' }}</span></div>
        <div class="rp-fuel-stat"><small>Cost per km</small><b>{{ $s['cost_per_km'] !== null ? 'AED '.number_format($s['cost_per_km'], 2) : '—' }}</b><span class="rp-fuel-why">at this month's price</span></div>
        <div class="rp-fuel-stat"><small>Waiting</small><b>{{ $s['waiting'] }}</b><span class="rp-fuel-why">for Accounts</span></div>
    </div>
    @if ($s['warning'])<div class="rp-fuel-alert">⚠ {{ $s['warning'] }}</div>@endif
    @if (! $car->tank_litres || ! $car->fuel_grade)<div class="rp-fuel-why" style="margin-top:.5rem">Add the tank size and fuel grade above (Fuel) so the fuel charge at return can be worked out.</div>@endif
</div>
