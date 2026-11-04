const CACHE = 'tap-v1';
const ASSETS = [
  '/',
  '/wp-content/plugins/travel-agency-platform/assets/css/tokens.css',
  '/wp-content/plugins/travel-agency-platform/assets/css/public.css',
];

self.addEventListener('install', function(e) {
  e.waitUntil(caches.open(CACHE).then(function(c) { return c.addAll(ASSETS); }));
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function(e) {
  if (e.request.method !== 'GET') return;
  e.respondWith(
    caches.match(e.request).then(function(r) {
      return r || fetch(e.request).then(function(resp) {
        return resp;
      }).catch(function() {
        return caches.match('/');
      });
    })
  );
});
PY