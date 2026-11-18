@extends('public.layout')
@section('title', 'Your ride · '.$b->reference)
@section('tag', 'Your ride')

{{-- 6 Nov (limousine L3): the customer follows the chauffeur. Polls /track/{id}/data every 15 s. --}}
@section('content')
    <div class="card trk" id="trk" data-feed="{{ $feed }}">
        <div class="trk-head">
            <div>
                <div class="muted" style="font-size:12px">Booking {{ $data['reference'] }}</div>
                <h1 id="trk-title" style="margin:2px 0 0">{{ collect($data['steps'])->firstWhere('key', $data['step'])['label'] ?? ($data['step'] === 'cancelled' ? 'Cancelled' : '') }}</h1>
            </div>
            <div class="trk-eta" id="trk-eta" @if (! $data['eta_minutes']) hidden @endif><b id="trk-eta-n">{{ $data['eta_minutes'] }}</b><small>min</small></div>
        </div>

        @if (session('ride_error'))<p class="trk-note trk-warn">{{ session('ride_error') }}</p>@endif
        @if ($data['step'] === 'cancelled')
            <p class="trk-note trk-warn">This ride was cancelled. Contact us if this is a mistake.</p>
        @endif

        {{-- 11 Nov: request → agent confirms the price → the customer accepts and pays, all on this page. --}}
        @php $p = $data['price']; @endphp
        @if (in_array($data['step'], ['requested', 'price'], true))
            <div class="trk-price">
                <div class="trk-price-top">
                    <span>{{ $p['estimate'] ? 'Estimated price' : 'Your price' }}</span>
                    <b>AED {{ number_format($p['total'], 2) }}</b>
                </div>
                @if ($p['km'])<small class="muted">{{ rtrim(rtrim(number_format($p['km'], 1), '0'), '.') }} km by road{{ $p['minutes'] ? ' · about '.$p['minutes'].' min' : '' }}</small>@endif
                @if (! $p['estimate'] && $p['extras'])
                    <table class="trk-lines">
                        <tr><td>Ride</td><td class="r">AED {{ number_format($p['ride'], 2) }}</td></tr>
                        @foreach ($p['extras'] as $x)<tr><td>{{ $x['label'] }}</td><td class="r">AED {{ number_format($x['amount'], 2) }}</td></tr>@endforeach
                    </table>
                @endif
                @if ($p['estimate'])
                    <p class="trk-note">Our team is checking your request and will confirm the final price here shortly. You pay nothing until you accept it.</p>
                @elseif ($p['can_pay'])
                    <form method="post" action="{{ $payUrl }}">@csrf
                        <button class="btn btn-primary" style="width:100%;margin-top:10px">Accept and pay AED {{ number_format($p['due'], 2) }}</button>
                    </form>
                    @if ($p['due'] + 0.004 < $p['total'] - $p['paid'])<p class="trk-note">You pay the advance now and the rest before the ride.</p>@endif
                @else
                    <p class="trk-note">The price is confirmed. Our team will send you the payment details{{ $data['phone'] ? ', or contact us below' : '' }}.</p>
                @endif
            </div>
        @endif

        <ol class="trk-steps" id="trk-steps">
            @php $reached = true; @endphp
            @foreach ($data['steps'] as $s)
                <li data-k="{{ $s['key'] }}" class="{{ $reached ? 'is-done' : '' }} {{ $s['key'] === $data['step'] ? 'is-now' : '' }}">{{ $s['label'] }}</li>
                @php if ($s['key'] === $data['step']) $reached = false; @endphp
            @endforeach
        </ol>

        <div id="trk-map" class="trk-map" @if (! $mapsKey || ! $data['location']) hidden @endif></div>
        <p id="trk-nomap" class="muted" style="font-size:13px" @if ($data['location'] || in_array($data['step'], ['requested', 'price', 'cancelled', 'done'], true)) hidden @endif>
            {{ $data['live'] ? 'Waiting for the car\'s position…' : 'The car appears on the map when your driver is on the way.' }}
        </p>
        {{-- Without the map key (or before the map loads) the position still opens in Google Maps. --}}
        <a id="trk-pos" class="btn btn-ghost" style="width:100%;margin-top:6px" target="_blank" rel="noopener"
           href="{{ $data['location'] ? 'https://www.google.com/maps?q='.$data['location']['lat'].','.$data['location']['lng'] : '#' }}"
           @if (! $data['location'] || $mapsKey) hidden @endif>See where the car is now</a>

        <table style="margin-top:12px">
            <tr><td class="muted">Pickup</td><td class="r">{{ $data['pickup'] }}<br><span class="muted">{{ $data['pickup_at'] }}</span></td></tr>
            @if ($data['dropoff'])<tr><td class="muted">Drop-off</td><td class="r">{{ $data['dropoff'] }}</td></tr>@endif
            <tr id="trk-driver-row" @if (! $data['driver']) hidden @endif><td class="muted">Driver</td><td class="r" id="trk-driver">{{ $data['driver']['name'] ?? '' }}</td></tr>
            @if ($data['car'])<tr><td class="muted">Car</td><td class="r">{{ $data['car']['name'] }}{{ ! empty($data['car']['colour']) ? ' · '.$data['car']['colour'] : '' }}{!! ! empty($data['car']['plate']) ? '<br><b class="trk-plate">'.e($data['car']['plate']).'</b>' : '' !!}</td></tr>@endif
        </table>

        @if ($data['phone'])
            @php $digits = preg_replace('/\D+/', '', $data['phone']); @endphp
            <div class="trk-actions">
                <a class="btn btn-primary" id="trk-call" href="tel:{{ $data['phone'] }}">Call {{ $data['phone_is_driver'] ? 'driver' : 'us' }}</a>
                <a class="btn btn-ghost" id="trk-wa" href="https://wa.me/{{ $digits }}" target="_blank" rel="noopener">WhatsApp</a>
            </div>
        @endif
        <p class="muted" style="font-size:12px;margin-top:10px">Free waiting: {{ $data['free_waiting'] }} minutes after your driver arrives. This page updates by itself.</p>
    </div>

    <style>
        .trk-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
        .trk-eta { text-align:center; min-width:64px; padding:8px 10px; border-radius:14px; background:#d7141a; color:#fff; line-height:1; }
        .trk-eta b { display:block; font-size:26px; } .trk-eta small { font-size:11px; }
        .trk-steps { list-style:none; padding:0; margin:14px 0; display:grid; gap:6px; }
        .trk-steps li { position:relative; padding-left:24px; font-size:14px; color:#8a8178; }
        .trk-steps li::before { content:''; position:absolute; left:4px; top:5px; width:10px; height:10px; border-radius:50%; border:2px solid #cfc7bd; }
        .trk-steps li.is-done { color:inherit; } .trk-steps li.is-done::before { background:#2e9d5b; border-color:#2e9d5b; }
        .trk-steps li.is-now { font-weight:700; } .trk-steps li.is-now::before { background:#d7141a; border-color:#d7141a; box-shadow:0 0 0 4px rgba(215,20,26,.18); }
        .trk-map { width:100%; aspect-ratio: 4/3; border-radius:14px; overflow:hidden; background:#eee; }
        .trk-price { margin-top:14px; padding:14px; border-radius:14px; border:1px solid rgba(212,166,42,.5); background:rgba(212,166,42,.08); }
        .trk-price-top { display:flex; justify-content:space-between; align-items:baseline; gap:10px; }
        .trk-price-top b { font-size:22px; }
        .trk-lines { margin-top:8px; font-size:13px; }
        .trk-note { font-size:13px; margin:10px 0 0; color:#8a8178; }
        .trk-warn { color:#d7141a; font-weight:600; }
        .trk-plate { display:inline-block; margin-top:4px; padding:2px 8px; border:2px solid currentColor; border-radius:6px; letter-spacing:.06em; }
        .trk-actions { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:14px; }
        .trk-actions .btn { text-align:center; }
    </style>
    <script>
    (function () {
        var box = document.getElementById('trk'), feed = box.dataset.feed, map = null, car = null, pin = null;
        var data = @json($data);
        function steps(d) {
            var reached = true;
            document.querySelectorAll('#trk-steps li').forEach(function (li) {
                li.classList.toggle('is-done', reached); li.classList.toggle('is-now', li.dataset.k === d.step);
                if (li.dataset.k === d.step) reached = false;
            });
            var now = d.steps.filter(function (s) { return s.key === d.step; })[0];
            if (now) document.getElementById('trk-title').textContent = now.label;
            var eta = document.getElementById('trk-eta');
            if (d.eta_minutes) { eta.hidden = false; document.getElementById('trk-eta-n').textContent = d.eta_minutes; } else eta.hidden = true;
            if (d.driver) { document.getElementById('trk-driver-row').hidden = false; document.getElementById('trk-driver').textContent = d.driver.name; }
        }
        function place(d) {
            var nomap = document.getElementById('trk-nomap');
            var pos = document.getElementById('trk-pos');
            if (nomap) nomap.hidden = !!d.location || ['requested', 'price', 'cancelled', 'done'].indexOf(d.step) > -1;
            if (pos) { pos.hidden = !d.location || !!window.google; if (d.location) pos.href = 'https://www.google.com/maps?q=' + d.location.lat + ',' + d.location.lng; }
            if (!window.google || !d.location) return;
            nomap.hidden = true; document.getElementById('trk-map').hidden = false;
            var at = { lat: d.location.lat, lng: d.location.lng };
            if (!map) { map = new google.maps.Map(document.getElementById('trk-map'), { center: at, zoom: 14, disableDefaultUI: true, zoomControl: true }); }
            if (!car) car = new google.maps.Marker({ map: map, position: at, title: 'Your car' }); else car.setPosition(at);
            map.panTo(at);
        }
        window.rpTrackMap = function () { place(data); };
        function poll() {
            fetch(feed, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) { if (!d) return; if (d.step !== data.step && (['requested','price','cancelled'].indexOf(d.step) > -1 || ['requested','price'].indexOf(data.step) > -1)) { location.reload(); return; } data = d; steps(d); place(d); if (d.step === 'done') clearInterval(t); })
                .catch(function () {});
        }
        var t = setInterval(poll, 15000);
    })();
    </script>
    @if ($mapsKey)
        <script async src="https://maps.googleapis.com/maps/api/js?key={{ urlencode($mapsKey) }}&callback=rpTrackMap"></script>
    @endif
@endsection
