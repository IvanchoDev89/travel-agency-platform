(function () {
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function i18n(key, fallback) {
    var d = window.tapDestsMap && window.tapDestsMap.i18n;
    return (d && d[key]) || fallback;
  }

  function parseJson(id) {
    var el = document.getElementById(id);
    if (!el || !el.value) return [];
    try { return JSON.parse(el.value) || []; } catch (e) { return []; }
  }

  jQuery(function ($) {
    var $root = $('.tap-destmap');
    var $el = $('#tap-destmap');
    if (!$root.length || !$el.length || typeof L === 'undefined') {
      return;
    }

    var spots = parseJson('tap-destmap-data');
    var services = parseJson('tap-destmap-services');

    if (!spots.length && !services.length) {
      return;
    }

    var centerRaw = ($root.data('center') || '9.7489,-83.7534').toString().split(',');
    var center = [parseFloat(centerRaw[0]) || 9.7489, parseFloat(centerRaw[1]) || -83.7534];
    var zoom = parseInt($root.data('zoom'), 10) || 8;

    L.Icon.Default.mergeOptions({
      iconUrl: window.tapDestsMap.pluginUrl + 'assets/leaflet/marker-icon.png',
      iconRetinaUrl: window.tapDestsMap.pluginUrl + 'assets/leaflet/marker-icon-2x.png',
      shadowUrl: window.tapDestsMap.pluginUrl + 'assets/leaflet/marker-shadow.png'
    });

    var map = L.map('tap-destmap', { scrollWheelZoom: false }).setView(center, zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    var spotCluster = L.markerClusterGroup({ maxClusterRadius: 45, showCoverageOnHover: false });
    var serviceCluster = L.markerClusterGroup({ maxClusterRadius: 45, showCoverageOnHover: false });
    map.addLayer(spotCluster);
    map.addLayer(serviceCluster);

    var spotMarkers = {};
    var serviceMarkers = {};
    var bounds = [];

    /* State: 'all' | 'spots' | 'services' */
    var layer = 'all';
    var serviceFilter = '';

    function pinIcon(kind, type, label) {
      var cls = 'tap-destmap-pin tap-destmap-pin--' + kind;
      if (type) cls += ' tap-destmap-pin--' + type;
      return L.divIcon({
        className: 'tap-destmap-pin-wrap',
        html: '<span class="' + cls + '" aria-hidden="true"></span>',
        iconSize: [20, 20],
        iconAnchor: [10, 10],
        popupAnchor: [0, -8]
      });
    }

    /* ------------------------------------------------------------------ */
    /* Detail panels                                                       */
    /* ------------------------------------------------------------------ */

    function spotDetailHtml(spot) {
      var html = '<div class="tap-destmap-detail-inner">';
      if (spot.img) {
        html += '<div class="tap-destmap-detail-media"><img src="' + esc(spot.img) + '" alt="" loading="lazy" decoding="async"></div>';
      }
      html += '<div class="tap-destmap-detail-body">';
      if (spot.typeName) {
        html += '<span class="tap-destmap-detail-type">' + esc(spot.typeName) + '</span>';
      }
      html += '<h3 class="tap-destmap-detail-title">' + esc(spot.title) + '</h3>';

      var tags = [];
      if (spot.province) tags.push(spot.province);
      if (spot.region) tags.push(spot.region);
      if (spot.altitude) tags.push(spot.altitude);
      if (tags.length) {
        html += '<p class="tap-destmap-detail-province">' + esc(tags.join(' · ')) + '</p>';
      }
      if (spot.excerpt) {
        html += '<p class="tap-destmap-detail-lead">' + esc(spot.excerpt) + '</p>';
      }
      if (spot.ecosystem || spot.bestSeason) {
        html += '<dl class="tap-destmap-detail-facts">';
        if (spot.ecosystem) {
          html += '<dt>' + esc(i18n('ecosystem', 'Ecosistema')) + '</dt><dd>' + esc(spot.ecosystem) + '</dd>';
        }
        if (spot.bestSeason) {
          html += '<dt>' + esc(i18n('bestSeason', 'Mejor época')) + '</dt><dd>' + esc(spot.bestSeason) + '</dd>';
        }
        html += '</dl>';
      }

      var lists = [
        ['interest', spot.interest],
        ['biodiversity', spot.biodiversity],
        ['activities', spot.activities],
        ['tips', spot.tips]
      ];
      lists.forEach(function (pair) {
        if (!pair[1] || !pair[1].length) return;
        html += '<h4>' + esc(i18n(pair[0], pair[0])) + '</h4><ul class="tap-destmap-detail-interest tap-destmap-detail-list">';
        pair[1].forEach(function (line) {
          html += '<li>' + esc(line) + '</li>';
        });
        html += '</ul>';
      });

      if (spot.conservation) {
        html += '<h4>' + esc(i18n('conservation', 'Conservación')) + '</h4>';
        html += '<p class="tap-destmap-detail-text">' + esc(spot.conservation) + '</p>';
      }

      var facts = [
        ['schedule', spot.schedule],
        ['fee', spot.fee],
        ['area', spot.area],
        ['created', spot.created],
        ['accessibility', spot.accessibility]
      ];
      var hasFacts = facts.some(function (f) { return f[1]; });
      if (hasFacts) {
        html += '<h4>' + esc(i18n('practical', 'Ficha práctica')) + '</h4>';
        html += '<dl class="tap-destmap-detail-facts">';
        facts.forEach(function (f) {
          if (!f[1]) return;
          html += '<dt>' + esc(i18n(f[0], f[0])) + '</dt><dd>' + esc(f[1]) + '</dd>';
        });
        html += '</dl>';
      }
      html += '<a class="tap-btn tap-btn-primary tap-destmap-detail-cta" href="' + esc(spot.url) + '">' + esc(i18n('visit', 'Ver ficha completa')) + '</a>';
      html += '</div></div>';
      return html;
    }

    function serviceDetailHtml(service) {
      var html = '<div class="tap-destmap-detail-inner">';
      if (service.img) {
        html += '<div class="tap-destmap-detail-media"><img src="' + esc(service.img) + '" alt="" loading="lazy" decoding="async"></div>';
      }
      html += '<div class="tap-destmap-detail-body">';
      html += '<span class="tap-destmap-detail-type tap-destmap-detail-type--service">' + esc(service.typeName) + '</span>';
      html += '<h3 class="tap-destmap-detail-title">' + esc(service.title) + '</h3>';
      if (service.city) {
        html += '<p class="tap-destmap-detail-province">' + esc(service.city) + '</p>';
      }
      if (service.price) {
        html += '<p class="tap-destmap-detail-price">' + esc(service.price) + '</p>';
      }
      if (service.ratingCount > 0) {
        html += '<p class="tap-destmap-detail-rating">' +
          '<span class="tap-destmap-star" aria-hidden="true">★</span> ' +
          esc(String(service.ratingAvg).replace('.', ',')) +
          ' <span class="tap-destmap-detail-rating-count">(' + esc(String(service.ratingCount)) + ')</span>' +
          '</p>';
      }
      if (service.approx) {
        html += '<p class="tap-destmap-detail-approx">' + esc(i18n('approx', 'Ubicación aproximada según su destino.')) + '</p>';
      }
      html += '<a class="tap-btn tap-btn-primary tap-destmap-detail-cta" href="' + esc(service.url) + '">' + esc(i18n('viewService', 'Ver servicio')) + '</a>';
      html += '</div></div>';
      return html;
    }

    /* ------------------------------------------------------------------ */
    /* Detail opening                                                      */
    /* ------------------------------------------------------------------ */

    function openDetail(kind, id, scroll) {
      var isService = kind === 'service';
      var items = isService ? services : spots;
      var item = null;
      for (var i = 0; i < items.length; i++) {
        if (String(items[i].id) === String(id)) { item = items[i]; break; }
      }
      if (!item) return;

      var $detail = $('#tap-destmap-detail');
      $detail.html(isService ? serviceDetailHtml(item) : spotDetailHtml(item)).prop('hidden', false);

      $('.tap-destmap-item').removeClass('is-active');
      $('.tap-destmap-item-btn').attr('aria-expanded', 'false');
      var $item = $('.tap-destmap-item[data-item-id="' + id + '"][data-item-kind="' + kind + '"]');
      $item.addClass('is-active').find('.tap-destmap-item-btn').attr('aria-expanded', 'true');

      var marker = isService ? serviceMarkers[id] : spotMarkers[id];
      if (marker) {
        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 11), { animate: true });
        marker.openPopup();
      }
      if (scroll && $item.length && window.matchMedia('(max-width: 900px)').matches) {
        $('html, body').animate({ scrollTop: $detail.offset().top - 20 }, 200);
      }
    }

    /* ------------------------------------------------------------------ */
    /* Markers                                                             */
    /* ------------------------------------------------------------------ */

    spots.forEach(function (spot) {
      var lat = parseFloat(spot.lat);
      var lng = parseFloat(spot.lng);
      if (isNaN(lat) || isNaN(lng)) return;
      bounds.push([lat, lng]);

      var marker = L.marker([lat, lng], { icon: pinIcon('spot', spot.type, spot.title) });
      var popup =
        '<div class="tap-destmap-popup">' +
          (spot.img ? '<img class="tap-destmap-popup-img" src="' + esc(spot.img) + '" alt="" loading="lazy">' : '') +
          '<span class="tap-destmap-popup-type">' + esc(spot.typeName || '') + '</span>' +
          '<a class="tap-destmap-popup-title" href="' + esc(spot.url) + '">' + esc(spot.title) + '</a>' +
          '<button type="button" class="tap-destmap-popup-btn" data-item-kind="spot" data-item-id="' + esc(spot.id) + '">' + esc(i18n('interest', 'Datos de interés')) + '</button>' +
        '</div>';
      marker.bindPopup(popup, { minWidth: 200 });
      marker.on('popupopen', function () {
        $('.tap-destmap-popup-btn[data-item-kind="spot"][data-item-id="' + spot.id + '"]').one('click', function () {
          openDetail('spot', spot.id, true);
        });
      });
      spotMarkers[spot.id] = marker;
      spotCluster.addLayer(marker);
    });

    services.forEach(function (service) {
      var lat = parseFloat(service.lat);
      var lng = parseFloat(service.lng);
      if (isNaN(lat) || isNaN(lng)) return;
      bounds.push([lat, lng]);

      var marker = L.marker([lat, lng], { icon: pinIcon('service', service.type, service.title) });
      var popup =
        '<div class="tap-destmap-popup tap-destmap-popup--service">' +
          (service.img ? '<img class="tap-destmap-popup-img" src="' + esc(service.img) + '" alt="" loading="lazy">' : '') +
          '<span class="tap-destmap-popup-type">' + esc(service.typeName || '') + '</span>' +
          '<a class="tap-destmap-popup-title" href="' + esc(service.url) + '">' + esc(service.title) + '</a>' +
          (service.price ? '<span class="tap-destmap-popup-price">' + esc(service.price) + '</span>' : '') +
          '<button type="button" class="tap-destmap-popup-btn" data-item-kind="service" data-item-id="' + esc(service.id) + '">' + esc(i18n('details', 'Ver detalles')) + '</button>' +
        '</div>';
      marker.bindPopup(popup, { minWidth: 200 });
      marker.on('popupopen', function () {
        $('.tap-destmap-popup-btn[data-item-kind="service"][data-item-id="' + service.id + '"]').one('click', function () {
          openDetail('service', service.id, true);
        });
      });
      serviceMarkers[service.id] = marker;
      serviceCluster.addLayer(marker);
    });

    if (bounds.length) {
      map.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 });
    }

    /* ------------------------------------------------------------------ */
    /* Layer / tab / filter switching                                      */
    /* ------------------------------------------------------------------ */

    function syncVisibility() {
      var showSpots = layer === 'all' || layer === 'spots';
      var showServices = layer === 'all' || layer === 'services';

      if (showSpots) { if (!map.hasLayer(spotCluster)) spotCluster.addTo(map); }
      else { if (map.hasLayer(spotCluster)) map.removeLayer(spotCluster); }

      if (showServices) { if (!map.hasLayer(serviceCluster)) serviceCluster.addTo(map); }
      else { if (map.hasLayer(serviceCluster)) map.removeLayer(serviceCluster); }

      $('.tap-destmap-item[data-item-kind="spot"]').closest('.tap-destmap-list').toggle(showSpots);
      $('.tap-destmap-item[data-item-kind="service"]').closest('.tap-destmap-list').toggle(showServices);

      $('.tap-destmap-filters--spots').toggle(showSpots);
      $('.tap-destmap-filters--services').toggle(showServices);

      $('.tap-destmap-tab').each(function () {
        var tab = $(this).data('tab');
        var active = (layer === 'all') ? true : tab === layer;
        $(this).toggleClass('is-active', active).attr('aria-selected', active ? 'true' : 'false');
      });

      $('.tap-destmap-layer').each(function () {
        $(this).toggleClass('is-active', $(this).data('layer') === layer);
      });

      var visible = showSpots ? spots.length : 0;
      if (layer === 'services') visible = services.length;
      if (layer === 'all') visible = spots.length + services.length;
      $('#tap-destmap-count').text(String(visible));
    }

    function applyServiceFilter() {
      var shown = 0;
      $('.tap-destmap-item[data-item-kind="service"]').each(function () {
        var type = String($(this).data('service-type') || '');
        var match = !serviceFilter || type === serviceFilter;
        $(this).toggle(match);
        if (match) shown++;
      });
      $('#tap-destmap-service-count').text(String(shown));
      $('.tap-destmap-chip--service').each(function () {
        var active = $(this).data('service-type') === serviceFilter;
        $(this).toggleClass('is-active', active).attr('aria-current', active ? 'true' : 'false');
      });
    }

    $(document).on('click', '.tap-destmap-item-btn', function () {
      var $item = $(this).closest('.tap-destmap-item');
      openDetail($item.data('item-kind'), $item.data('item-id'), false);
    });

    $(document).on('click', '.tap-destmap-layer', function () {
      layer = String($(this).data('layer') || 'all');
      syncVisibility();
      $('#tap-destmap-detail').prop('hidden', true);
      window.setTimeout(function () { map.invalidateSize(); }, 60);
    });

    $(document).on('click', '.tap-destmap-tab', function () {
      var tab = String($(this).data('tab') || 'spots');
      layer = tab;
      syncVisibility();
      $('#tap-destmap-detail').prop('hidden', true);
      window.setTimeout(function () { map.invalidateSize(); }, 60);
    });

    $(document).on('click', '.tap-destmap-chip--service', function (e) {
      e.preventDefault();
      var type = String($(this).data('service-type') || '');
      serviceFilter = (serviceFilter === type) ? '' : type;
      applyServiceFilter();
    });

    syncVisibility();
    applyServiceFilter();

    window.setTimeout(function () { map.invalidateSize(); }, 200);
  });
})();
