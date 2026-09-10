/**
 * TAP Itinerary Builder (T3) — wizard state machine + AJAX results + sidebar.
 * Progressive enhancement: the form still works as a plain GET without JS.
 */
(function ($) {
    'use strict';

    var root = document.getElementById('tap-itin');
    if (!root || !window.tapItinerary) { return; }

    var DATA = tapItinerary.data || { interests: [], categories: [], destinations: [], types: [] };
    var STORE_KEY = 'tap_itin_v1';

    function load() {
        try { return JSON.parse(localStorage.getItem(STORE_KEY)) || defaultState(); } catch (e) { return defaultState(); }
    }
    function save(state) {
        try { localStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch (e) { /* private mode */ }
    }
    function defaultState() {
        return { interests: [], categories: [], locations: [], types: [], min_price: null, max_price: null, picks: [] };
    }

    var state = load();
    var $step = $('.tap-itin-step', root);
    var current = 1;

    // ---- Wiring ----
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function renderInterests() {
        var box = $('[data-role="interests"]', root);
        if (box && DATA.interests.length) {
            box.innerHTML = DATA.interests.map(function (t) {
                return '<button type="button" class="tap-itin-chip' + (state.interests.indexOf(t.slug) !== -1 ? ' is-on' : '') + '" data-value="' + esc(t.slug) + '">' + esc(t.name) + (t.count ? ' <span class="tap-itin-chip-count">' + esc(t.count) + '</span>' : '') + '</button>';
            }).join('');
        }
        var cat = $('[data-role="categories"]', root);
        if (cat) {
            if (DATA.categories.length) {
                cat.innerHTML = DATA.categories.map(function (t) {
                    return '<span class="tap-itin-chip-label">' + esc(t.name) + '</span><button type="button" class="tap-itin-chip tap-itin-chip-inline' + (state.categories.indexOf(t.slug) !== -1 ? ' is-on' : '') + '" data-value="' + esc(t.slug) + '" data-kind="category">' + esc(t.name) + '</button>';
                }).join('');
            } else {
                cat.style.display = 'none';
            }
        }
    }

    function renderDestinations() {
        var box = $('[data-role="destinations"]', root);
        if (!box) { return; }
        var list = DATA.destinations || [];
        if (!list.length) {
            box.innerHTML = '<p class="tap-itin-dest-empty">' + esc(tapItinerary.emptyDestMsg) + '</p>';
            return;
        }
        box.innerHTML = list.map(function (d) {
            var on = state.locations.indexOf(d.id) !== -1;
            return '<button type="button" class="tap-itin-dest' + (on ? ' is-on' : '') + '" data-value="' + d.id + '">' +
                '<strong>' + esc(d.name) + '</strong>' +
                (d.parent ? '<span>' + esc(d.parent) + '</span>' : '') +
                (d.count ? '<em>' + esc(d.count) + '</em>' : '') +
                '</button>';
        }).join('');
    }

    function renderTypes() {
        var box = $('[data-role="types"]', root);
        if (!box) { return; }
        box.innerHTML = (DATA.types || []).map(function (t) {
            var on = state.types.length === 0 || state.types.indexOf(t.value) !== -1;
            return '<label class="tap-itin-type">' +
                '<input type="checkbox" value="' + esc(t.value) + '"' + (on ? ' checked' : '') + '>' +
                '<span>' + esc(t.label) + '</span></label>';
        }).join('');
    }

    function sync() {
        $('[data-itin-fld="interests"]', root).value = state.interests.join(',');
        $('[data-itin-fld="locations"]', root).value = state.locations.join(',');
        $('[data-itin-fld="types"]', root).value = state.types.join(',');
        $('[data-itin-fld="min_price"]', root).value = state.min_price == null ? '' : state.min_price;
        $('[data-itin-fld="max_price"]', root).value = state.max_price == null ? '' : state.max_price;
        renderSummary();
    }

    function showStep(n) {
        current = n;
        $step.each(function () {
            $(this).toggleClass('is-active', parseInt($(this).attr('data-itin-step'), 10) === n);
        });
        $('[data-itin-prev]', root).prop('disabled', n === 1);
        $('[data-itin-next]', root).prop('hidden', n === 3);
        $('[data-itin-run]', root).prop('hidden', n !== 3);
    }

    // ---- Sidebar / picks ----
    function pickKey(id, type) { return type + ':' + id; }

    function renderSummary() {
        var list = $('[data-itin-list]', root);
        list.empty();
        var total = 0;
        (state.picks || []).forEach(function (p) {
            total += (parseFloat(p.price) || 0);
            list.append('<li data-key="' + esc(pickKey(p.id, p.type)) + '">' +
                '<span class="tap-itin-side-item-title">' + esc(p.title) + '</span>' +
                (p.price ? '<span class="tap-itin-side-item-price">' + esc(p.priceLabel || '') + '</span>' : '') +
                '<button type="button" class="tap-itin-side-remove" aria-label="×">×</button></li>');
        });
        $('[data-itin-empty]', root).toggle(state.picks.length === 0);
        var totalBox = $('[data-itin-total]', root);
        totalBox.text(tapItinerary.currencySymbol + ' ' + total.toLocaleString());
        $('[data-itin-side-total]', root).attr('hidden', state.picks.length === 0 ? '' : null);
        var view = $('[data-itin-view]', root);
        if (state.picks.length) {
            var ids = state.picks.map(function (p) { return p.id; }).join(',');
            view.attr('href', tapItinerary.view_url + (tapItinerary.view_url.indexOf('?') !== -1 ? '&' : '?') + 'itin=' + ids);
            view.attr('hidden', null);
        } else {
            view.attr('hidden', '');
        }
    }

    function priceLabel(id, type) {
        var label = '';
        $('.tap-itin-item', root).each(function () {
            if ($(this).attr('data-id') === String(id) && $(this).attr('data-type') === type) {
                label = $.trim($(this).find('.tap-itin-item-price').first().text());
            }
        });
        return label;
    }

    function togglePick(id, type) {
        var key = pickKey(id, type);
        var el = $('.tap-itin-item[data-id="' + id + '"]', root).first();
        var title = el.attr('data-title') ? el.attr('data-title') : id;
        var price = el.attr('data-price') ? el.attr('data-price') : '0';
        var existing = (state.picks || []).filter(function (p) { return pickKey(p.id, p.type) === key; });
        if (existing.length) {
            state.picks = state.picks.filter(function (p) { return pickKey(p.id, p.type) !== key; });
            el.find('.tap-itin-add').text(tapItinerary.addText);
        } else {
            state.picks.push({ id: id, type: type, title: title, price: price, priceLabel: priceLabel(id, type) });
            el.find('.tap-itin-add').text(tapItinerary.addedText);
        }
        save(state);
        renderSummary();
    }

    // ---- Events ----
    $(root).on('click', '[data-role="interests"] .tap-itin-chip', function () {
        var v = $(this).attr('data-value');
        var i = state.interests.indexOf(v);
        i === -1 ? state.interests.push(v) : state.interests.splice(i, 1);
        $(this).toggleClass('is-on', i === -1);
        save(state); sync();
    });
    $(root).on('click', '[data-role="categories"] .tap-itin-chip', function () {
        var v = $(this).attr('data-value');
        var i = state.categories.indexOf(v);
        i === -1 ? state.categories.push(v) : state.categories.splice(i, 1);
        $(this).toggleClass('is-on', i === -1);
        save(state); sync();
    });
    $(root).on('click', '[data-role="destinations"] .tap-itin-dest', function () {
        var v = parseInt($(this).attr('data-value'), 10);
        var i = state.locations.indexOf(v);
        i === -1 ? state.locations.push(v) : state.locations.splice(i, 1);
        $(this).toggleClass('is-on', i === -1);
        save(state); sync();
    });
    $(root).on('change', '[data-role="types"] input', function () {
        var checked = $('[data-role="types"] input:checked', root).map(function () { return $(this).val(); }).get();
        state.types = checked.length === (DATA.types || []).length ? [] : checked;
        save(state); sync();
    });
    $(root).on('input', '[data-role="min_price"]', function () { state.min_price = $(this).val() === '' ? null : parseFloat($(this).val()); save(state); sync(); });
    $(root).on('input', '[data-role="max_price"]', function () { state.max_price = $(this).val() === '' ? null : parseFloat($(this).val()); save(state); sync(); });
    $(root).on('click', '[data-itin-next]', function () { showStep(Math.min(3, current + 1)); });
    $(root).on('click', '[data-itin-prev]', function () { showStep(Math.max(1, current - 1)); });
    $(root).on('click', '[data-itin-run]', function () { $('form[data-itin-form]', root).trigger('submit'); });
    $(root).on('click', '.tap-itin-item .tap-itin-add', function () {
        var item = $(this).closest('.tap-itin-item');
        togglePick(item.attr('data-id'), item.attr('data-type'));
    });
    $(root).on('click', '.tap-itin-side-remove', function () {
        var li = $(this).closest('li');
        var parts = li.attr('data-key').split(':');
        togglePick(parts[1], parts[0]);
    });
    $(root).on('click', '[data-itin-clear]', function () {
        state.picks = [];
        $('.tap-itin-add', root).text(tapItinerary.addText);
        save(state); renderSummary();
    });

    $('form[data-itin-form]', root).on('submit', function (e) {
        e.preventDefault();
        var $btn = $('[data-itin-run]', root).prop('disabled', true);
        var post = {
            action: 'tap_itinerary_search',
            nonce: tapItinerary.nonce,
            interests: state.interests, categories: state.categories,
            locations: state.locations, types: state.types,
            min_price: state.min_price, max_price: state.max_price
        };
        $.post(tapItinerary.ajax_url, post).done(function (res) {
            var box = $('[data-itin-results]', root);
            if (res && res.success && res.data.total === 0) {
                post.relaxed = '1';
                $.post(tapItinerary.ajax_url, post).done(function (r2) {
                    box.html((r2 && r2.success) ? r2.data.html : '<p>' + esc(tapItinerary.errorText) + '</p>');
                }).fail(function () { box.html('<p>' + esc(tapItinerary.errorText) + '</p>'); });
            } else if (res && res.success) {
                box.html(res.data.html);
            }
        }).always(function () { $btn.prop('disabled', false); });
    });

    // ---- Boot ----
    renderInterests();
    renderDestinations();
    renderTypes();
    sync();
    showStep(1);
})(jQuery);