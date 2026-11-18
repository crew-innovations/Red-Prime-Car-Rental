/* Installed app + notifications for the CRM (/crm) and the customer account (/account). 4 Nov 2026, brief 9 / 16.
 * - Registers the service worker for this app's scope.
 * - Tells the CRM about this device (installed or not, notification permission, push subscription).
 * - Shows "Allow notifications" once signed in, and a one-time "Add to Home Screen" hint on iPhone. */
(function () {
  var cfg = window.RP_PWA; if (!cfg || !('serviceWorker' in navigator)) return;
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };
  var deviceId = store.get('rp-device');
  if (!deviceId) { deviceId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(16).slice(2); store.set('rp-device', deviceId); }
  var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  var ios = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var canPush = 'PushManager' in window && 'Notification' in window && cfg.vapid;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

  function report(extra) {
    var body = Object.assign({ device_id: deviceId, standalone: standalone, version: String(cfg.version), permission: ('Notification' in window) ? Notification.permission : 'default' }, extra || {});
    return fetch(cfg.deviceUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify(body) }).catch(function () {});
  }
  function key(b64) { var p = '='.repeat((4 - b64.length % 4) % 4), s = atob((b64 + p).replace(/-/g, '+').replace(/_/g, '/')), a = new Uint8Array(s.length); for (var i = 0; i < s.length; i++) a[i] = s.charCodeAt(i); return a; }
  function subscribe(reg) {
    return reg.pushManager.getSubscription().then(function (sub) {
      return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key(cfg.vapid) });
    }).then(function (sub) { return report({ permission: 'granted', subscription: sub.toJSON() }); });
  }
  function banner(text, button, onClick, id) {
    if (document.getElementById(id)) return;
    var b = document.createElement('div'); b.id = id; b.className = 'rp-pwa-banner';
    b.innerHTML = '<span></span><button type="button"></button><button type="button" class="rp-pwa-x" aria-label="Close">×</button>';
    b.querySelector('span').textContent = text; b.querySelectorAll('button')[0].textContent = button;
    b.querySelectorAll('button')[0].onclick = function () { onClick(); b.remove(); };
    b.querySelector('.rp-pwa-x').onclick = function () { store.set(id + '-closed', String(Date.now())); b.remove(); };
    document.body.appendChild(b);
  }

  var ready = navigator.serviceWorker.register('/sw.js', { scope: cfg.scope });
  // Used by "My notifications": ask now, from a button the person tapped.
  window.RPPush = { enable: function () {
    if (!canPush) return Promise.resolve('unsupported');
    return Notification.requestPermission().then(function (p) {
      if (p !== 'granted') { report({ permission: p }); return p; }
      return ready.then(subscribe).then(function () { return 'granted'; });
    });
  } };

  ready.then(function (reg) {
    report();
    if (!canPush) return;
    if (Notification.permission === 'granted') { subscribe(reg); return; }
    if (Notification.permission === 'denied') return;
    if (ios && !standalone) return; // iPhone: push only from the installed app
    var closed = parseInt(store.get('rp-allow-closed') || '0', 10);
    if (closed && Date.now() - closed < 3 * 864e5 && !cfg.alwaysAsk) return;
    banner(cfg.texts.allow, cfg.texts.allowButton, function () {
      Notification.requestPermission().then(function (p) { if (p === 'granted') subscribe(reg); else report({ permission: p }); });
    }, 'rp-allow');
  }).catch(function () {});

  if (ios && !standalone && !store.get('rp-ios-hint')) {
    store.set('rp-ios-hint', '1');
    banner(cfg.texts.iosHint, 'OK', function () {}, 'rp-ios');
  }
})();
