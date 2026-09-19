/* TAP agency back-office pricing & availability calendar.
 * Plain JS; loads per-room monthly data via tap_agency_get_pricing and writes
 * single or bulk cells via tap_agency_save_pricing (tap_front_dash_nonce). */
(function () {
    'use strict';

    var cfg = window.tapAgencyCal;
    if (!cfg || !cfg.rooms || !cfg.rooms.length) return;

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

    var months = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    var dows = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function fmtMoney(v) {
        return '$' + parseFloat(v || 0).toFixed(2);
    }

    /* ── initialisation ─────────────────────────────────────────────── */

    function init() {
        var now = new Date();
        state.year = now.getFullYear();
        state.month = now.getMonth() + 1;

        renderTabs();
        bindNav();
        bindEdit();
        bindRange();
        bindDow();
        bindClearAll();
        loadMonth(state.year, state.month);
    }

    function renderTabs() {
        var box = qs('#tap-ac-room-tabs');
        if (!box) return;
        box.textContent = '';
        cfg.rooms.forEach(function (room) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'tap-ac-room-tab' + (room.id === state.roomId ? ' active' : '');
            b.textContent = room.title;
            b.addEventListener('click', function () {
                if (room.id === state.roomId) return;
                state.roomId = room.id;
                state.selectedDate = null;
                qsa('.tap-ac-room-tab', box).forEach(function (t) { t.classList.toggle('active', t === b); });
                hideEdit();
                loadMonth(state.year, state.month);
            });
            box.appendChild(b);
        });
    }

    /* ── grid ───────────────────────────────────────────────────────── */

    function loadMonth(year, month) {
        var cal = qs('#tap-ac-calendar');
        cal.innerHTML = '<div class="tap-ac-loading">' + escapeHtml(cfg.i18n.loading) + '</div>';

        var fd = new FormData();
        fd.append('action', 'tap_agency_get_pricing');
        fd.append('nonce', cfg.nonce);
        fd.append('room_id', state.roomId);
        fd.append('year', year);
        fd.append('month', month);

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.success) {
                cal.innerHTML = '<div class="tap-ac-loading">' + escapeHtml(cfg.i18n.error) + '</div>';
                return;
            }
            var d = j.data;
            state.days = d.days;
            state.basePrice = parseFloat(d.base_price) || 0;
            state.baseMin = parseInt(d.base_min, 10) || 1;
            state.inventory = parseInt(d.inventory, 10) || 1;
            state.year = d.year;
            state.month = d.month;
            var label = qs('.tap-ac-month-label');
            if (label) label.textContent = months[d.month - 1] + ' ' + d.year;
            renderGrid();
        });
    }

    function renderGrid() {
        var cal = qs('#tap-ac-calendar');
        var html = '<div class="tap-ac-weekdays">';
        for (var i = 0; i < 7; i++) html += '<div class="tap-ac-weekday">' + dows[i] + '</div>';
        html += '</div><div class="tap-ac-days">';

        var firstDoW = new Date(state.year, state.month - 1, 1).getDay();
        for (var p = 0; p < firstDoW; p++) html += '<div class="tap-ac-day tap-ac-day-ghost"></div>';

        var today = new Date();
        var todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');

        state.days.forEach(function (day) {
            var cls = 'tap-ac-day';
            if (day.overridden && day.is_blocked) cls += ' tap-ac-day-blocked';
            else if (day.overridden) cls += ' tap-ac-day-override';
            if (day.date === todayStr) cls += ' tap-ac-day-today';
            if (state.selectedDate === day.date) cls += ' tap-ac-day-selected';

            var meta = [];
            if (day.is_blocked) meta.push(escapeHtml(cfg.i18n.blocked));
            else if (day.booked) meta.push(escapeHtml(day.booked) + ' ' + escapeHtml(cfg.i18n.booked));

            html += '<div class="' + cls + '" data-date="' + day.date + '" title="' + day.date + '">'
                 + '<span class="tap-ac-day-number">' + day.day + '</span>'
                 + '<span class="tap-ac-day-price">' + fmtMoney(day.price) + '</span>'
                 + (day.label ? '<span class="tap-ac-day-label">' + escapeHtml(day.label) + '</span>' : '')
                 + (meta.length ? '<span class="tap-ac-day-meta">' + meta.join(' · ') + '</span>' : '')
                 + '</div>';
        });

        html += '</div>';
        cal.innerHTML = html;

        qsa('.tap-ac-day[data-date]', cal).forEach(function (cell) {
            cell.addEventListener('click', function () { selectDate(cell.getAttribute('data-date')); });
        });
    }

    /* ── single-day edit panel ──────────────────────────────────────── */

    function dayFor(date) {
        var found = null;
        state.days.forEach(function (d) { if (d.date === date) found = d; });
        return found;
    }

    function hideEdit() {
        var panel = qs('#tap-ac-edit');
        if (panel) panel.hidden = true;
        state.selectedDate = null;
    }

    function selectDate(date) {
        var day = dayFor(date);
        if (!day) return;
        state.selectedDate = date;
        qsa('.tap-ac-day[data-date]').forEach(function (c) {
            c.classList.toggle('tap-ac-day-selected', c.getAttribute('data-date') === date);
        });

        var parts = date.split('-');
        var panel = qs('#tap-ac-edit');
        var dateLabel = qs('.tap-ac-edit-date');
        if (dateLabel) dateLabel.textContent = parts[2] + ' de ' + months[parseInt(parts[1], 10) - 1] + ' ' + parts[0];
        qs('.tap-ac-edit-date-input').value = date;
        if (day.overridden) {
            qs('.tap-ac-edit-price').value = day.price !== null && day.price !== state.basePrice ? day.price : '';
            qs('.tap-ac-edit-minstay').value = day.min_stay !== null && day.min_stay !== state.baseMin ? day.min_stay : '';
            qs('.tap-ac-edit-avail').value = day.is_blocked ? 'no' : (day.overridden ? 'yes' : '');
            qs('.tap-ac-edit-label').value = day.label || '';
        } else {
            qs('.tap-ac-edit-price').value = '';
            qs('.tap-ac-edit-minstay').value = '';
            qs('.tap-ac-edit-avail').value = '';
            qs('.tap-ac-edit-label').value = '';
        }
        setMsg('#tap-ac-edit', '');
        panel.hidden = false;
    }

    function bindEdit() {
        qs('.tap-ac-save').addEventListener('click', function () {
            var date = qs('.tap-ac-edit-date-input').value;
            var price = qs('.tap-ac-edit-price').value;
            var minstay = qs('.tap-ac-edit-minstay').value;
            var avail = qs('.tap-ac-edit-avail').value;
            var label = qs('.tap-ac-edit-label').value;
            postCells([{
                date: date,
                price: price !== '' ? price : '',
                min_stay: minstay !== '' ? minstay : '',
                is_blocked: avail === 'no' ? 1 : 0,
                label: label
            }], '#tap-ac-edit');
        });

        qs('.tap-ac-clear').addEventListener('click', function () {
            var date = qs('.tap-ac-edit-date-input').value;
            postCells([{ date: date, price: '', min_stay: '', is_blocked: 0, label: '' }], '#tap-ac-edit');
        });
    }

    /* ── range ──────────────────────────────────────────────────────── */

    function bindRange() {
        qs('.tap-ac-range-apply').addEventListener('click', function () {
            var from = qs('.tap-ac-range-from').value;
            var to = qs('.tap-ac-range-to').value;
            if (!from || !to || from > to) {
                setMsg('.tap-ac-panel', cfg.i18n.error);
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
                    date: d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'),
                    price: price !== '' ? price : '',
                    min_stay: minstay !== '' ? minstay : '',
                    is_blocked: avail === 'no' ? 1 : 0,
                    label: label !== '' ? label : (avail === 'no' ? 'Bloqueado' : '')
                });
                d.setDate(d.getDate() + 1);
            }
            postCells(cells, '#tap-ac-range-actions');
        });
    }

    /* ── day-of-week rule ───────────────────────────────────────────── */

    function bindDow() {
        qs('.tap-ac-dow-apply').addEventListener('click', function () {
            var dowSels = qsa('#tap-ac-dow input:checked').map(function (c) { return parseInt(c.value, 10); });
            if (!dowSels.length) {
                setMsg('#tap-ac-dow-actions', cfg.i18n.noOverrideTarget);
                return;
            }
            var op = qs('.tap-ac-dow-op').value;
            var amount = parseFloat(qs('.tap-ac-dow-amount').value);
            var minstay = qs('.tap-ac-dow-minstay').value;
            var avail = qs('.tap-ac-dow-avail').value;

            if (op !== 'clear' && amount <= 0) {
                setMsg('#tap-ac-dow-actions', cfg.i18n.error);
                return;
            }

            var cells = [];
            state.days.forEach(function (day) {
                if (dowSels.indexOf(day.dow) === -1) return;
                var cell = { date: day.date, is_blocked: 0 };
                if (op === 'clear') {
                    cell.price = ''; cell.min_stay = ''; cell.is_blocked = 0; cell.label = '';
                } else {
                    if (op === 'set') {
                        cell.price = amount !== '' ? amount : '';
                    } else {
                        var pct = amount / 100;
                        cell.price = op === 'add_pct'
                            ? Math.round((parseFloat(day.price) * (1 + pct)) * 100) / 100
                            : Math.round((parseFloat(day.price) * (1 - pct)) * 100) / 100;
                    }
                    cell.min_stay = minstay !== '' ? minstay : '';
                    cell.is_blocked = avail === 'no' ? 1 : 0;
                    cell.label = '';
                }
                cells.push(cell);
            });

            postCells(cells, '#tap-ac-dow-actions');
        });
    }

    /* ── clear month ────────────────────────────────────────────────── */

    function bindClearAll() {
        qs('.tap-ac-clear-all').addEventListener('click', function () {
            var cells = [];
            state.days.forEach(function (day) { if (day.overridden) cells.push({ date: day.date }); });
            if (!cells.length) {
                setMsg('#tap-ac-clear-actions', cfg.i18n.error);
                return;
            }
            postCells(cells.map(function (c) {
                return { date: c.date, price: '', min_stay: '', is_blocked: 0, label: '' };
            }), '#tap-ac-clear-actions');
        });
    }

    /* ── shared save ────────────────────────────────────────────────── */

    function setMsg(scopeSel, text, inbound) {
        var scope = typeof scopeSel === 'string' ? qs(scopeSel) : scopeSel;
        if (!scope) return;
        var msg = inbound ? qs(inbound, scope) : qs('.tap-ac-msg', scope);
        if (msg) msg.textContent = text;
    }

    function postCells(cells, msgScope) {
        if (!cells || !cells.length) return;
        setMsg(msgScope, '…');

        var fd = new FormData();
        fd.append('action', 'tap_agency_save_pricing');
        fd.append('nonce', cfg.nonce);
        fd.append('room_id', state.roomId);
        fd.append('bulk', '1');
        fd.append('cells', JSON.stringify(cells));

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j.success) {
                var app = j.data && j.data.applied;
                var total = app ? (app.created + app.updated + app.deleted + app.noop) : 0;
                setMsg(msgScope, (cfg.i18n.saved || '') + ' · ' + total);
                loadMonth(state.year, state.month);
            } else {
                setMsg(msgScope, cfg.i18n.error);
            }
        })
        .catch(function () { setMsg(msgScope, cfg.i18n.error); });
    }

    /* ── nav ────────────────────────────────────────────────────────── */

    function bindNav() {
        qs('.tap-ac-prev').addEventListener('click', function () {
            state.month -= 1;
            if (state.month < 1) { state.month = 12; state.year -= 1; }
            state.selectedDate = null;
            hideEdit();
            loadMonth(state.year, state.month);
        });
        qs('.tap-ac-next').addEventListener('click', function () {
            state.month += 1;
            if (state.month > 12) { state.month = 1; state.year += 1; }
            state.selectedDate = null;
            hideEdit();
            loadMonth(state.year, state.month);
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();