/* TAP agency back-office pricing & availability calendar.
 * Plain JS; loads per-room monthly data via tap_agency_get_pricing and writes
 * single or bulk cells via tap_agency_save_pricing (tap_front_dash_nonce).
 * Day level edits open a modal (TapWizard.formModal); bulk tools stay in the
 * side rail. Shares toasts / confirm / money helpers with agency-wizard.js. */
(function (window, document) {
    'use strict';

    var cfg = window.tapAgencyCal;
    if (!cfg || !cfg.rooms || !cfg.rooms.length) { return; }

    var ui = window.TapWizard || {};
    var i18n = cfg.i18n || {};

    var state = {
        roomId: cfg.rooms[0].id,
        year: 0,
        month: 0,
        days: [],
        basePrice: 0,
        baseMin: 1,
        inventory: 1,
        selectedDate: null
    };

    var months = i18n.months || ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    var dows = i18n.dow || ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function t(key, fallback) { return i18n[key] != null ? i18n[key] : fallback; }

    function toast(msg, type) {
        if (ui.toast) { ui.toast(msg, type); }
    }

    function decimals() {
        var d = parseInt(cfg.decimals, 10);
        return isNaN(d) ? 2 : d;
    }

    function money(value) {
        var num = parseFloat(value || 0);
        var text = num.toFixed(decimals());
        var symbol = cfg.currency || '';
        return symbol ? symbol + ' ' + text : text;
    }

    function pad(n) { return String(n).padStart(2, '0'); }

    function iso(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    function longDate(dateStr) {
        var p = String(dateStr).split('-');
        var m = parseInt(p[1], 10) - 1;
        return parseInt(p[2], 10) + ' de ' + (months[m] || '') + ' de ' + p[0];
    }

    /* ── initialisation ─────────────────────────────────────────────── */

    function init() {
        var now = new Date();
        state.year = now.getFullYear();
        state.month = now.getMonth() + 1;

        renderTabs();
        bindNav();
        bindRange();
        bindDow();
        bindCopyMonth();
        bindClearAll();
        loadMonth(state.year, state.month);
    }

    function currentRoom() {
        var found = cfg.rooms[0];
        cfg.rooms.forEach(function (room) { if (room.id === state.roomId) { found = room; } });
        return found;
    }

    function renderTabs() {
        var box = qs('#tap-ac-room-tabs');
        if (!box) { return; }
        box.textContent = '';
        cfg.rooms.forEach(function (room) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'tap-ac-room-tab' + (room.id === state.roomId ? ' active' : '');
            b.textContent = room.title;
            b.setAttribute('aria-pressed', room.id === state.roomId ? 'true' : 'false');
            b.addEventListener('click', function () {
                if (room.id === state.roomId) { return; }
                state.roomId = room.id;
                state.selectedDate = null;
                qsa('.tap-ac-room-tab', box).forEach(function (tab) {
                    var active = tab === b;
                    tab.classList.toggle('active', active);
                    tab.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
                loadMonth(state.year, state.month);
            });
            box.appendChild(b);
        });
    }

    /* ── grid ───────────────────────────────────────────────────────── */

    function loadMonth(year, month) {
        var cal = qs('#tap-ac-calendar');
        if (!cal) { return; }
        cal.innerHTML = '<div class="tap-ac-loading">' + escapeHtml(t('loading', 'Cargando calendario…')) + '</div>';

        var fd = new FormData();
        fd.append('action', 'tap_agency_get_pricing');
        fd.append('nonce', cfg.nonce);
        fd.append('room_id', state.roomId);
        fd.append('year', year);
        fd.append('month', month);

        window.fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) {
                    cal.innerHTML = '<div class="tap-ac-loading">' + escapeHtml(t('error', 'Error')) + '</div>';
                    toast((j && j.data && j.data.message) || t('error', 'Error'), 'error');
                    return;
                }
                var d = j.data;
                state.days = d.days || [];
                state.basePrice = parseFloat(d.base_price) || 0;
                state.baseMin = parseInt(d.base_min, 10) || 1;
                state.inventory = parseInt(d.inventory, 10) || 1;
                state.year = d.year;
                state.month = d.month;
                var label = qs('.tap-ac-month-label');
                if (label) {
                    label.textContent = months[d.month - 1] + ' ' + d.year;
                }
                renderMeta();
                renderCopyTargets();
                renderGrid();
            })
            .catch(function () {
                cal.innerHTML = '<div class="tap-ac-loading">' + escapeHtml(t('error', 'Error')) + '</div>';
                toast(t('networkError', 'Error de conexión.'), 'error');
            });
    }

    function renderMeta() {
        var room = currentRoom();
        var base = qs('.tap-ac-base-info');
        if (base) {
            base.textContent = money(state.basePrice) + ' · ' + t('minStay', 'mín.') + ' ' + state.baseMin + ' ' +
                t('nights', 'noches') + ' · ' + state.inventory + ' ' + t('available', 'disponible(s)');
        }
        var counter = qs('.tap-ac-override-count');
        if (counter) {
            var n = state.days.filter(function (day) { return day.overridden; }).length;
            counter.textContent = n === 1
                ? t('oneOverride', '1 día ajustado')
                : n + ' ' + t('overrides', 'días ajustados');
            counter.classList.toggle('is-empty', n === 0);
        }
        var roomLabel = qs('.tap-ac-room-label');
        if (roomLabel) { roomLabel.textContent = room.title; }
    }

    function dayAria(day) {
        var bits = [longDate(day.date), money(day.price)];
        if (day.is_blocked) {
            bits.push(t('blocked', 'Bloqueado'));
        } else if (day.booked) {
            bits.push(day.booked + ' ' + t('booked', 'Ocupado'));
        }
        if (day.min_stay > state.baseMin) {
            bits.push(t('minStay', 'mín.') + ' ' + day.min_stay);
        }
        if (day.label) { bits.push(day.label); }
        if (!day.overridden) { bits.push(t('base', 'Base')); }
        return bits.join(' · ');
    }

    function renderGrid() {
        var cal = qs('#tap-ac-calendar');
        if (!cal) { return; }

        var html = '<div class="tap-ac-weekdays" role="row">';
        for (var i = 0; i < 7; i++) {
            html += '<div class="tap-ac-weekday" role="columnheader">' + escapeHtml(dows[i]) + '</div>';
        }
        html += '</div><div class="tap-ac-days">';

        var firstDoW = new Date(state.year, state.month - 1, 1).getDay();
        for (var p = 0; p < firstDoW; p++) {
            html += '<div class="tap-ac-day tap-ac-day-ghost" aria-hidden="true"></div>';
        }

        var today = new Date();
        var todayStr = iso(today);

        state.days.forEach(function (day) {
            var cls = 'tap-ac-day';
            if (day.overridden && day.is_blocked) { cls += ' tap-ac-day-blocked'; }
            else if (day.overridden) { cls += ' tap-ac-day-override'; }
            if (day.date === todayStr) { cls += ' tap-ac-day-today'; }
            if (state.selectedDate === day.date) { cls += ' tap-ac-day-selected'; }

            var meta = [];
            if (day.is_blocked) { meta.push(escapeHtml(t('blocked', 'Bloqueado'))); }
            else if (day.booked) { meta.push(escapeHtml(String(day.booked)) + ' ' + escapeHtml(t('booked', 'Ocupado'))); }

            html += '<button type="button" class="' + cls + '" data-date="' + day.date + '"' +
                ' aria-label="' + escapeHtml(dayAria(day)) + '"' +
                ' aria-pressed="' + (state.selectedDate === day.date ? 'true' : 'false') + '">' +
                '<span class="tap-ac-day-number">' + day.day + '</span>' +
                '<span class="tap-ac-day-price">' + escapeHtml(money(day.price)) + '</span>' +
                (day.label ? '<span class="tap-ac-day-label">' + escapeHtml(day.label) + '</span>' : '') +
                (meta.length ? '<span class="tap-ac-day-meta">' + meta.join(' · ') + '</span>' : '') +
                '</button>';
        });

        html += '</div>';
        cal.innerHTML = html;

        qsa('.tap-ac-day[data-date]', cal).forEach(function (cell) {
            cell.addEventListener('click', function () {
                openDayModal(cell.getAttribute('data-date'));
            });
        });
    }

    function dayFor(date) {
        var found = null;
        state.days.forEach(function (d) { if (d.date === date) { found = d; } });
        return found;
    }

    /* ── day modal (seasons & single overrides) ─────────────────────── */

    function openDayModal(date) {
        var day = dayFor(date);
        if (!day || !ui.formModal) { return; }

        state.selectedDate = date;
        qsa('.tap-ac-day[data-date]').forEach(function (cell) {
            var active = cell.getAttribute('data-date') === date;
            cell.classList.toggle('tap-ac-day-selected', active);
            cell.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        var overridden = !!day.overridden;
        var priceValue = overridden && day.price !== null && parseFloat(day.price) !== state.basePrice
            ? day.price : '';
        var minValue = overridden && parseInt(day.min_stay, 10) !== state.baseMin ? day.min_stay : '';

        ui.formModal({
            title: longDate(date),
            subtitle: money(day.price) + ' · ' + t('basePriceIs', 'Precio base') + ' ' + money(state.basePrice),
            okText: t('save', 'Guardar'),
            extraLabel: overridden ? t('clearDay', 'Eliminar ajuste') : '',
            fields: [
                {
                    name: 'price', type: 'number', label: t('price', 'Precio por noche'),
                    placeholder: money(state.basePrice), value: priceValue,
                    min: 0, step: '0.01', inputmode: 'decimal', autofocus: true,
                    hint: t('emptyUsesBase', 'Vacío = usar el precio base.')
                },
                {
                    name: 'min_stay', type: 'number', label: t('minStay', 'Estadía mínima'),
                    placeholder: String(state.baseMin), value: minValue,
                    min: 0, max: 60, step: '1', inputmode: 'numeric',
                    hint: t('emptyUsesBase', 'Vacío = usar la base.')
                },
                {
                    name: 'avail', type: 'select', label: t('availability', 'Disponibilidad'),
                    value: day.is_blocked ? 'no' : (overridden ? 'yes' : ''),
                    options: [
                        { value: '', label: t('useBase', 'Usar base') },
                        { value: 'yes', label: t('available', 'Disponible') },
                        { value: 'no', label: t('block', 'Bloquear estas fechas') }
                    ]
                },
                {
                    name: 'label', type: 'text', label: t('label', 'Etiqueta (temporada)'),
                    placeholder: t('labelPlaceholder', 'p.ej. Temporada Alta'), value: day.label || '',
                    maxlength: 100, wide: true
                }
            ]
        }).then(function (values) {
            if (!values) { return; }
            if (values.__clear) {
                postCells([{ date: date, price: '', min_stay: '', is_blocked: 0, label: '' }], null);
                return;
            }
            postCells([{
                date: date,
                price: values.price !== '' ? values.price : '',
                min_stay: values.min_stay !== '' ? values.min_stay : '',
                is_blocked: values.avail === 'no' ? 1 : 0,
                label: values.label || ''
            }], null);
        });
    }

    /* ── range ──────────────────────────────────────────────────────── */

    function bindRange() {
        var btn = qs('.tap-ac-range-apply');
        if (!btn) { return; }
        on(btn, 'click', function () {
            var from = qs('.tap-ac-range-from').value;
            var to = qs('.tap-ac-range-to').value;
            if (!from || !to || from > to) {
                setMsg('#tap-ac-range-actions', t('badRange', 'Revisa el rango de fechas.'), true);
                return;
            }
            var price = qs('.tap-ac-range-price').value;
            var minstay = qs('.tap-ac-range-minstay').value;
            var avail = qs('.tap-ac-range-avail').value;
            var label = qs('.tap-ac-range-label').value;

            var cells = [];
            var d = new Date(from + 'T00:00:00');
            var end = new Date(to + 'T00:00:00');
            while (d <= end) {
                cells.push({
                    date: iso(d),
                    price: price !== '' ? price : '',
                    min_stay: minstay !== '' ? minstay : '',
                    is_blocked: avail === 'no' ? 1 : 0,
                    label: label !== '' ? label : (avail === 'no' ? t('blockedShort', 'Bloqueado') : '')
                });
                d.setDate(d.getDate() + 1);
            }
            setBusy(btn, true);
            postCells(cells, '#tap-ac-range-actions', function () { setBusy(btn, false); });
        });
    }

    /* ── day-of-week rule ───────────────────────────────────────────── */

    function bindDow() {
        var btn = qs('.tap-ac-dow-apply');
        if (!btn) { return; }
        on(btn, 'click', function () {
            var selected = qsa('#tap-ac-dow input:checked').map(function (c) { return parseInt(c.value, 10); });
            if (!selected.length) {
                setMsg('#tap-ac-dow-actions', t('noOverrideTarget', 'Selecciona al menos un día de la semana.'), true);
                return;
            }
            var op = qs('.tap-ac-dow-op').value;
            var amount = parseFloat(qs('.tap-ac-dow-amount').value);
            var minstay = qs('.tap-ac-dow-minstay').value;
            var avail = qs('.tap-ac-dow-avail').value;

            if (op !== 'clear' && !(amount > 0)) {
                setMsg('#tap-ac-dow-actions', t('badAmount', 'Indica un importe o porcentaje mayor que 0.'), true);
                return;
            }

            var cells = [];
            state.days.forEach(function (day) {
                if (selected.indexOf(day.dow) === -1) { return; }
                var cell = { date: day.date, is_blocked: 0 };
                if (op === 'clear') {
                    cell.price = ''; cell.min_stay = ''; cell.is_blocked = 0; cell.label = '';
                } else {
                    if (op === 'set') {
                        cell.price = amount;
                    } else {
                        var pct = amount / 100;
                        var current = parseFloat(day.price) || state.basePrice;
                        cell.price = Math.round(current * (op === 'add_pct' ? (1 + pct) : (1 - pct)) * 100) / 100;
                    }
                    cell.min_stay = minstay !== '' ? minstay : '';
                    cell.is_blocked = avail === 'no' ? 1 : 0;
                    cell.label = '';
                }
                cells.push(cell);
            });

            setBusy(btn, true);
            postCells(cells, '#tap-ac-dow-actions', function () { setBusy(btn, false); });
        });
    }

    /* ── copy month ─────────────────────────────────────────────────── */

    function renderCopyTargets() {
        var select = qs('.tap-ac-copy-target');
        if (!select || select.dataset.ready === '1') { return; }
        select.textContent = '';
        for (var i = 1; i <= 12; i++) {
            var m = state.month + i;
            var y = state.year;
            while (m > 12) { m -= 12; y += 1; }
            var opt = document.createElement('option');
            opt.value = y + '-' + pad(m);
            opt.textContent = months[m - 1] + ' ' + y;
            select.appendChild(opt);
        }
        select.dataset.ready = '1';
    }

    function bindCopyMonth() {
        var btn = qs('.tap-ac-copy-apply');
        if (!btn) { return; }
        on(btn, 'click', function () {
            var target = qs('.tap-ac-copy-target').value;
            var mode = qs('.tap-ac-copy-mode').value;
            if (!target) { return; }

            var parts = target.split('-');
            var ty = parseInt(parts[0], 10);
            var tm = parseInt(parts[1], 10);

            var sources = state.days.filter(function (day) {
                if (mode === 'overrides') { return day.overridden; }
                return true;
            });
            if (!sources.length) {
                setMsg('#tap-ac-copy-actions', t('nothingToCopy', 'No hay días que copiar.'), true);
                return;
            }

            var daysInTarget = new Date(ty, tm, 0).getDate();
            var cells = [];
            var dropped = 0;
            sources.forEach(function (day) {
                var dom = parseInt(String(day.date).slice(8), 10);
                if (dom > daysInTarget) { dropped += 1; return; }
                cells.push({
                    date: ty + '-' + pad(tm) + '-' + pad(dom),
                    price: parseFloat(day.price) || '',
                    min_stay: parseInt(day.min_stay, 10) || '',
                    is_blocked: day.is_blocked ? 1 : 0,
                    label: day.label || ''
                });
            });

            if (!cells.length) {
                setMsg('#tap-ac-copy-actions', t('nothingToCopy', 'No hay días que copiar.'), true);
                return;
            }

            var label = (months[tm - 1] + ' ' + ty) + (mode === 'overrides'
                ? ' ' + t('copiedFrom', 'copiado desde') + ' ' + months[state.month - 1] + ' ' + state.year
                : '');

            if (ui.confirm) {
                ui.confirm({
                    title: t('copyMonth', 'Copiar mes'),
                    subtitle: cells.length + ' ' + t('daysTo', 'días a') + ' ' + label +
                        (dropped ? ' · ' + dropped + ' ' + t('skipped', 'omitidos') : ''),
                    okText: t('copy', 'Copiar'),
                    onConfirm: function () {
                        setBusy(btn, true);
                        postCells(cells.map(function (c) {
                            return {
                                date: c.date, price: c.price, min_stay: c.min_stay,
                                is_blocked: c.is_blocked, label: c.label || label
                            };
                        }), '#tap-ac-copy-actions', function () { setBusy(btn, false); });
                    }
                });
                return;
            }

            postCells(cells, '#tap-ac-copy-actions', function () { setBusy(btn, false); });
        });
    }

    /* ── clear month ────────────────────────────────────────────────── */

    function bindClearAll() {
        var btn = qs('.tap-ac-clear-all');
        if (!btn) { return; }
        on(btn, 'click', function () {
            var cells = state.days
                .filter(function (day) { return day.overridden; })
                .map(function (day) {
                    return { date: day.date, price: '', min_stay: '', is_blocked: 0, label: '' };
                });
            if (!cells.length) {
                setMsg('#tap-ac-clear-actions', t('nothingToClear', 'Este mes no tiene ajustes.'), true);
                return;
            }
            var run = function () {
                setBusy(btn, true);
                postCells(cells, '#tap-ac-clear-actions', function () { setBusy(btn, false); });
            };
            if (ui.confirm) {
                ui.confirm({
                    title: t('clearMonth', 'Limpiar mes'),
                    subtitle: cells.length + ' ' + t('daysWillReset', 'días volverán al precio base'),
                    okText: t('clear', 'Limpiar'),
                    danger: true,
                    onConfirm: run
                });
                return;
            }
            run();
        });
    }

    /* ── shared save ────────────────────────────────────────────────── */

    function on(el, ev, fn) { if (el) { el.addEventListener(ev, fn); } }

    function setBusy(button, busy) {
        if (ui.setBusy) { ui.setBusy(button, busy); return; }
        if (!button) { return; }
        button.disabled = !!busy;
    }

    function setMsg(scopeSel, text, isError) {
        var scope = typeof scopeSel === 'string' ? qs(scopeSel) : scopeSel;
        if (!scope) { return; }
        var msg = qs('.tap-ac-msg', scope);
        if (!msg) { return; }
        msg.textContent = text || '';
        msg.classList.toggle('is-error', !!isError);
    }

    function postCells(cells, msgScope, done) {
        if (!cells || !cells.length) {
            if (done) { done(); }
            return;
        }
        setMsg(msgScope, '…');

        var fd = new FormData();
        fd.append('action', 'tap_agency_save_pricing');
        fd.append('nonce', cfg.nonce);
        fd.append('room_id', state.roomId);
        fd.append('bulk', '1');
        fd.append('cells', JSON.stringify(cells));

        window.fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.success) {
                    var app = j.data && j.data.applied;
                    var total = app ? (app.created + app.updated + app.deleted + app.noop) : 0;
                    var text = (t('saved', 'Guardado') + ' · ' + total);
                    setMsg(msgScope, text);
                    toast(text, 'success');
                } else {
                    var message = (j && j.data && j.data.message) || t('error', 'Error');
                    setMsg(msgScope, message, true);
                    toast(message, 'error');
                }
                loadMonth(state.year, state.month);
                if (done) { done(); }
            })
            .catch(function () {
                setMsg(msgScope, t('networkError', 'Error de conexión.'), true);
                toast(t('networkError', 'Error de conexión.'), 'error');
                if (done) { done(); }
            });
    }

    /* ── nav ────────────────────────────────────────────────────────── */

    function shift(delta) {
        var m = state.month + delta;
        var y = state.year;
        while (m > 12) { m -= 12; y += 1; }
        while (m < 1) { m += 12; y -= 1; }
        state.year = y;
        state.month = m;
        state.selectedDate = null;
        qsa('.tap-ac-day-selected').forEach(function (cell) {
            cell.classList.remove('tap-ac-day-selected');
            cell.setAttribute('aria-pressed', 'false');
        });
        loadMonth(y, m);
    }

    function bindNav() {
        var prev = qs('.tap-ac-prev');
        var next = qs('.tap-ac-next');
        on(prev, 'click', function () { shift(-1); });
        on(next, 'click', function () { shift(1); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
