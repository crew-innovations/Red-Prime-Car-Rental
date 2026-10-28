@extends('pdf.layout')
@section('title', 'Job report '.$b->reference)
@section('doc-title', 'Job Completion Report')

@php
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $fuel = \App\Models\Booking::FUEL_LEVELS;
    $svc = $b->service_type;
    $isCar = $svc === \App\Enums\ServiceType::Car;
    $isTransfer = $svc === \App\Enums\ServiceType::Transfer;
    $isCharter = $svc === \App\Enums\ServiceType::Yacht;
    $item = $b->bookable;
    $when = fn ($d) => $d ? $d->format('D d M Y, H:i') : '-';
    $people = collect([
        'Agent' => $b->assignee?->name,
        $isCharter ? 'Captain' : ($isTransfer ? 'Chauffeur' : 'Driver') => $isCharter ? $b->captain?->name : $b->driver?->name,
        'Collection driver' => $isCar ? $b->returnDriver?->name : null,
        'Crew' => $isCharter && $b->crew_ids ? $b->crewMembers()->pluck('name')->implode(', ') : null,
        'Handed over by' => $b->deliveredBy?->name,
        'Received by' => $b->receivedBy?->name,
    ])->filter();
    $checks = $jobs->flatMap(fn ($j) => collect((array) data_get($j->inspection, 'checklist', []))->map(fn ($v, $k) => [$j->typeLabel(), is_string($k) ? $k : $v, is_bool($v) ? $v : true]));
@endphp

@section('meta')
    <tr><td class="k">Report</td><td class="r"><strong>{{ $number ?? 'Draft' }}</strong>@if (! empty($version)) · v{{ $version }}@endif</td></tr>
    <tr><td class="k">Booking</td><td class="r"><strong>{{ $b->reference }}</strong></td></tr>
    <tr><td class="k">Service</td><td class="r">{{ $svc?->getLabel() }}</td></tr>
    <tr><td class="k">Status</td><td class="r">{{ $b->status?->getLabel() }}</td></tr>
@endsection

