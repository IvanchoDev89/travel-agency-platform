const CACHE = 'tap-v2';
const ASSETS = [
  '/',
  '/offline.html',
  '/wp-content/plugins/travel-agency-platform/assets/css/tokens.css',
  '/wp-content/plugins/travel-agency-platform/assets/css/public.css',
];

self.addEventListener('install', function(e) {
  e.waitUntil(caches.open(CACHE).then(function(c) { return c.addAll(ASSETS); }));
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(keys.filter(function(k){ return k !== CACHE; }).map(function(k){ return caches.delete(k); }));
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function(e) {
  if (e.request.method !== 'GET') return;
  e.respondWith(
    fetch(e.request).then(function(resp) {
      var copy = resp.clone();
      caches.open(CACHE).then(function(c) { c.put(e.request, copy); });
      return resp;
    }).catch(function() {
      return caches.match(e.request).then(function(r) {
        return r || caches.match('/offline.html');
      });
    })
  );
});
PY