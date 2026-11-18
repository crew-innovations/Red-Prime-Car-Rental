/* RED PRIME RENTALS service worker (4 Nov 2026, brief 16.3).
 * - Shows push notifications and opens the right page when one is tapped.
 * - Caches only the app shell (styles, scripts, fonts, icons) and an offline page.
 *   Pages, API answers and anything with personal data or money are never cached. */
const VERSION = 'rp-{{ $version }}';
const SHELL = ['/pwa/offline'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

const isStatic = (url) => url.origin === self.location.origin && /^\/(css|js|fonts|images|favicon|vendor\/filament|filament|livewire)\b/.test(url.pathname) && !url.search.includes('token');

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (req.mode === 'navigate') {
    // Always from the network; the offline page only when there is no connection.
    event.respondWith(fetch(req).catch(() => caches.match('/pwa/offline')));
    return;
  }
  if (isStatic(url)) {
    event.respondWith(caches.open(VERSION).then((cache) => cache.match(req).then((hit) => {
      const net = fetch(req).then((res) => { if (res.ok && res.type === 'basic') cache.put(req, res.clone()); return res; }).catch(() => hit);
      return hit || net;
    })));
  }
});

self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: event.data ? event.data.text() : 'RED PRIME RENTALS' }; }
  const title = data.title || 'RED PRIME RENTALS';
  // 5 Nov (S3): a new lead carries an Accept button (where the phone shows buttons) and its own
  // accept link; "Taken by …" replaces it quietly (same tag).
  event.waitUntil(self.registration.showNotification(title, {
    body: data.body || '',
    tag: data.tag || undefined,
    renotify: !!data.tag && !data.silent,
    silent: !!data.silent,
    icon: '/images/apple-touch-icon.png',
    badge: '/favicon.png',
    actions: Array.isArray(data.actions) ? data.actions.slice(0, 2) : [],
    data: { url: data.url || self.registration.scope, claim: data.claim || null },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const d = event.notification.data || {};
  if (event.action === 'accept' && d.claim) {
    // Accept straight from the notification: the agent's own single-use link, no login needed.
    event.waitUntil(fetch(d.claim, { method: 'POST', headers: { 'Accept': 'application/json' } })
      .then((r) => r.json().catch(() => ({})))
      .then((res) => self.registration.showNotification(
        res.result === 'won' ? 'The lead is yours' : (res.owner ? 'Taken by ' + res.owner : 'Could not accept this lead'),
        { body: res.result === 'won' ? 'Open it and call the customer now.' : '', tag: event.notification.tag, icon: '/images/apple-touch-icon.png', data: { url: res.url || d.url } }))
      .catch(() => self.clients.openWindow(d.url)));
    return;
  }
  const target = d.url || self.registration.scope;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
    for (const w of wins) { if (w.url.startsWith(self.registration.scope) && 'focus' in w) { w.navigate(target); return w.focus(); } }
    return self.clients.openWindow(target);
  }));
});
