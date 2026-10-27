@php
    $user = auth()->user();
    $customersOn = (bool) \App\Models\Setting::read('customer_accounts_enabled', true);
    $isCustomer = $user && $user->isCustomerAccount();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0b0708">
    <title>RED PRIME CRM · RED PRIME RENTALS</title>
    <link rel="icon" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --red: #d7141a; --red-deep: #8f0d11; --gold: #d4a62a; --gold-soft: #f0d27a; --bg: #0b0708; --panel: #141011; --line: rgba(255,255,255,.08); --text: #f4f1ee; --muted: #a8a09c; }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: Poppins, system-ui, sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; -webkit-font-smoothing: antialiased; overflow-x: hidden; }
        a { color: inherit; }

        .glow { position: fixed; inset: 0; pointer-events: none; z-index: 0;
            background:
                radial-gradient(900px 520px at 85% -10%, rgba(215,20,26,.32), transparent 60%),
                radial-gradient(700px 420px at -10% 110%, rgba(212,166,42,.14), transparent 60%); }
        .wrap { position: relative; z-index: 1; max-width: 1120px; margin: 0 auto; padding: 0 20px; }

        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 22px 0; }
        header img { height: 40px; width: auto; display: block; }
        .top-link { font-size: 14px; font-weight: 500; color: var(--muted); text-decoration: none; padding: 8px 14px; border: 1px solid var(--line); border-radius: 999px; transition: color .2s, border-color .2s; }
        .top-link:hover { color: var(--text); border-color: rgba(212,166,42,.5); }

        .hero { display: grid; grid-template-columns: 1.1fr .9fr; gap: 48px; align-items: center; padding: 56px 0 72px; }
        .eyebrow { display: inline-flex; align-items: center; gap: 10px; font-size: 12px; font-weight: 600; letter-spacing: .18em; text-transform: uppercase; color: var(--gold); }
        .eyebrow::before { content: ""; width: 28px; height: 1px; background: var(--gold); }
        h1 { font-size: clamp(34px, 5vw, 56px); line-height: 1.08; font-weight: 700; margin: 18px 0 18px; letter-spacing: -.02em; }
        h1 .gold { background: linear-gradient(100deg, var(--gold-soft), var(--gold) 60%, #a8781a); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .lead { font-size: 17px; color: var(--muted); max-width: 520px; margin: 0 0 32px; font-weight: 300; }

        .actions { display: flex; flex-wrap: wrap; gap: 12px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; padding: 15px 26px; border-radius: 14px; font: 600 15px Poppins, sans-serif; text-decoration: none; transition: transform .15s, box-shadow .2s, background .2s, border-color .2s; }
        .btn svg { width: 18px; height: 18px; flex: none; }
        .btn:active { transform: translateY(1px); }
        .btn-primary { background: linear-gradient(135deg, #e8262c, var(--red) 55%, var(--red-deep)); color: #fff; box-shadow: 0 10px 30px -10px rgba(215,20,26,.7); }
        .btn-primary:hover { box-shadow: 0 14px 36px -10px rgba(215,20,26,.9); }
        .btn-ghost { background: rgba(255,255,255,.03); color: var(--text); border: 1px solid rgba(212,166,42,.4); }
        .btn-ghost:hover { border-color: var(--gold); background: rgba(212,166,42,.08); }
        .hint { margin-top: 18px; font-size: 13px; color: var(--muted); }

        .brand-card { position: relative; border-radius: 28px; padding: 48px 36px; background: linear-gradient(160deg, rgba(255,255,255,.05), rgba(255,255,255,.01)); border: 1px solid var(--line); text-align: center; overflow: hidden; }
        .brand-card::before { content: ""; position: absolute; inset: -40% -20% auto auto; width: 320px; height: 320px; background: radial-gradient(circle, rgba(215,20,26,.35), transparent 65%); }
        .brand-card img { position: relative; width: 100%; max-width: 340px; height: auto; filter: drop-shadow(0 20px 40px rgba(0,0,0,.6)); }
        .brand-card .line { position: relative; margin: 28px auto 0; display: flex; justify-content: center; gap: 22px; flex-wrap: wrap; font-size: 12px; letter-spacing: .16em; text-transform: uppercase; color: var(--muted); }
        .brand-card .line span::before { content: "◆"; color: var(--gold); margin-right: 8px; font-size: 8px; vertical-align: 2px; }

        .section-title { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
        .section-title h2 { font-size: 24px; margin: 0; font-weight: 600; }
        .section-title p { margin: 0; color: var(--muted); font-size: 14px; }
        .features { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; padding-bottom: 72px; }
        .feature { background: var(--panel); border: 1px solid var(--line); border-radius: 20px; padding: 24px 22px; transition: border-color .2s, transform .2s; }
        .feature:hover { border-color: rgba(212,166,42,.35); transform: translateY(-2px); }
        .icon { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; background: rgba(215,20,26,.12); color: #ff5a5f; margin-bottom: 18px; }
        .feature:nth-child(even) .icon { background: rgba(212,166,42,.12); color: var(--gold); }
        .icon svg { width: 22px; height: 22px; }
        .feature h3 { font-size: 16px; margin: 0 0 8px; font-weight: 600; }
        .feature p { margin: 0; color: var(--muted); font-size: 14px; }

        footer { border-top: 1px solid var(--line); padding: 26px 0 32px; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; font-size: 13px; color: var(--muted); }
        footer .lock { display: inline-flex; align-items: center; gap: 8px; }
        footer svg { width: 14px; height: 14px; color: var(--gold); }

        @media (max-width: 960px) {
            .hero { grid-template-columns: 1fr; gap: 36px; padding: 28px 0 56px; }
            .brand-card { order: -1; padding: 36px 24px; }
            .brand-card img { max-width: 260px; }
            .features { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 560px) {
            header img { height: 32px; }
            .lead { font-size: 15px; }
            .actions .btn { width: 100%; }
            .features { grid-template-columns: 1fr; }
            .section-title { display: block; }
            .section-title p { margin-top: 6px; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>
    <div class="glow" aria-hidden="true"></div>
    <div class="wrap">
        <header>
            <img src="{{ asset('images/logo-mark.png') }}" alt="RED PRIME RENTALS">
            @if ($user)
                <a class="top-link" href="{{ $isCustomer ? url('/account') : url('/crm') }}">{{ $isCustomer ? 'My account' : 'Open CRM' }}</a>
            @else
                <a class="top-link" href="{{ url('/crm/login') }}">Sign in</a>
            @endif
        </header>

        <section class="hero">
            <div>
                <span class="eyebrow">RED PRIME CRM</span>
                <h1>Luxury rentals,<br><span class="gold">run from one place.</span></h1>
                <p class="lead">The private workspace for the RED PRIME RENTALS team. Bookings, cars, yachts, customers and drivers, all kept in step.</p>

                <div class="actions">
                    @if ($user)
                        <a class="btn btn-primary" href="{{ $isCustomer ? url('/account') : url('/crm') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                            {{ $isCustomer ? 'Open my account' : 'Open the CRM' }}
                        </a>
                    @else
                        <a class="btn btn-primary" href="{{ url('/crm/login') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                            Team login
                        </a>
                        @if ($customersOn)
                            <a class="btn btn-ghost" href="{{ url('/account/login') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                                Customer login
                            </a>
                        @endif
                    @endif
                </div>
                @unless ($user)
                    <p class="hint">Team login is for staff, agents and drivers.@if ($customersOn) Customers sign in to see their bookings and documents.@endif</p>
                @endunless
            </div>

            <div class="brand-card">
                <img src="{{ asset('images/logo.png') }}" alt="RED PRIME Car Rental">
                <div class="line"><span>Cars</span><span>Yachts</span><span>Trips</span></div>
            </div>
        </section>

        <div class="section-title">
            <h2>Everything the team needs</h2>
            <p>One system for the office and the road.</p>
        </div>
        <section class="features">
            <div class="feature">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h3"/></svg></div>
                <h3>Bookings &amp; handovers</h3>
                <p>Every rental from first enquiry to the car coming back, with deposits and balances tracked.</p>
            </div>
            <div class="feature">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17h14M6 17l1.5-5h9L18 17M7 17v2M17 17v2"/><circle cx="8" cy="14.5" r=".6"/><circle cx="16" cy="14.5" r=".6"/><path d="M2 21c2 0 2-1 4-1s2 1 4 1 2-1 4-1 2 1 4 1 2-1 4-1"/></svg></div>
                <h3>Cars &amp; yachts</h3>
                <p>Live status of the fleet: available, booked, on the road or in maintenance.</p>
            </div>
            <div class="feature">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18.5 20a6.5 6.5 0 0 0-3-5.5"/></svg></div>
                <h3>Customers &amp; leads</h3>
                <p>Car, yacht and B2B pipelines, quotations and customer documents together.</p>
            </div>
            <div class="feature">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/><path d="M10 9l1.5 1.5L14.5 7.5"/></svg></div>
                <h3>Drivers on the go</h3>
                <p>Drivers see their jobs on the phone and record handovers and returns with photos.</p>
            </div>
        </section>

        <footer>
            <span>&copy; {{ now()->year }} RED PRIME RENTALS</span>
            <span class="lock"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>Private system for authorised users</span>
        </footer>
    </div>
</body>
</html>
