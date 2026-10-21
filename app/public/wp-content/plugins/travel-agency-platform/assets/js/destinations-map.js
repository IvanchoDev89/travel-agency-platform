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

  jQuery(function ($) {
    var $root = $('.tap-destmap');
    var $el = $('#tap-destmap');
    if (!$root.length || !$el.length || typeof L === 'undefined') {
      return;
    }

    var dataEl = document.getElementById('tap-destmap-data');
    var spots = [];
    if (dataEl && dataEl.value) {
      try { spots = JSON.parse(dataEl.value) || []; } catch (e) { spots = []; }
    }
    if (!spots.length) {
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

    var cluster = L.markerClusterGroup({ maxClusterRadius: 45, showCoverageOnHover: false });
    map.addLayer(cluster);

    var markers = {};
    var bounds = [];

    function detailHtml(spot) {
      var html = '<div class="tap-destmap-detail-inner">';
      if (spot.img) {
        html += '<div class="tap-destmap-detail-media"><img src="' + esc(spot.img) + '" alt="" loading="lazy" decoding="async"></div>';
      }
      html += '<div class="tap-destmap-detail-body">';
      if (spot.typeName) {
        html += '<span class="tap-destmap-detail-type">' + esc(spot.typeName) + '</span>';
      }
      html += '<h3 class="tap-destmap-detail-title">' + esc(spot.title) + '</h3>';
      if (spot.province) {
        html += '<p class="tap-destmap-detail-province">' + esc(spot.province) + '</p>';
      }
      if (spot.excerpt) {
        html += '<p class="tap-destmap-detail-lead">' + esc(spot.excerpt) + '</p>';
      }
      if (spot.interest && spot.interest.length) {
        html += '<h4>' + esc(i18n('interest', 'Datos de interés')) + '</h4><ul class="tap-destmap-detail-interest">';
        spot.interest.forEach(function (line) {
          html += '<li>' + esc(line) + '</li>';
        });
        html += '</ul>';
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

    function openDetail(id, scroll) {
      var spot = null;
      for (var i = 0; i < spots.length; i++) {
        if (String(spots[i].id) === String(id)) { spot = spots[i]; break; }
      }
      if (!spot) return;

      var $detail = $('#tap-destmap-detail');
      $detail.html(detailHtml(spot)).prop('hidden', false);

      $('.tap-destmap-item').removeClass('is-active');
      $('.tap-destmap-item-btn').attr('aria-expanded', 'false');
      var $item = $('.tap-destmap-item[data-spot-id="' + id + '"]');
      $item.addClass('is-active').find('.tap-destmap-item-btn').attr('aria-expanded', 'true');

      if (markers[id]) {
        map.setView(markers[id].getLatLng(), Math.max(map.getZoom(), 11), { animate: true });
        markers[id].openPopup();
      }
      if (scroll && $item.length && window.matchMedia('(max-width: 900px)').matches) {
        $('html, body').animate({ scrollTop: $detail.offset().top - 20 }, 200);
      }
    }

    spots.forEach(function (spot) {
      var lat = parseFloat(spot.lat);
      var lng = parseFloat(spot.lng);
      if (isNaN(lat) || isNaN(lng)) return;
      bounds.push([lat, lng]);

      var marker = L.marker([lat, lng]);
      var popup =
        '<div class="tap-destmap-popup">' +
          (spot.img ? '<img class="tap-destmap-popup-img" src="' + esc(spot.img) + '" alt="" loading="lazy">' : '') +
          '<span class="tap-destmap-popup-type">' + esc(spot.typeName || '') + '</span>' +
          '<a class="tap-destmap-popup-title" href="' + esc(spot.url) + '">' + esc(spot.title) + '</a>' +
          '<button type="button" class="tap-destmap-popup-btn" data-spot-id="' + esc(spot.id) + '">' + esc(i18n('interest', 'Datos de interés')) + '</button>' +
        '</div>';
      marker.bindPopup(popup, { minWidth: 200 });
      marker.on('popupopen', function () {
        $('.tap-destmap-popup-btn[data-spot-id="' + spot.id + '"]').one('click', function () {
          openDetail(spot.id, true);
        });
      });
      markers[spot.id] = marker;
      cluster.addLayer(marker);
    });

    if (bounds.length) {
      map.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 });
    }

    $(document).on('click', '.tap-destmap-item-btn', function () {
      openDetail($(this).closest('.tap-destmap-item').data('spot-id'), false);
    });

    window.setTimeout(function () { map.invalidateSize(); }, 200);
  });
})();
