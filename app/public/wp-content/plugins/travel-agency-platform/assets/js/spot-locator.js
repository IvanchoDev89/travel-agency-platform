(function () {
  jQuery(function ($) {
    var el = document.getElementById('tap-spot-map');
    if (!el || typeof L === 'undefined' || !window.tapSpotLocator) {
      return;
    }
    var cfg = window.tapSpotLocator;

    L.Icon.Default.mergeOptions({
      iconUrl: cfg.pluginUrl + 'assets/leaflet/marker-icon.png',
      iconRetinaUrl: cfg.pluginUrl + 'assets/leaflet/marker-icon-2x.png',
      shadowUrl: cfg.pluginUrl + 'assets/leaflet/marker-shadow.png'
    });

    var map = L.map('tap-spot-map', { scrollWheelZoom: false })
      .setView([cfg.lat, cfg.lng], cfg.zoom || 11);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    L.marker([cfg.lat, cfg.lng]).addTo(map).bindPopup(document.createTextNode(cfg.title));

    window.setTimeout(function () { map.invalidateSize(); }, 200);
  });
})();
