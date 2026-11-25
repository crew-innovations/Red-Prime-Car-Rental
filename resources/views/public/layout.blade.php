<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · RED PRIME RENTALS</title>
    <link rel="icon" href="{{ asset('images/apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --red: #d7141a; --gold: #d4a62a; --ink: #18181b; --muted: #71717a; --line: #e4e4e7; --bg: #f6f5f3; --ok: #16a34a; --warn: #d97706; --bad: #dc2626; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Poppins, system-ui, sans-serif; color: var(--ink); background: var(--bg); line-height: 1.5; }
        .top { background: linear-gradient(120deg, #120607, #2a0709 55%, var(--red) 140%); color: #fff; padding: 18px 16px; }
        .top-inner { max-width: 760px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .top img { height: 42px; }
        .top .tag { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: var(--gold); font-weight: 600; }
        main { max-width: 760px; margin: 0 auto; padding: 20px 16px 48px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 20px; margin-bottom: 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 0 0 12px; }
        .muted { color: var(--muted); font-size: 14px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; border-radius: 12px; padding: 12px 18px; font: 600 15px Poppins, sans-serif; cursor: pointer; text-decoration: none; }
        .btn-primary { background: var(--red); color: #fff; }
        .btn-ghost { background: #fff; color: var(--ink); border: 1px solid var(--line); }
        .pill { display: inline-block; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px; }
        .pill.ok { background: #dcfce7; color: var(--ok); } .pill.warn { background: #fef3c7; color: var(--warn); } .pill.bad { background: #fee2e2; color: var(--bad); } .pill.info { background: #e0f2fe; color: #0369a1; }
        .alert { border-radius: 12px; padding: 12px 14px; font-size: 14px; margin-bottom: 16px; }
        .alert.ok { background: #dcfce7; color: #14532d; } .alert.bad { background: #fee2e2; color: #7f1d1d; }
        label { display: block; font-weight: 600; font-size: 14px; margin: 14px 0 6px; }
        select, input[type=file] { width: 100%; font: 15px Poppins, sans-serif; padding: 12px; border: 1px solid var(--line); border-radius: 12px; background: #fff; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        td, th { padding: 9px 0; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        td.r, th.r { text-align: right; }
        .foot { text-align: center; color: var(--muted); font-size: 12px; margin-top: 24px; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .card { border: 0; padding: 0 0 12px; }
            .top { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
    <link rel="stylesheet" href="{{ asset('css/crm.css') }}?v={{ @filemtime(public_path('css/crm.css')) }}">
</head>
<body>
    <header class="top">
        <div class="top-inner">
            <img src="{{ asset('images/logo-mark.png') }}" alt="RED PRIME RENTALS">
            <span class="tag">@yield('tag')</span>
        </div>
    </header>
    <main>
        @yield('content')
        <div class="foot">RED PRIME RENTALS</div>
    </main>
    {!! \App\Support\Uploads\CameraUpload::scriptTag() !!}
</body>
</html>
