<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install {{ $app['name'] }}</title>
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="{{ $app['theme'] }}">
    <link rel="manifest" href="{{ url('/manifest/'.$app['key'].'.webmanifest') }}">
    <link rel="apple-touch-icon" href="{{ \App\Support\Push\Apps::iconUrl($app['key'], 'apple-touch-180.png') }}">
    <style>
        :root { --red: #d7141a; --gold: #d4a62a; --bg: #0a0908; --fg: #f5f0e8; --muted: rgba(245,240,232,.7); --card: rgba(255,255,255,.06); }
        @media (prefers-color-scheme: light) { :root { --bg: #f7f4ef; --fg: #1c1917; --muted: #57534e; --card: #fff; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg); font: 15px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
        .wrap { max-width: 520px; margin: 0 auto; padding: 28px 16px 40px; }
        .head { display: flex; align-items: center; gap: 14px; }
        .head img { width: 64px; height: 64px; border-radius: 16px; }
        h1 { font-size: 22px; margin: 0; }
        .muted { color: var(--muted); }
        .card { background: var(--card); border-radius: 16px; padding: 18px; margin-top: 18px; border: 1px solid rgba(127,127,127,.18); }
        .btn { display: block; width: 100%; border: 0; border-radius: 14px; background: var(--red); color: #fff; font-weight: 700; font-size: 16px; padding: 14px; text-align: center; text-decoration: none; cursor: pointer; }
        .btn[disabled] { opacity: .5; }
        ol { padding-left: 20px; margin: 8px 0 0; } li { margin: 6px 0; }
        .step-ic { display: inline-block; padding: 0 6px; border-radius: 6px; background: rgba(127,127,127,.18); font-weight: 600; }
        .hide { display: none; }
        .qr { text-align: center; } .qr img { width: 170px; height: 170px; background: #fff; border-radius: 12px; padding: 8px; }
        .ok { color: #16a34a; font-weight: 600; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <img src="{{ \App\Support\Push\Apps::iconUrl($app['key'], 'icon-192.png') }}" alt="">
        <div><h1>{{ $app['name'] }}</h1><div class="muted">Install the app on this phone or computer.</div></div>
    </div>

    <div class="card" id="android">
        <strong>Install</strong>
        <p class="muted" style="margin:.3rem 0 1rem">One tap adds the app icon to your home screen. It opens like a normal app and can send you notifications.</p>
        <button class="btn" id="installBtn" disabled>Install the app</button>
        <p class="muted hide" id="notReady" style="margin-top:.8rem;font-size:13px">If the button stays grey, open this page in Chrome (Android) or Chrome / Edge (computer), or use the browser menu › "Install app" / "Add to Home screen".</p>
        <p class="ok hide" id="installed">Installed. Open it from your home screen.</p>
    </div>

    <div class="card hide" id="ios">
        <strong>iPhone / iPad</strong>
        <ol>
            <li>Open this page in <b>Safari</b>.</li>
            <li>Tap the <span class="step-ic">Share</span> button (the square with an arrow).</li>
            <li>Choose <span class="step-ic">Add to Home Screen</span>, then <span class="step-ic">Add</span>.</li>
            <li>Open the app from the new icon and sign in. Allow notifications when asked.</li>
        </ol>
        <p class="muted" style="font-size:13px;margin-bottom:0">Notifications on iPhone work only from the installed app (iOS 16.4 or newer).</p>
    </div>

    <div class="card">
        <strong>After installing</strong>
        <ol>
            <li>Open the app and sign in with your email and password.</li>
            <li>Press <b>Allow notifications</b> so you never miss {{ $app['key'] === 'driver' ? 'a job' : ($app['key'] === 'customer' ? 'an update about your booking' : 'a new lead or payment') }}.</li>
        </ol>
        <a class="btn" style="margin-top:14px;background:transparent;color:var(--fg);border:1px solid rgba(127,127,127,.4)" href="{{ url($app['start']) }}">Open in the browser instead</a>
    </div>

    @if ($qr)
        <div class="card qr"><div class="muted" style="margin-bottom:8px">On a computer? Scan with the phone camera:</div><img src="{{ $qr }}" alt="QR code"></div>
    @endif
</div>
<script>
(function () {
    var ua = navigator.userAgent, ios = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
    if (ios) { document.getElementById('ios').classList.remove('hide'); document.getElementById('android').classList.add('hide'); }
    if (standalone) { document.getElementById('installed').classList.remove('hide'); document.getElementById('installBtn').classList.add('hide'); }
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js', { scope: @json($app['scope']) }).catch(function () {});
    var deferred = null, btn = document.getElementById('installBtn');
    setTimeout(function () { if (!deferred && !ios) document.getElementById('notReady').classList.remove('hide'); }, 2500);
    window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; btn.disabled = false; });
    btn.addEventListener('click', function () {
        if (!deferred) return;
        deferred.prompt();
        deferred.userChoice.then(function (r) { if (r.outcome === 'accepted') { btn.classList.add('hide'); document.getElementById('installed').classList.remove('hide'); } deferred = null; });
    });
    window.addEventListener('appinstalled', function () { btn.classList.add('hide'); document.getElementById('installed').classList.remove('hide'); });
})();
</script>
</body>
</html>
