const CACHE = 'tap-v3';
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

self.addEventListener('push', function(e) {
  var data = {};
  try { data = e.data ? e.data.json() : {}; } catch (err) { data = { title: 'Notificación', body: e.data ? e.data.text() : '' }; }
  var title = data.title || 'Travel Agency';
  var options = {
    body: data.body || '',
    icon: '/wp-content/plugins/travel-agency-platform/assets/icons/icon-192.png',
    badge: '/wp-content/plugins/travel-agency-platform/assets/icons/icon-192.png',
    data: { url: data.url || '/' }
  };
  e.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function(e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) ? e.notification.data.url : '/';
  e.waitUntil(clients.openWindow(url));
});
PY