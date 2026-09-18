(function() {
  function tapMapEsc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

jQuery(function($) {
  var tapMap = {
    map: null,
    cluster: null,
    markers: [],

    init: function() {
      var self = this;
      var $toggle = $('.tap-view-btn[data-view="map"]');
      if (!$toggle.length || !$('#tap-map').length) return;

      $toggle.on('click', function() {
        self.setView('map');
      });

      // Also allow clicking from grid/list
      $('#tap-map').on('click', '.tap-map-close', function() {
        self.setView($('input[name="view"]').val() === 'list' ? 'list' : 'grid');
      });
    },

    setView: function(view) {
      if (view === 'map') {
        $('.tap-view-btn').removeClass('active');
        $('#tap-map-wrap').show();
        $('.tap-acc-archive-grid').hide();
        this.render();
        // Map is a transient view; don't persist it to the filter form
        $('input[name="view"]').val('grid');
      } else {
        $('.tap-view-btn').removeClass('active');
        $('.tap-view-btn[data-view="' + view + '"]').addClass('active');
        $('input[name="view"]').val(view);
        $('.tap-acc-archive-grid').toggleClass('tap-archive-list', view === 'list');
        $('#tap-map-wrap').hide();
        $('.tap-acc-archive-grid').show();
      }
    },

    getData: function() {
      var el = document.getElementById('tap-map-data');
      if (!el || !el.value) return [];
      try { return JSON.parse(el.value); } catch (e) { return []; }
    },

    render: function() {
      var self = this;
      var items = this.getData();
      var hasCoords = items.some(function(i) { return i.lat && i.lng; });
      var center = [ -16.5, -68.15 ];

      if (!this.map) {
        this.map = L.map('tap-map').setView(center, hasCoords ? 13 : 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; OpenStreetMap contributors'
        }).addTo(this.map);

        this.cluster = L.markerClusterGroup({
          maxClusterRadius: 40,
          showCoverageOnHover: false,
          spiderfyOnMaxZoom: true
        });
        this.map.addLayer(this.cluster);

        // Ensure markers referenced by default icon images resolve
        L.Icon.Default.mergeOptions({
          iconUrl: tapMapData.pluginUrl + 'assets/leaflet/marker-icon.png',
          iconRetinaUrl: tapMapData.pluginUrl + 'assets/leaflet/marker-icon-2x.png',
          shadowUrl: tapMapData.pluginUrl + 'assets/leaflet/marker-shadow.png'
        });
      } else {
        this.cluster.clearLayers();
        this.markers = [];
      }

      var hasAny = false;
      var bounds = [];
      $.each(items, function(i, item) {
        var lat = parseFloat(item.lat);
        var lng = parseFloat(item.lng);
        if (isNaN(lat) || isNaN(lng)) return;
        hasAny = true;
        bounds.push([lat, lng]);

        var marker = L.marker([lat, lng]);
        var popup =
          '<div class="tap-map-popup">' +
            (item.img ? '<img class="tap-map-popup-img" src="' + tapMapEsc(item.img) + '" alt="" loading="lazy" decoding="async">' : '<div class="tap-map-popup-img-placeholder">🏨</div>') +
            '<div class="tap-map-popup-info">' +
              '<a class="tap-map-popup-title" href="' + tapMapEsc(item.url) + '">' + tapMapEsc(item.title) + '</a>' +
              (item.city ? '<span class="tap-map-popup-city">' + tapMapEsc(item.city) + '</span>' : '') +
              '<span class="tap-map-popup-price">$' + tapMapEsc(item.price) + ' <small>/ noche</small></span>' +
              '<a class="tap-map-popup-cta" href="' + tapMapEsc(item.url) + '">Ver</a>' +
            '</div>' +
          '</div>';
        marker.bindPopup(popup, { minWidth: 220 });
        self.cluster.addLayer(marker);
      });

      if (hasAny && bounds.length) {
        this.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
      } else {
        this.map.setView(center, 6);
      }
    }
  };

  tapMap.init();
});
})();
