/* Travel Agency Platform service worker for push notifications. */
self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'Travel Agency', body: event.data ? event.data.text() : '' }; }
  var title = data.title || 'Travel Agency';
  var options = {
    body: data.body || '',
    icon: (self.registration && self.registration.scope) ? self.registration.scope + 'assets/leaflet/marker-icon.png' : '',
    badge: (self.registration && self.registration.scope) ? self.registration.scope + 'assets/leaflet/marker-icon.png' : '',
    data: { url: data.url || '/' }
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var c = clientList[i];
        if (c.url === url && 'focus' in c) return c.focus();
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});
