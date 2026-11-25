@extends('public.layout')
@section('title', 'Rental agreement '.$c->number)
@section('tag', 'Rental agreement')

@php
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $s = \App\Models\Setting::values();
    $locked = $c->signed_at || $c->driver_signed_at;
    $terms = $locked ? $c->terms_en : ($s['contract_terms_en'] ?? null);
    $termsAr = $locked ? $c->terms_ar : ($s['contract_terms_ar'] ?? null);
    $policies = collect(['policy_fuel' => 'Fuel', 'policy_late_return' => 'Late return', 'policy_fines' => 'Fines, Salik and tolls', 'policy_cancellation' => 'Cancellation and changes'])
        ->filter(fn ($label, $key) => filled($s[$key] ?? null));
@endphp

@section('content')
    @if (! empty($done))
        <div class="alert ok">Thank you, {{ strtok((string) (($who ?? 'hirer') === 'driver' ? $c->driver_signed_by_name : $c->signed_by_name), ' ') }}. Your rental agreement {{ $c->number }} is signed. Our team has been told.</div>
    @endif

    <div class="card">
        <h1>Rental agreement {{ $c->number }}</h1>
        <p class="muted" style="margin:0">{{ $c->customer?->name }} · {{ $b?->bookable?->name }}{{ $b?->bookable?->registration_number ? ' · '.$b->bookable->registration_number : '' }}</p>
        <table style="margin-top:12px">
            <tr><td>Pick-up</td><td class="r">{{ $b?->start_at?->format('D d M Y, H:i') }}</td></tr>
            <tr><td>Return</td><td class="r">{{ $b?->end_at?->format('D d M Y, H:i') }}</td></tr>
            <tr><td>Rental type</td><td class="r">{{ $b?->drive_mode?->getLabel() }}</td></tr>
            @if ($b?->kmLimitPerDay())<tr><td>Kilometres</td><td class="r">{{ number_format($b->kmLimitPerDay()) }} km per day{{ $b->extraKmRate() ? ', then '.$aed($b->extraKmRate()).' per km' : '' }}</td></tr>@endif
            <tr><td>Drivers</td><td class="r">{{ $c->drivers->pluck('name')->implode(', ') }}</td></tr>
            <tr><td>Total</td><td class="r"><strong>{{ $aed($b?->amountDue()) }}</strong></td></tr>
            <tr><td>{{ $b?->deposit_mode === 'none' ? 'Deposit' : 'Security deposit (refundable)' }}</td><td class="r">{{ $b?->deposit_mode === 'none' ? 'No deposit' : $aed($b?->security_deposit) }}</td></tr>
        </table>
    </div>

    <div class="card">
        <h2>Terms and conditions</h2>
        @if (filled($terms))<div class="muted" style="white-space:pre-line;font-size:13px">{{ $terms }}</div>@endif
        @if (filled($termsAr))<div class="muted" dir="rtl" style="white-space:pre-line;font-size:13px;margin-top:12px">{{ $termsAr }}</div>@endif
        @if (blank($terms) && blank($termsAr))
            @forelse ($policies as $key => $label)
                <p class="muted" style="font-size:13px"><strong>{{ $label }}.</strong> {{ $s[$key] }}</p>
            @empty
                <p class="muted" style="font-size:13px">The rental terms of RED PRIME RENTALS apply. Ask our team for a copy.</p>
            @endforelse
        @endif
    </div>

    @if ($token)
        @php
            $who = $who ?? 'hirer';
            $driver = $who === 'driver' ? \App\Support\Contracts\Contracts::driverContact($c) : null;
        @endphp
        <form method="post" action="{{ route($who === 'driver' ? 'contracts.driver-sign.store' : 'contracts.sign.store', $token) }}" class="card" id="sign-form">
            @csrf
            <h2>{{ $who === 'driver' ? 'Driver signature' : 'Sign' }}</h2>
            @if ($errors->any())<div class="alert bad">{{ $errors->first() }}</div>@endif
            <label for="name">Your full name</label>
            <input id="name" name="name" value="{{ old('name', $who === 'driver' ? ($driver['name'] ?? '') : $c->customer?->name) }}" required maxlength="120" style="width:100%;font:15px Poppins,sans-serif;padding:12px;border:1px solid var(--line);border-radius:12px">
            <label>Signature</label>
            <canvas id="pad" style="width:100%;height:180px;border:1px dashed #a1a1aa;border-radius:12px;background:#fff;touch-action:none"></canvas>
            <div style="display:flex;justify-content:space-between;margin-top:6px"><span class="muted">Sign with your finger or mouse</span><button type="button" class="btn btn-ghost" id="clear" style="padding:6px 12px">Clear</button></div>
            <input type="hidden" name="signature" id="signature">
            @if ($who === 'hirer' && \App\Http\Controllers\ContractSignController::needsCode($c))
                <label for="code">Code from your email</label>
                <div style="display:flex;gap:8px">
                    <input id="code" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" style="flex:1;font:15px Poppins,sans-serif;padding:12px;border:1px solid var(--line);border-radius:12px">
                    <button class="btn btn-ghost" type="submit" formaction="{{ route('contracts.sign.code', $token) }}" formnovalidate>{{ session('code_sent') ? 'Send again' : 'Email me a code' }}</button>
                </div>
                @if (session('code_sent'))<p class="muted" style="margin:6px 0 0">We emailed a 6-digit code to {{ \Illuminate\Support\Str::mask((string) $c->customer?->email, '*', 2, max(1, strpos((string) $c->customer?->email, '@') - 2)) }}.</p>@endif
            @endif
            <label style="display:flex;gap:10px;align-items:flex-start;font-weight:500"><input type="checkbox" name="agree" value="1" required style="margin-top:4px"> I have read the rental agreement and the terms and agree to them.</label>
            <button class="btn btn-primary" style="width:100%;margin-top:14px" type="submit">Sign the agreement</button>
        </form>
        <script>
            (function () {
                const c = document.getElementById('pad'), r = window.devicePixelRatio || 1;
                c.width = c.offsetWidth * r; c.height = 180 * r;
                const x = c.getContext('2d'); x.scale(r, r); x.lineWidth = 2.4; x.lineCap = 'round'; x.lineJoin = 'round'; x.strokeStyle = '#111';
                x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
                let down = false, empty = true;
                const pos = e => { const b = c.getBoundingClientRect(), p = e.touches ? e.touches[0] : e; return [p.clientX - b.left, p.clientY - b.top]; };
                const start = e => { e.preventDefault(); down = true; const [a, b] = pos(e); x.beginPath(); x.moveTo(a, b); };
                const move = e => { if (!down) return; e.preventDefault(); const [a, b] = pos(e); x.lineTo(a, b); x.stroke(); empty = false; };
                const end = () => { down = false; };
                ['mousedown', 'touchstart'].forEach(t => c.addEventListener(t, start, { passive: false }));
                ['mousemove', 'touchmove'].forEach(t => c.addEventListener(t, move, { passive: false }));
                ['mouseup', 'mouseleave', 'touchend'].forEach(t => c.addEventListener(t, end));
                document.getElementById('clear').onclick = () => { x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); empty = true; };
                document.getElementById('sign-form').addEventListener('submit', e => {
                    if (e.submitter && e.submitter.hasAttribute('formnovalidate')) return; // asking for the email code
                    if (empty) { e.preventDefault(); alert('Please sign in the box.'); return; }
                    document.getElementById('signature').value = c.toDataURL('image/png');
                });
            })();
        </script>
    @endif
@endsection
