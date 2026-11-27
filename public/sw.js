/*
 * Chatu Warehouse service worker. It keeps the look and the offline look-up page available without a network.
 * Signed-in pages and data are never cached here: when a page cannot load, the offline look-up opens instead,
 * which answers from the snapshot this browser saved while online.
 */
const CACHE = 'chatuwms-0.4.0';
const SHELL = ['offline.html', 'static/app.css', 'static/app.js', 'static/theme.js', 'static/offline.js', 'static/fonts/figtree.woff2',
  'static/fonts/bricolage-700.woff2', 'static/fonts/bricolage-800.woff2', 'static/brand/logo-wide.png', 'static/brand/favicon-64.png', 'static/icon-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL.map((p) => new Request(p, { cache: 'reload' })))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('chatuwms-') && k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  const scope = new URL(self.registration.scope);
  if (req.mode === 'navigate') {
    // pages: always from the network; offline → the look-up page
    e.respondWith(fetch(req).catch(() => caches.match(new URL('offline.html', scope).href)));
    return;
  }
  if (url.pathname.startsWith(scope.pathname + 'static/')) {
    // look: cached copy first, refreshed in the background
    e.respondWith(caches.open(CACHE).then((c) => c.match(req, { ignoreSearch: true }).then((hit) => {
      const net = fetch(req).then((res) => { if (res.ok) c.put(req, res.clone()); return res; }).catch(() => hit);
      return hit || net;
    })));
  }
});
