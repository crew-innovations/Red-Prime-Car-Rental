/* Driver phone helpers (4 Nov 2026, brief 15).
 * - act(): sends a job step / "running late" with its own id. Without signal it is kept on the phone
 *   and sent again automatically; the server does each id only once, so nothing happens twice.
 * - track(): shares the location while a job is active, only when the office switched it on. */
(function () {
  if (window.RPDriver) return;
  var KEY = 'rp-driver-queue';
  var T = window.RP_DRIVER_TEXT || {};
  var csrf = function () { return (document.querySelector('meta[name="csrf-token"]') || {}).content; };
  var load = function () { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } };
  var save = function (q) { try { localStorage.setItem(KEY, JSON.stringify(q)); } catch (e) {} };
  var uuid = function () { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2); };

  function toast(text, kind) {
    var t = document.createElement('div');
    t.className = 'rp-dv-toast ' + (kind || '');
    t.textContent = text;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('out'); }, 3200);
    setTimeout(function () { t.remove(); }, 3800);
  }

  function post(item) {
    return fetch('/crm/driver/jobs/' + item.job + '/action', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify(Object.assign({ action: item.action, client_action_id: item.id }, item.data || {}))
    }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, body: j }; }); });
  }

  var flushing = false;
  function flush() {
    if (flushing || !navigator.onLine) return Promise.resolve();
    var q = load();
    if (!q.length) return Promise.resolve();
    flushing = true;
    var sent = 0;
    var next = function () {
      var item = load()[0];
      if (!item) return Promise.resolve();
      return post(item).then(function (res) {
        if (res.status >= 500 || res.status === 419 || res.status === 401) throw new Error('retry');
        save(load().filter(function (x) { return x.id !== item.id; })); // done, a duplicate, or refused: never send again
        if (res.status === 200) sent++; else if (res.body && res.body.message) toast(res.body.message, 'bad');
        return next();
      });
    };
    return next().catch(function () {}).then(function () {
      flushing = false;
      if (sent) { toast(T.sent || 'Sent', 'ok'); if (window.Livewire) document.querySelectorAll('[wire\\:id]').forEach(function (el) { try { Livewire.find(el.getAttribute('wire:id')).$refresh(); } catch (e) {} }); }
    });
  }

  function act(job, action, data) {
    var item = { id: uuid(), job: job, action: action, data: data || {}, at: Date.now() };
    return post(item).then(function (res) {
      if (res.status === 200) { toast(T.saved || 'Saved', 'ok'); return res.body; }
      if (res.status === 422 || res.status === 403 || res.status === 404) { toast((res.body && res.body.message) || T.not_saved || 'Not saved', 'bad'); return res.body; }
      throw new Error('retry');
    }).catch(function () {
      var q = load(); q.push(item); save(q);
      toast(T.queued || 'No signal: saved on your phone. It will be sent automatically.', 'warn');
      return { queued: true };
    });
  }

  var watch = null, lastSent = 0;
  function track(cfg) {
    var box = document.getElementById('rp-share');
    if (watch !== null) { navigator.geolocation.clearWatch(watch); watch = null; }
    if (!cfg || !cfg.share || !navigator.geolocation) return;
    var every = Math.max(15, cfg.interval || 60) * 1000;
    watch = navigator.geolocation.watchPosition(function (p) {
      if (Date.now() - lastSent < every) return;
      lastSent = Date.now();
      fetch('/crm/driver/location', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ job: cfg.job, lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy, speed: p.coords.speed, at: p.timestamp })
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j && (j.off || j.active === false)) { navigator.geolocation.clearWatch(watch); watch = null; if (box) box.hidden = true; }
      }).catch(function () {});
    }, function () {
      if (box) { box.querySelector('[data-ok]').hidden = true; box.querySelector('[data-denied]').hidden = false; box.classList.add('is-off'); }
    }, { enableHighAccuracy: true, maximumAge: 15000, timeout: 30000 });
  }

  window.RPDriver = { act: act, flush: flush, track: track, pending: function () { return load().length; } };
  window.addEventListener('online', flush);
  setInterval(flush, 20000);
  document.addEventListener('DOMContentLoaded', flush);
})();