@section('content')
    @if ($missing)
        <div class="box" style="margin-top:14px;border-color:#f59e0b;background:#fffbeb">
            <div class="label" style="color:#92400e">Incomplete when this report was made</div>
            {{ implode(' · ', $missing) }}
        </div>
    @else
        <div style="margin-top:14px"><span class="pill pill-ok">All required details recorded</span></div>
    @endif

    <table class="parties" style="margin-top:14px">
        <tr>
            <td style="padding-right:8px">
                <div class="box">
                    <div class="label">Customer</div>
                    <div class="strong">{{ $b->customer?->name ?? '-' }}</div>
                    @if ($b->customer?->phone)<div class="muted">{{ $b->customer->phone }}</div>@endif
                    @if ($b->customer?->email)<div class="muted">{{ $b->customer->email }}</div>@endif
                </div>
            </td>
            <td style="padding-left:8px">
                <div class="box">
                    <div class="label">{{ $isTransfer ? 'Vehicle' : ($isCharter ? 'Yacht' : ($isCar ? 'Car' : 'Trip')) }}</div>
                    <div class="strong">{{ $item?->name ?? ($isTransfer ? ($b->vehicleCategory?->name ?? '-') : '-') }}</div>
                    @if ($isCar || $isTransfer)
                        <div class="muted">{{ collect([$item?->registration_number, $isTransfer ? $b->vehicleCategory?->name : null, $b->drive_mode?->getLabel()])->filter()->implode(' · ') }}</div>
                    @endif
                    @if ($isCharter)<div class="muted">{{ collect([$b->crew_mode === 'bareboat' ? 'Bareboat' : 'With captain & crew', $item?->marina])->filter()->implode(' · ') }}</div>@endif
                </div>
            </td>
        </tr>
    </table>

    <h3>Schedule and completion</h3>
    <table class="info">
        <tr><td class="k">Booked</td><td>{{ $when($b->start_at) }} → {{ $when($b->end_at) }}</td></tr>
        <tr><td class="k">{{ $isTransfer ? 'Picked up' : ($isCharter ? 'Charter started' : 'Handed over') }}</td><td>{{ $when($b->delivered_at) }}</td></tr>
        <tr><td class="k">{{ $isTransfer ? 'Dropped off / closed' : ($isCharter ? 'Charter ended / closed' : 'Returned') }}</td><td>{{ $when($b->returned_at) }}</td></tr>
        @if ($isTransfer)
            <tr><td class="k">Service</td><td>{{ $b->transferService?->name }}{{ $b->journey_type === 'return' ? ' · return journey' : '' }}</td></tr>
            <tr><td class="k">Pickup</td><td>{{ $b->pickup_location ?: '-' }}</td></tr>
            @foreach ((array) $b->stops as $n => $s)<tr><td class="k">Stop {{ $n + 1 }}</td><td>{{ $s['address'] ?? '' }}</td></tr>@endforeach
            <tr><td class="k">Drop-off</td><td>{{ $b->dropoff_location ?: '-' }}</td></tr>
            @if ($b->return_at)<tr><td class="k">Return pickup</td><td>{{ $when($b->return_at) }}</td></tr>@endif
            <tr><td class="k">Route</td><td>{{ $b->route_km ? rtrim(rtrim(number_format((float) $b->route_km, 1), '0'), '.').' km' : '-' }}{{ $b->route_minutes ? ' · about '.$b->route_minutes.' min' : '' }}{{ $b->route_source ? ' ('.($b->route_source === 'google' ? 'Google Maps' : 'typed by staff').')' : '' }}</td></tr>
            @if ($b->flight_number)<tr><td class="k">Flight</td><td>{{ collect([$b->flight_number, $b->airline, $b->terminal ? 'Terminal '.$b->terminal : null])->filter()->implode(' · ') }}</td></tr>@endif
            <tr><td class="k">Passengers</td><td>{{ collect([$b->guests ? $b->guests.' passengers' : null, $b->luggage ? $b->luggage.' bags' : null, $b->child_seats ? $b->child_seats.' child seat(s)' : null])->filter()->implode(' · ') ?: '-' }}</td></tr>
        @else
            <tr><td class="k">{{ $isCar ? 'Pick-up / delivery' : 'Meeting point / marina' }}</td><td>{{ $b->pickup_location ?: '-' }}</td></tr>
            @if ($isCar && $b->return_location)<tr><td class="k">Return location</td><td>{{ $b->return_location }}</td></tr>@endif
            @if (! $isCar)<tr><td class="k">Guests</td><td>{{ $b->guests ?: '-' }}</td></tr>@endif
        @endif
        @foreach ($people as $role => $name)<tr><td class="k">{{ $role }}</td><td>{{ $name }}</td></tr>@endforeach
    </table>

    @if ($isCar)
        <table class="lines">
            <thead><tr><th></th><th>Handover</th><th>Return</th></tr></thead>
            <tbody>
                <tr><td class="muted">Odometer</td><td>{{ $b->delivery_km ? number_format($b->delivery_km).' km' : '-' }}</td><td>{{ $b->return_km ? number_format($b->return_km).' km' : '-' }}@if ($b->delivery_km && $b->return_km) <span class="muted">({{ number_format($b->return_km - $b->delivery_km) }} km)</span>@endif</td></tr>
                <tr><td class="muted">Fuel</td><td>{{ $fuel[$b->delivery_fuel] ?? '-' }}</td><td>{{ $fuel[$b->return_fuel] ?? '-' }}</td></tr>
                <tr><td class="muted">Condition</td><td>{{ $b->delivery_notes ? \Illuminate\Support\Str::limit($b->delivery_notes, 140) : 'As handed over' }}</td><td>{{ \App\Models\Booking::CONDITIONS[$b->return_condition] ?? '-' }}</td></tr>
            </tbody>
        </table>
    @endif

    @if ($jobs->isNotEmpty())
        <h3>Driver and crew jobs</h3>
        <table class="lines" style="margin-top:4px">
            <thead><tr><th>Job</th><th>Who</th><th>Planned</th><th>Done</th></tr></thead>
            <tbody>
                @foreach ($jobs as $j)
                    <tr><td>{{ $j->typeLabel() }}{{ $j->role ? ' · '.ucfirst($j->role) : '' }}</td><td>{{ $j->driver?->name }}</td><td>{{ $j->scheduled_at?->format('d M H:i') }}</td><td>{{ $j->done_at?->format('d M H:i') ?? $j->statusLabel() }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($b->damageReports->isNotEmpty())
        <h3>Damage and incidents</h3>
        <table class="lines" style="margin-top:4px">
            <thead><tr><th>Reported</th><th>What</th><th>Outcome</th><th class="r">Charge</th></tr></thead>
            <tbody>
                @foreach ($b->damageReports as $r)
                    <tr><td>{{ $r->created_at?->format('d M H:i') }}</td><td>{{ $r->description }}</td><td>{{ $r->statusLabel() }}</td><td class="r">{{ $r->status === 'charged' ? $aed($r->charge_amount) : '-' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (array_filter((array) $b->charges))
        <h3>Extra charges</h3>
        <table class="lines" style="margin-top:4px">
            <tbody>
                @foreach ((array) $b->charges as $key => $amount)
                    @if ((float) $amount > 0)<tr><td>{{ \App\Support\Options::chargeLabel($key) }}</td><td class="r" style="width:24%">{{ $aed($amount) }}</td></tr>@endif
                @endforeach
            </tbody>
        </table>
    @endif

    <h3>Money</h3>
    <table class="lines" style="margin-top:4px">
        <thead><tr><th>Date</th><th>What</th><th>Method</th><th class="r">Amount</th></tr></thead>
        <tbody>
            @forelse ($payments->concat($deposits)->sortBy('date') as $t)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($t->date)->format('d M Y') }}</td>
                    <td>{{ $t->categoryLabel() }}@if ($t->reversed_at) <span class="pill pill-bad">reversed</span>@elseif ($t->status === \App\Enums\PaymentStatus::Refunded) <span class="pill pill-warn">refunded</span>@endif</td>
                    <td>{{ $t->method?->getLabel() ?? '-' }}</td>
                    <td class="r">{{ $aed($t->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No money recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
    <table class="totals">
        <tr><td style="width:55%"></td><td class="muted">Quoted / booked price</td><td class="r">{{ $aed($b->total) }}</td></tr>
        @if ((float) $b->extra_charges > 0)<tr><td></td><td class="muted">Extra charges</td><td class="r">{{ $aed($b->extra_charges) }}</td></tr>@endif
        <tr><td></td><td class="muted">Paid</td><td class="r">{{ $aed($b->paid_amount) }}</td></tr>
        <tr class="grand"><td></td><td>Balance</td><td class="r">{{ $aed($b->balance) }}</td></tr>
        <tr><td></td><td class="muted">Security deposit still held</td><td class="r">{{ $aed($b->depositHeld()) }}</td></tr>
    </table>

    @if ($photos)
        <h3>Photos</h3>
        <table>
            @foreach (array_chunk($photos, 4) as $row)
                <tr>
                    @foreach ($row as $p)
                        <td style="width:25%;padding:3px;vertical-align:top"><img src="{{ $p['src'] }}" style="width:100%" alt=""><div class="muted" style="font-size:7.5px">{{ $p['label'] }}{{ $p['at'] ? ' · '.$p['at']->format('d M H:i') : '' }}</div></td>
                    @endforeach
                    @for ($i = count($row); $i < 4; $i++)<td style="width:25%"></td>@endfor
                </tr>
            @endforeach
        </table>
        <div class="muted" style="font-size:7.5px">Small copies. The full photos stay in the CRM.</div>
    @endif

    @if ($checks->isNotEmpty())
        <h3>Checklist</h3>
        <table class="info">
            @foreach ($checks as [$job, $item, $ok])<tr><td class="k">{{ $job }}</td><td>{{ $ok ? '✔' : '✘' }} {{ $item }}</td></tr>@endforeach
        </table>
    @endif

    @if ($b->delivery_notes || $b->return_notes)
        <h3>Staff notes</h3>
        <div class="note">@if ($b->delivery_notes)<strong>Start:</strong> {{ $b->delivery_notes }}@endif
@if ($b->return_notes)<strong>End:</strong> {{ $b->return_notes }}@endif</div>
    @endif

    <h3>Customer acknowledgement</h3>
    <div class="muted">No signature is captured in the CRM for this job.</div>

    <div class="muted" style="margin-top:16px;font-size:8px">Made {{ now()->format('d M Y H:i') }}{{ $by ? ' by '.$by->name : '' }} from the records in the CRM. This report does not change the booking.</div>
@endsection
