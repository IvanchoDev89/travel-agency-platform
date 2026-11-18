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

  function urlParam(name) {
    try { return new URLSearchParams(window.location.search).get(name) || ''; }
    catch (e) { return ''; }
  }

  /** Diacritic-insensitive search: "volcan" matches "Volcán". */
  function norm(s) {
    return String(s == null ? '' : s).trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  }

  /** Update the URL without a reload so the current view can be shared. */
  function setUrlParams(patch) {
    try {
      var p = new URLSearchParams(window.location.search);
      Object.keys(patch).forEach(function (key) {
        var value = patch[key];
        if (value) { p.set(key, value); } else { p.delete(key); }
      });
      var qs = p.toString();
      window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
    } catch (e) { /* old browser: state simply is not shareable */ }
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
    var pluginUrl = (window.tapDestsMap && window.tapDestsMap.pluginUrl) || '';

    L.Icon.Default.mergeOptions({
      iconUrl: pluginUrl + 'assets/leaflet/marker-icon.png',
      iconRetinaUrl: pluginUrl + 'assets/leaflet/marker-icon-2x.png',
      shadowUrl: pluginUrl + 'assets/leaflet/marker-shadow.png'
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

    /* Shareable state: layer | spots | services, service type, search text. */
    var requestedLayer = urlParam('layer');
    var state = {
      layer: ['all', 'spots', 'services'].indexOf(requestedLayer) !== -1 ? requestedLayer : 'all',
      serviceFilter: urlParam('service_type'),
      q: norm(urlParam('q')).substring(0, 80)
    };

    var ITIN_KEY = 'tap_itin_v1';
    var lastFocus = null;

    function pinIcon(kind, type, label) {
      var cls = 'tap-destmap-pin tap-destmap-pin--' + kind;
      if (type) cls += ' tap-destmap-pin--' + type;
      return L.divIcon({
        className: 'tap-destmap-pin-wrap',
        html: '<span class="' + cls + '" aria-hidden="true"></span>',
        iconSize: [28, 28],
        iconAnchor: [14, 14],
        popupAnchor: [0, -12]
      });
    }

    function toast(message) {
      var $toast = $('#tap-destmap-toast');
      if (!$toast.length) return;
      $toast.text(message).addClass('is-visible');
      clearTimeout($toast.data('t'));
      $toast.data('t', setTimeout(function () { $toast.removeClass('is-visible'); }, 2400));
    }

    /** Booking deep link: the service page already renders [tap_booking_form]. */
    function bookingHref(service) {
      var url = service.url || '#';
      var ref = urlParam('ref');
      if (ref) {
        url += (url.indexOf('?') === -1 ? '?' : '&') + 'ref=' + encodeURIComponent(ref);
      }
      return url + (url.indexOf('#') === -1 ? '#tap-booking-widget' : '');
    }

    /** Destination archive (never the home page). PHP supplies the real term link. */
    function destinationHref(slug) {
      var home = (window.tapDestsMap && window.tapDestsMap.homeUrl) || '/';
      return home.replace(/\/?$/, '/') + 'destination/' + encodeURIComponent(slug) + '/';
    }

    /* ------------------------------------------------------------------ */
    /* Itinerary (same localStorage shape as itinerary.js)                  */
    /* ------------------------------------------------------------------ */

    function readItinerary() {
      try { return JSON.parse(localStorage.getItem(ITIN_KEY)) || {}; }
      catch (e) { return {}; }
    }

    function itineraryHas(id, type) {
      var data = readItinerary();
      return (data.picks || []).some(function (p) {
        return String(p.id) === String(id) && p.type === type;
      });
    }

    function toggleItinerary($btn) {
      var id = $btn.attr('data-itinerary-id');
      var type = $btn.attr('data-itinerary-type') || '';
      var title = $btn.attr('data-itinerary-title') || '';
      var priceLabel = $btn.attr('data-itinerary-label') || '';
      var data = readItinerary();
      data.picks = data.picks || [];
      var wasAdded = itineraryHas(id, type);
      if (wasAdded) {
        data.picks = data.picks.filter(function (p) {
          return !(String(p.id) === String(id) && p.type === type);
        });
      } else {
        data.picks.push({
          id: id,
          type: type,
          title: title,
          price: String(priceLabel).replace(/[^0-9.,-]/g, '') || '0',
          priceLabel: priceLabel
        });
      }
      try { localStorage.setItem(ITIN_KEY, JSON.stringify(data)); } catch (e) { /* private mode */ }

      var label = i18n(wasAdded ? 'addItin' : 'addedItin', wasAdded ? 'Agregar al itinerario' : 'Agregado al itinerario');
      $('.tap-destmap-itin-btn').each(function () {
        if ($(this).attr('data-itinerary-id') === String(id) && $(this).attr('data-itinerary-type') === type) {
          $(this).text(label).toggleClass('is-added', !wasAdded);
        }
      });
      toast(i18n(wasAdded ? 'removedItin' : 'addedItin', wasAdded ? 'Quitado del itinerario' : 'Agregado al itinerario'));
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
        html += '<div class="tap-destmap-detail-facts">';
        facts.forEach(function (f) {
          if (!f[1]) return;
          html += '<span class="tap-fact-chip"><dt>' + esc(i18n(f[0], f[0])) + '</dt><dd>' + esc(f[1]) + '</dd></span>';
        });
        html += '</div>';
      }

      /* Primary conversion bridge: the educational ficha itself. */
      html += '<a class="tap-btn tap-btn-primary tap-destmap-detail-cta" href="' + esc(spot.url) + '">' +
        esc(i18n('visit', 'Ver ficha completa')) + '</a>';
      html += '<div class="tap-destmap-detail-actions">';
      if (spot.destinationSlug) {
        var servicesHref = spot.destinationUrl || destinationHref(spot.destinationSlug);
        html += '<a class="tap-destmap-detail-link" href="' + esc(servicesHref) + '">' +
          esc(i18n('servicesHere', 'Ver servicios en este destino')) + ' &rarr;</a>';
      }
      html += '</div>';
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

      /* Primary conversion: straight to the real booking form anchor. */
      html += '<a class="tap-btn tap-btn-primary tap-destmap-detail-cta" href="' + esc(bookingHref(service)) + '">' +
        esc(i18n('book', 'Reservar / Cotizar')) + '</a>';
      html += '<div class="tap-destmap-detail-actions">';
      html += '<a class="tap-destmap-detail-link" href="' + esc(service.url) + '">' + esc(i18n('viewService', 'Ver servicio')) + '</a>';
      var inItin = itineraryHas(service.id, service.type);
      html += '<button type="button" class="tap-destmap-detail-link tap-destmap-itin-btn' + (inItin ? ' is-added' : '') + '"' +
        ' data-itinerary-id="' + esc(service.id) + '"' +
        ' data-itinerary-type="' + esc(service.type) + '"' +
        ' data-itinerary-title="' + esc(service.title) + '"' +
        ' data-itinerary-label="' + esc(service.price || '') + '">' +
        esc(i18n(inItin ? 'addedItin' : 'addItin', inItin ? 'En el itinerario ✓' : 'Agregar al itinerario')) +
        '</button>';
      html += '</div>';
      html += '</div></div>';
      return html;
    }

    /* ------------------------------------------------------------------ */
    /* Detail opening                                                      */
    /* ------------------------------------------------------------------ */

    function openModal() {
      var $m = $('#tap-destmap-modal');
      if ($m.is(':visible')) return;
      lastFocus = document.activeElement;
      $m.removeAttr('hidden').attr('aria-hidden', 'false');
      $('body').css('overflow', 'hidden');
      $m.find('.tap-destmap-modal-close').trigger('focus');
    }
    function closeModal() {
      var $m = $('#tap-destmap-modal');
      if (!$m.length || $m.is(':hidden')) return;
      $m.attr('hidden', 'hidden').attr('aria-hidden', 'true');
      $('body').css('overflow', '');
      setUrlParams({ spot: '', service: '' });
      if (lastFocus && lastFocus.focus) {
        try { lastFocus.focus(); } catch (e) {}
      }
    }

    function openDetail(kind, id) {
      var isService = kind === 'service';
      var items = isService ? services : spots;
      var item = null;
      for (var i = 0; i < items.length; i++) {
        if (String(items[i].id) === String(id)) { item = items[i]; break; }
      }
      if (!item) return;

      var $detail = $('#tap-destmap-detail');
      $detail.removeAttr('hidden').html(isService ? serviceDetailHtml(item) : spotDetailHtml(item));

      $('.tap-destmap-item').removeClass('is-active');
      $('.tap-destmap-item-btn').attr('aria-expanded', 'false');
      var $item = $('.tap-destmap-item[data-item-id="' + id + '"][data-item-kind="' + kind + '"]');
      $item.addClass('is-active').find('.tap-destmap-item-btn').attr('aria-expanded', 'true');
      if ($item.length) {
        try { $item[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) {}
      }

      var marker = isService ? serviceMarkers[id] : spotMarkers[id];
      if (marker && map.hasLayer(marker)) {
        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 11), { animate: true });
        marker.openPopup();
      }
      setUrlParams(isService ? { service: String(id), spot: '' } : { spot: String(id), service: '' });
      openModal();
    }

    /* close handlers */
    $(document).on('click', '[data-close]', closeModal);
    $(document).on('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
    $(document).on('click', '.tap-destmap-itin-btn', function () { toggleItinerary($(this)); });

    /* ------------------------------------------------------------------ */
    /* Markers                                                             */
    /* ------------------------------------------------------------------ */

    spots.forEach(function (spot) {
      var lat = parseFloat(spot.lat);
      var lng = parseFloat(spot.lng);
      if (isNaN(lat) || isNaN(lng)) return;
      bounds.push([lat, lng]);

      var marker = L.marker([lat, lng], { icon: pinIcon('spot', spot.type, spot.title) });
      marker.bindTooltip(esc(spot.title), { direction: 'top', offset: [0, -12], opacity: 0.92 });
      var popup =
        '<div class="tap-destmap-popup">' +
          (spot.img ? '<img class="tap-destmap-popup-img" src="' + esc(spot.img) + '" alt="" loading="lazy">' : '') +
          '<span class="tap-destmap-popup-type">' + esc(spot.typeName || '') + '</span>' +
          '<a class="tap-destmap-popup-title" href="' + esc(spot.url) + '">' + esc(spot.title) + '</a>' +
          '<button type="button" class="tap-destmap-popup-btn" data-item-kind="spot" data-item-id="' + esc(spot.id) + '">' + esc(i18n('interest', 'Datos de interés')) + '</button>' +
        '</div>';
      marker.bindPopup(popup, { minWidth: 200 });
      spotMarkers[spot.id] = marker;
      spotCluster.addLayer(marker);
    });

    services.forEach(function (service) {
      var lat = parseFloat(service.lat);
      var lng = parseFloat(service.lng);
      if (isNaN(lat) || isNaN(lng)) return;
      bounds.push([lat, lng]);

      var marker = L.marker([lat, lng], { icon: pinIcon('service', service.type, service.title) });
      marker.bindTooltip(esc(service.title), { direction: 'top', offset: [0, -12], opacity: 0.92 });
      var popup =
        '<div class="tap-destmap-popup tap-destmap-popup--service">' +
          (service.img ? '<img class="tap-destmap-popup-img" src="' + esc(service.img) + '" alt="" loading="lazy">' : '') +
          '<span class="tap-destmap-popup-type">' + esc(service.typeName || '') + '</span>' +
          '<a class="tap-destmap-popup-title" href="' + esc(service.url) + '">' + esc(service.title) + '</a>' +
          (service.price ? '<span class="tap-destmap-popup-price">' + esc(service.price) + '</span>' : '') +
          '<button type="button" class="tap-destmap-popup-btn" data-item-kind="service" data-item-id="' + esc(service.id) + '">' + esc(i18n('details', 'Ver detalles')) + '</button>' +
          '<a class="tap-destmap-popup-cta" href="' + esc(bookingHref(service)) + '">' + esc(i18n('book', 'Reservar / Cotizar')) + ' &rarr;</a>' +
        '</div>';
      marker.bindPopup(popup, { minWidth: 200 });
      serviceMarkers[service.id] = marker;
      serviceCluster.addLayer(marker);
    });

    /* One delegated handler for all popups (no duplicate .one() bindings). */
    $(document).on('click', '.tap-destmap-popup-btn', function () {
      openDetail($(this).attr('data-item-kind'), $(this).attr('data-item-id'));
    });
    $(document).on('click', '.tap-destmap-item-btn', function () {
      var $item = $(this).closest('.tap-destmap-item');
      openDetail($item.attr('data-item-kind'), $item.attr('data-item-id'));
    });

    if (bounds.length) {
      map.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 });
    }
    var sk = document.getElementById('tap-destmap-skeleton');
    if (sk) { sk.classList.add('is-hidden'); }

    /* ------------------------------------------------------------------ */
    /* Unified filtering: search + layers + service type (list AND map)    */
    /* ------------------------------------------------------------------ */

    function matchesQuery(item) {
      if (!state.q) return true;
      var haystack = [item.title, item.typeName, item.province, item.region, item.city].join(' ');
      return norm(haystack).indexOf(norm(state.q)) !== -1;
    }

    function isVisible(item, kind) {
      if (kind === 'spot' && state.layer === 'services') return false;
      if (kind === 'service' && state.layer === 'spots') return false;
      if (kind === 'service' && state.serviceFilter && item.type !== state.serviceFilter) return false;
      return matchesQuery(item);
    }

    function findItem(kind, id) {
      var items = kind === 'service' ? services : spots;
      for (var i = 0; i < items.length; i++) {
        if (String(items[i].id) === String(id)) return items[i];
      }
      return null;
    }

    function applyFilters() {
      var showSpots = state.layer === 'all' || state.layer === 'spots';
      var showServices = state.layer === 'all' || state.layer === 'services';

      spots.forEach(function (spot) {
        var marker = spotMarkers[spot.id];
        if (!marker) return;
        if (showSpots && isVisible(spot, 'spot')) {
          if (!spotCluster.hasLayer(marker)) spotCluster.addLayer(marker);
        } else if (spotCluster.hasLayer(marker)) {
          spotCluster.removeLayer(marker);
        }
      });
      services.forEach(function (service) {
        var marker = serviceMarkers[service.id];
        if (!marker) return;
        if (showServices && isVisible(service, 'service')) {
          if (!serviceCluster.hasLayer(marker)) serviceCluster.addLayer(marker);
        } else if (serviceCluster.hasLayer(marker)) {
          serviceCluster.removeLayer(marker);
        }
      });

      var shown = 0;
      $('.tap-destmap-item').each(function () {
        var $item = $(this);
        var kind = $item.attr('data-item-kind');
        var item = findItem(kind, $item.attr('data-item-id'));
        var vis = !!item && isVisible(item, kind);
        $item.toggle(vis);
        if (vis) shown++;
      });

      $('.tap-destmap-list:first').prop('hidden', !showSpots);
      $('.tap-destmap-list--services').prop('hidden', !showServices);
      $('.tap-destmap-filters--spots').prop('hidden', !showSpots);
      $('.tap-destmap-filters--services').prop('hidden', !showServices);

      $('.tap-destmap-tab').each(function () {
        var active = ($(this).attr('data-tab') === state.layer);
        $(this).toggleClass('is-active', active).attr('aria-selected', active ? 'true' : 'false');
      });
      $('.tap-destmap-layer').each(function () {
        var active = ($(this).attr('data-layer') === state.layer);
        $(this).toggleClass('is-active', active).attr('aria-pressed', active ? 'true' : 'false');
      });
      $('.tap-destmap-chip--service').each(function () {
        var active = ($(this).attr('data-service-type') || '') === state.serviceFilter;
        $(this).toggleClass('is-active', active).attr('aria-current', active ? 'true' : 'false');
      });

      var label = i18n(shown === 1 ? 'result' : 'results', shown === 1 ? '%d lugar' : '%d lugares');
      $('#tap-destmap-count').text(String(label).replace('%d', String(shown)));
      $('#tap-destmap-no-results').prop('hidden', shown !== 0);

      setUrlParams({
        layer: state.layer === 'all' ? '' : state.layer,
        service_type: state.serviceFilter,
        q: state.q
      });
    }

    var searchTimer;
    $('#tap-destmap-search').val(state.q).on('input', function () {
      var value = $(this).val();
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.q = norm(value).substring(0, 80);
        applyFilters();
      }, 120);
    });

    function setLayer(value) {
      state.layer = value;
      applyFilters();
      $('#tap-destmap-detail').attr('hidden', 'hidden');
      window.setTimeout(function () { map.invalidateSize(); }, 60);
    }

    $(document).on('click', '.tap-destmap-layer', function () {
      setLayer(String($(this).attr('data-layer') || 'all'));
    });
    $(document).on('click', '.tap-destmap-tab', function () {
      setLayer(String($(this).attr('data-tab') || 'spots'));
    });
    $(document).on('click', '.tap-destmap-chip--service', function (e) {
      e.preventDefault();
      var type = String($(this).attr('data-service-type') || '');
      state.serviceFilter = (state.serviceFilter === type) ? '' : type;
      applyFilters();
    });

    /* Share the exact current view (URL state + any ?ref= attribution). */
    $(document).on('click', '[data-role="share"]', function () {
      var url = window.location.href;
      function done() { toast(i18n('copied', 'Enlace copiado ✓')); }
      function fallback() {
        var $tmp = $('<textarea>').val(url).css({ position: 'fixed', left: '-9999px' }).appendTo('body');
        $tmp[0].select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        $tmp.remove();
        if (ok) { done(); } else { toast(i18n('copyFail', 'No se pudo copiar el enlace')); }
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done).catch(fallback);
      } else {
        fallback();
      }
    });

    /* Mobile bottom sheet toggle. */
    $(document).on('click', '[data-role="sheet-toggle"]', function () {
      var collapsed = $root.toggleClass('is-sheet-collapsed').hasClass('is-sheet-collapsed');
      $(this).attr('aria-expanded', collapsed ? 'false' : 'true');
      $(this).find('[data-role="sheet-toggle-label"]').text(
        i18n(collapsed ? 'sheetClosed' : 'sheetOpen', collapsed ? 'Mostrar lista' : 'Ocultar lista')
      );
      window.setTimeout(function () { map.invalidateSize(); }, 280);
    });

    /* Boot */
    applyFilters();
    var deepSpot = urlParam('spot');
    var deepService = urlParam('service');
    if (deepSpot || deepService) {
      window.setTimeout(function () {
        openDetail(deepSpot ? 'spot' : 'service', deepSpot || deepService);
      }, 250);
    }
    window.setTimeout(function () { map.invalidateSize(); }, 200);
  });
})();
