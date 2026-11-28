/**
 * TAP Frontend Dashboard — JS interactions
 * @since 1.5.0
 */
(function ($) {
    'use strict';

    var cfg = window.tapDash || {};

    function tapToast(msg, type) {
        var el = document.querySelector('.tap-dash-toast');
        if (!el) {
            el = document.createElement('div');
            el.className = 'tap-dash-toast';
            document.body.appendChild(el);
        }
        el.className = 'tap-dash-toast' + (type ? ' tap-dash-toast-' + type : '');
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.textContent = msg || '';
        void el.offsetWidth;
        el.classList.add('show');
        clearTimeout(el._t);
        el._t = setTimeout(function(){ el.classList.remove('show'); }, type === 'error' ? 5000 : 3000);
    }
    window.tapToast = tapToast;

    /* ── Accessible in-page dialogs (replace native confirm/prompt) ── */
    function tapDialog(title, message, value, isPrompt, onAccept) {
        var i18n = cfg.i18n || {};
        var overlay = document.createElement('div');
        overlay.className = 'tap-modal-overlay';
        overlay.setAttribute('role', 'presentation');
        var field = isPrompt
            ? '<input class="tap-modal-input" type="text" maxlength="200" value="' + String(value || '').replace(/"/g, '&quot;') + '">'
            : '';
        overlay.innerHTML = '<div class="tap-modal" role="dialog" aria-modal="true" aria-labelledby="tap-modal-title">'
            + '<h3 id="tap-modal-title"></h3><p class="tap-modal-message"></p>' + field
            + '<div class="tap-modal-actions">'
            + '<button type="button" class="tap-btn tap-btn-sm" data-act="cancel"></button>'
            + '<button type="button" class="tap-btn tap-btn-sm tap-btn-primary" data-act="ok"></button>'
            + '</div></div>';
        overlay.querySelector('#tap-modal-title').textContent = title || i18n.confirm_title || 'Confirmar';
        overlay.querySelector('.tap-modal-message').textContent = message || '';
        overlay.querySelector('[data-act="cancel"]').textContent = i18n.confirm_no || 'Cancelar';
        overlay.querySelector('[data-act="ok"]').textContent = i18n.confirm_yes || 'Confirmar';

        var input = overlay.querySelector('.tap-modal-input');
        var ok = overlay.querySelector('[data-act="ok"]');
        function close() {
            document.removeEventListener('keydown', onKey);
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
        }
        function accept() {
            var answer = input ? input.value.trim() : true;
            close();
            onAccept(answer);
        }
        function onKey(e) {
            if (e.key === 'Escape') { close(); }
            if (e.key === 'Enter' && input) { e.preventDefault(); accept(); }
        }
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { close(); return; }
            var act = e.target.getAttribute('data-act');
            if (act === 'ok') { accept(); }
            if (act === 'cancel') { close(); }
        });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        (input || ok).focus();
    }
    window.tapConfirm = function (title, message, onAccept) {
        tapDialog(title, message, '', false, function () { onAccept(); });
    };
    window.tapPrompt = function (title, message, value, onAccept) {
        tapDialog(title, message, value, true, function (answer) { onAccept(answer); });
    };

    /* ── Keep the active nav item visible on the mobile strip ─────── */
    $(function () {
        var active = document.querySelector('.tap-dash-nav-active');
        if (active && active.scrollIntoView) {
            active.scrollIntoView({ block: 'nearest', inline: 'center' });
        }
    });

    /* ── Command palette (Ctrl/Cmd + K) ───────────────────────────── */
    function tapCommandPalette() {
        if (document.querySelector('.tap-cmd-overlay')) { return; }
        var i18n = cfg.i18n || {};
        var entries = [];
        document.querySelectorAll('.tap-dash-nav-item').forEach(function (a) {
            entries.push({ label: (a.textContent || '').trim().replace(/\s+/g, ' '), url: a.getAttribute('href') || '', kind: i18n.kind_section || 'Sección' });
        });
        document.querySelectorAll('a.tap-booking-code').forEach(function (a) {
            entries.push({ label: (a.textContent || '').trim(), url: a.getAttribute('href') || '', kind: i18n.kind_booking || 'Reserva' });
        });
        document.querySelectorAll('.tap-table tbody tr').forEach(function (tr) {
            var link = tr.querySelector('a[href]');
            var text = (tr.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 90);
            if (link && text) { entries.push({ label: text, url: link.getAttribute('href'), kind: i18n.kind_row || 'Fila' }); }
        });
        var seen = {};
        entries = entries.filter(function (e) {
            if (!e.label || !e.url || seen[e.url]) { return false; }
            seen[e.url] = true; return true;
        });

        var overlay = document.createElement('div');
        overlay.className = 'tap-cmd-overlay';
        overlay.innerHTML = '<div class="tap-cmd" role="dialog" aria-modal="true" aria-label="">'
            + '<input class="tap-cmd-input" type="search" placeholder="Buscar secciones, reservas o listados…" autocomplete="off">'
            + '<ul class="tap-cmd-results" role="listbox"></ul>'
            + '<p class="tap-cmd-hint">Esc para cerrar · ↑ ↓ para navegar · Enter para abrir</p></div>';
        overlay.querySelector('.tap-cmd').setAttribute('aria-label', i18n.search_aria || 'Buscar');
        overlay.querySelector('.tap-cmd-input').placeholder = i18n.search_placeholder || 'Buscar…';
        overlay.querySelector('.tap-cmd-hint').textContent = i18n.search_hint || '';
        document.body.appendChild(overlay);
        var input = overlay.querySelector('.tap-cmd-input');
        var list = overlay.querySelector('.tap-cmd-results');
        var matches = [];
        var active = 0;

        var remote = [];
        var requestId = 0;
        var timer = null;
        function render() {
            var q = (input.value || '').trim().toLowerCase();
            var all = entries.concat(remote);
            var seenAll = {};
            matches = all.filter(function (e) {
                if (!e.label || !e.url || seenAll[e.url]) { return false; }
                seenAll[e.url] = true;
                return !q || e.label.toLowerCase().indexOf(q) !== -1;
            }).slice(0, 8);
            active = 0;
            list.innerHTML = '';
            matches.forEach(function (e, i) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.className = 'tap-cmd-item' + (i === 0 ? ' is-active' : '');
                li.innerHTML = '<span class="tap-cmd-kind"></span><span class="tap-cmd-label"></span>';
                li.querySelector('.tap-cmd-kind').textContent = e.kind;
                li.querySelector('.tap-cmd-label').textContent = e.label;
                li.addEventListener('click', function () { window.location.href = e.url; });
                list.appendChild(li);
            });
            if (!matches.length) { list.innerHTML = '';
            var empty = document.createElement('li');
            empty.className = 'tap-cmd-empty';
            empty.textContent = i18n.search_empty || 'Sin resultados';
            list.appendChild(empty); }
        }
        function close() { if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); } }
        function move(delta) {
            if (!matches.length) { return; }
            active = (active + delta + matches.length) % matches.length;
            Array.prototype.forEach.call(list.querySelectorAll('.tap-cmd-item'), function (li, i) {
                li.classList.toggle('is-active', i === active);
            });
        }
        input.addEventListener('input', function () {
            render();
            clearTimeout(timer);
            var q = (input.value || '').trim();
            if (q.length < 2 || !cfg.ajax_url) { remote = []; render(); return; }
            var mine = ++requestId;
            timer = setTimeout(function () {
                var fd = new FormData();
                fd.append('action', 'tap_dash_global_search');
                fd.append('nonce', cfg.nonce || '');
                fd.append('q', q);
                fetch(cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (mine !== requestId) { return; }
                        remote = (j && j.success && j.data && j.data.items) ? j.data.items.map(function (x) {
                            return { label: x.label || '', url: x.url || '', kind: x.kind || '' };
                        }) : [];
                        render();
                    })
                    .catch(function () { remote = []; });
            }, 180);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
            if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
            if (e.key === 'Enter' && matches[active]) { e.preventDefault(); window.location.href = matches[active].url; }
            if (e.key === 'Escape') { close(); }
        });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
        render();
        input.focus();
    }
    $(document).on('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault(); tapCommandPalette();
        }
    });

    /* ── Grouped navigation (desktop collapse + mobile "more") ────── */
    $(document).on('click', '.tap-dash-nav-group-toggle', function () {
        var $group = $(this).closest('.tap-dash-nav-group');
        var collapsed = $group.toggleClass('is-collapsed').hasClass('is-collapsed');
        $(this).attr('aria-expanded', collapsed ? 'false' : 'true');
        try { localStorage.setItem('tap-nav-' + ($group.data('group') || 'group'), collapsed ? '1' : '0'); } catch (e) {}
    });
    $(function () {
        $('.tap-dash-nav-group').each(function () {
            var key = 'tap-nav-' + ($(this).data('group') || 'group');
            try {
                if (localStorage.getItem(key) === '1') {
                    $(this).addClass('is-collapsed');
                    $(this).find('.tap-dash-nav-group-toggle').attr('aria-expanded', 'false');
                }
            } catch (e) {}
        });
    });
    $(document).on('click', '.tap-dash-nav-more', function () {
        var panel = document.getElementById('tap-dash-more-panel');
        if (!panel) { return; }
        var open = panel.hasAttribute('hidden');
        if (open) { panel.removeAttribute('hidden'); } else { panel.setAttribute('hidden', ''); }
        $(this).attr('aria-expanded', open ? 'true' : 'false');
        if (open) { var first = panel.querySelector('a'); if (first) { first.focus(); } }
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            var panel = document.getElementById('tap-dash-more-panel');
            if (panel && !panel.hasAttribute('hidden')) {
                panel.setAttribute('hidden', '');
                $('.tap-dash-nav-more').attr('aria-expanded', 'false').trigger('focus');
            }
        }
    });

    /* ── Notification bell (agency context bar) ───────────────────── */
    $(document).on('click', '.tap-notif-bell[aria-haspopup="true"]', function (e) {
        e.stopPropagation();
        var $bell = $(this);
        var $dd = $bell.closest('.tap-notif-wrap').find('.tap-notif-dropdown');
        var open = !$dd.length || $dd[0].hasAttribute('hidden');
        $('.tap-notif-dropdown').attr('hidden', '');
        if ($dd.length) {
            if (open) { $dd[0].removeAttribute('hidden'); } else { $dd[0].setAttribute('hidden', ''); }
        }
        $bell.attr('aria-expanded', open ? 'true' : 'false');
    });
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.tap-notif-wrap').length) {
            $('.tap-notif-dropdown').attr('hidden', '');
            $('.tap-notif-bell[aria-haspopup="true"]').attr('aria-expanded', 'false');
        }
    });

    /* ── Save client profile ──────────────────────────────────────── */
    $(document).on('submit', '.tap-dash-profile-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn  = $form.find('button[type="submit"]');
        var $msg  = $form.find('.tap-form-msg');

        $btn.prop('disabled', true);
        $msg.removeClass('is-error').text('');

        $.post(cfg.ajax_url, {
            action:     'tap_dash_client_profile_save',
            nonce:      cfg.nonce,
            name:             $form.find('[name="name"]').val(),
            email:            $form.find('[name="email"]').val(),
            current_password: $form.find('[name="current_password"]').val(),
            password:         $form.find('[name="password"]').val(),
            password2:        $form.find('[name="password2"]').val()
        }).done(function (res) {
            if (res && res.success) {
                $msg.text(res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.saved));
                $form.find('[name="password"], [name="password2"]').val('');
            } else {
                $msg.addClass('is-error').text(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error));
            }
        }).fail(function () {
            $msg.addClass('is-error').text(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* ── Cancel booking ───────────────────────────────────────────── */
    $(document).on('click', '.tap-dash-cancel', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        var code = $btn.data('code') || '';
        var originalLabel = $btn.text();
        window.tapConfirm(cfg.i18n && cfg.i18n.confirm_title, (cfg.i18n && cfg.i18n.confirm_cancel) + ' ' + code + '?', function () {
            $btn.prop('disabled', true).text('…');
            $.post(cfg.ajax_url, {
                action:     'tap_dash_cancel_booking',
                nonce:      cfg.nonce,
                booking_id: id
            }).done(function (res) {
                if (res && res.success) {
                    $btn.closest('.tap-dash-booking-card').fadeOut(300, function () { $(this).remove(); });
                } else {
                    tapToast(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error), 'error');
                    $btn.prop('disabled', false).text(originalLabel);
                }
            }).fail(function () {
                tapToast(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', 'error');
                $btn.prop('disabled', false).text(originalLabel);
            });
        });
    });

    /* ── Toggle favorite ──────────────────────────────────────────── */
    $(document).on('click', '.tap-dash-fav-remove', function () {
        var $btn   = $(this);
        var postId = $btn.data('id');

        $btn.prop('disabled', true);

        $.post(cfg.ajax_url, {
            action:  'tap_dash_toggle_favorite',
            nonce:   cfg.nonce,
            post_id: postId
        }).done(function (res) {
            if (res && res.success) {
                $btn.closest('.tap-dash-fav-card').fadeOut(300, function () {
                    $(this).remove();
                });
            } else {
                tapToast(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error), 'error');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            tapToast(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', 'error');
            $btn.prop('disabled', false);
        });
    });

    /* ── Delete review ────────────────────────────────────────────── */
    $(document).on('click', '.tap-dash-review-delete', function () {
        var $btn    = $(this);
        var reviewId = $btn.data('id');
        window.tapConfirm(cfg.i18n && cfg.i18n.confirm_title, cfg.i18n && cfg.i18n.confirm_delete ? cfg.i18n.confirm_delete : '¿Eliminar?', function () {
            $btn.prop('disabled', true);
            $.post(cfg.ajax_url, {
                action:    'tap_dash_delete_review',
                nonce:     cfg.nonce,
                review_id: reviewId
            }).done(function (res) {
                if (res && res.success) {
                    $btn.closest('.tap-dash-review-card').fadeOut(300, function () { $(this).remove(); });
                } else {
                    tapToast(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error), 'error');
                    $btn.prop('disabled', false);
                }
            }).fail(function () {
                tapToast(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', 'error');
                $btn.prop('disabled', false);
            });
        });
    });

    /* ── Optimistic booking row update (no full page reload) ──────── */
    function tapUpdateBookingRow($btn, op) {
        var $row = $btn.closest('tr');
        if (!$row.length) { return; }
        var i18n = cfg.i18n || {};
        var labels = {
            confirm: i18n.op_confirmed || 'Confirmada',
            complete: i18n.op_completed || 'Completada',
            cancel: i18n.op_cancelled || 'Cancelada',
            reject: i18n.op_rejected || 'Rechazada',
            no_show: i18n.op_noshow || 'No show'
        };
        if (labels[op]) {
            var $status = $row.find('td[data-label] .tap-status').last();
            if ($status.length) {
                $status.text(labels[op]);
                $status.removeClass('tap-status-pending tap-status-confirmed tap-status-cancelled');
                $status.addClass(op === 'confirm' || op === 'complete' ? 'tap-status-confirmed' : 'tap-status-cancelled');
            }
        }
        if (op === 'mark_paid' || op === 'settle_offline') {
            var $pay = $row.find('td[data-label] .tap-status').first();
            if ($pay.length) {
                $pay.text(i18n.op_paid || 'Pagada');
                $pay.removeClass('tap-status-pending tap-status-cancelled').addClass('tap-status-paid');
            }
        }
        $row.find('.tap-bo-api').each(function () {
            var next = $(this).data('op');
            var hide = op === 'cancel' || op === 'reject'
                || (op === 'confirm' && (next === 'confirm' || next === 'reject'))
                || (op === 'complete' && next === 'complete')
                || ((op === 'mark_paid' || op === 'settle_offline') && (next === 'mark_paid' || next === 'settle_offline'));
            if (hide) { $(this).remove(); }
        });
        tapToast(i18n.saved || 'Guardado', 'success');
    }

    /* ── Agency back-office: booking ops ──────────────────────────── */
    $(document).on('click', '.tap-bo-api', function () {
        var $btn  = $(this);
        var op    = $btn.data('op');
        var id    = $btn.data('id');
        var name  = $btn.data('name') || '';
        var reason = '';
        var originalLabel = $btn.text();
        var i18n = cfg.i18n || {};

        function run() {
            window.tapConfirm(i18n.confirm_title, (i18n.confirm_op || '¿Realizar esta operación?') + ' ' + name + '?', function () {
                $btn.prop('disabled', true).text('…');
                var data = {
                    action:     'tap_dash_agency_booking',
                    nonce:      cfg.nonce,
                    booking_id: id,
                    op:         op
                };
                if ((op === 'cancel' || op === 'settle_offline') && reason !== '') {
                    data.reason = reason;
                }
                $.post(cfg.ajax_url, data).done(function (res) {
                    if (res && res.success) {
                        tapUpdateBookingRow($btn, op);
                        $btn.prop('disabled', false).text(originalLabel);
                    } else {
                        tapToast(res && res.data && res.data.message ? res.data.message : i18n.error, 'error');
                        $btn.prop('disabled', false).text(originalLabel);
                    }
                }).fail(function () {
                    tapToast(i18n.error || 'Error', 'error');
                    $btn.prop('disabled', false).text(originalLabel);
                });
            });
        }

        if (op === 'cancel' || op === 'reject' || op === 'settle_offline') {
            var message = op === 'reject' ? (i18n.reject_reason || 'Motivo del rechazo:')
                : (op === 'settle_offline' ? (i18n.offline_receipt || 'Comprobante de la liquidación offline:')
                : (i18n.cancel_reason || 'Motivo de cancelación:'));
            window.tapPrompt(i18n.prompt_title, message, '', function (answer) {
                if (op === 'settle_offline' && !answer) {
                    tapToast(i18n.offline_receipt || 'Comprobante obligatorio', 'error');
                    return;
                }
                reason = answer || '';
                run();
            });
        } else {
            run();
        }
    });

    /* ── Agency back-office: guest chat ────────────────────────────── */
    /* ── Chat: open/close with keyboard support (focus management) ──── */
    function tapChatFocusInput($row) {
        $row.find('textarea[name="message"]').trigger('focus');
    }

    function tapChatClose($row) {
        var id = $row.data('id');
        $row.hide();
        $('.tap-chat-toggle[data-id="' + id + '"]').trigger('focus');
    }

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            var $open = $('.tap-chat-row:visible').last();
            if ($open.length) {
                tapChatClose($open);
            }
        }
    });

    $(document).on('click', '.tap-chat-toggle', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        var $row = $('.tap-chat-row[data-id="' + id + '"]');
        if (!$row.length) {
            return;
        }
        if ($row.is(':visible')) {
            tapChatClose($row);
            return;
        }
        var loaded = $row.data('loaded');
        if (loaded) {
            $row.show();
            $btn.find('.tap-chat-badge').remove();
            tapChatFocusInput($row);
            return;
        }
        $.post(cfg.ajax_url, {
            action:     'tap_dash_chat_thread',
            nonce:      cfg.nonce,
            booking_id: id
        }).done(function (res) {
            if (!res || !res.success) {
                tapToast(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error), 'error');
                return;
            }
            var $thread = $row.find('.tap-chat-thread');
            $thread.html(res.data.html || '<p class="tap-empty">' + (cfg.i18n && cfg.i18n.no_messages || '') + '</p>');
            $row.data('loaded', 1).data('count', parseInt(res.data.count, 10) || 0).show();
            $btn.find('.tap-chat-badge').remove();
            var $tail = $thread.find('.tap-chat-msg:last');
            if ($tail.length) {
                $tail[0].scrollIntoView({ block: 'nearest' });
            }
            tapChatFocusInput($row);
        }).fail(function () {
            tapToast(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', 'error');
        });
    });

    $(document).on('submit', '.tap-dash-chat-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $ta   = $form.find('textarea[name="message"]');
        var msg   = $.trim($ta.val());
        if (!msg) {
            return;
        }
        var $btn = $form.find('button[type="submit"]').prop('disabled', true);
        $.post(cfg.ajax_url, {
            action:     'tap_dash_chat_send',
            nonce:      cfg.nonce,
            booking_id: $form.data('booking-id'),
            message:    msg
        }).done(function (res) {
            if (res && res.success) {
                var $wrap   = $form.closest('.tap-chat-wrap');
                var $thread = $wrap.find('.tap-chat-thread');
                $thread.find('.tap-empty').remove();
                $thread.append(res.data.row);
                $ta.val('');
                var $row = $form.closest('.tap-chat-row');
                $row.data('count', ($thread.find('.tap-chat-msg').length));
            } else {
                tapToast(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error), 'error');
            }
        }).fail(function () {
            tapToast(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', 'error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* ── Client chat with agency ──────────────────────────────────── */
    $(document).on('click', '.tap-client-chat-toggle', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        var $row = $('.tap-client-chat-row[data-id="' + id + '"]');
        if (!$row.length) { return; }
        if ($row.is(':visible')) {
            $row.hide();
            return;
        }
        if ($row.data('loaded')) {
            $row.show();
            $btn.find('.tap-chat-badge').remove();
            return;
        }
        $.post(cfg.ajax_url, {
            action:     'tap_dash_client_chat_thread',
            nonce:      cfg.nonce,
            booking_id: id
        }).done(function (res) {
            if (!res || !res.success) {
                tapToast(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error), 'error');
                return;
            }
            var $thread = $row.find('.tap-chat-thread');
            $thread.html(res.data.html || '<p class="tap-empty">' + (cfg.i18n && cfg.i18n.no_messages || '') + '</p>');
            $row.data('loaded', 1).data('count', parseInt(res.data.count, 10) || 0).show();
            $btn.find('.tap-chat-badge').remove();
            var $tail = $thread.find('.tap-chat-msg:last');
            if ($tail.length) { $tail[0].scrollIntoView({ block: 'nearest' }); }
        }).fail(function () {
            tapToast(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', 'error');
        });
    });

    $(document).on('submit', '.tap-client-chat-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $ta   = $form.find('textarea[name="message"]');
        var msg   = $.trim($ta.val());
        if (!msg) { return; }
        var $btn = $form.find('button[type="submit"]').prop('disabled', true);
        $.post(cfg.ajax_url, {
            action:     'tap_dash_client_chat_send',
            nonce:      cfg.nonce,
            booking_id: $form.data('booking-id'),
            message:    msg
        }).done(function (res) {
            if (res && res.success) {
                var $wrap   = $form.closest('.tap-chat-wrap');
                var $thread = $wrap.find('.tap-chat-thread');
                $thread.find('.tap-empty').remove();
                $thread.append(res.data.row);
                $ta.val('');
            } else {
                tapToast(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error), 'error');
            }
        }).fail(function () {
            tapToast(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', 'error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* ── Agency back-office: live chat refresh ─────────────────────── */
    function tapChatBadge(total) {
        total = parseInt(total, 10) || 0;
        var $badge = $('[data-tap-chat-nav]');
        if (!$badge.length) {
            return;
        }
        if (total > 0) {
            $badge.text(total).prop('hidden', false);
        } else {
            $badge.text('').prop('hidden', true);
        }
    }

    function tapChatPoll() {
        $('.tap-chat-row:visible').each(function () {
            var $row = $(this);
            var seen = parseInt($row.data('count'), 10) || 0;
            $.post(cfg.ajax_url, {
                action:     'tap_dash_chat_thread',
                nonce:      cfg.nonce,
                booking_id: $row.data('id')
            }).done(function (res) {
                if (!res || !res.success) {
                    return;
                }
                var count = parseInt(res.data.count, 10) || 0;
                if (count === seen) {
                    return;
                }
                var $thread = $row.find('.tap-chat-thread');
                var $doc = $(document);
                var nearBottom = ($doc.scrollTop() + window.innerHeight + 120) >= $doc.height();
                $thread.html(res.data.html || '<p class="tap-empty">' + (cfg.i18n && cfg.i18n.no_messages || '') + '</p>');
                $row.data('count', count);
                $row.find('.tap-chat-badge').remove();
                if (nearBottom) {
                    var $tail = $thread.find('.tap-chat-msg:last');
                    if ($tail.length) {
                        $tail[0].scrollIntoView({ block: 'nearest' });
                    }
                }
            });
        });
    }

    // Live refresh: pauses while the tab is hidden and backs off on errors.
    var tapChatHasWidgets = $('[data-tap-chat-nav], .tap-chat-row').length > 0;
    var tapChatBase       = 15000;
    var tapChatMax        = 120000;
    var tapChatDelay      = tapChatBase;
    var tapChatTimer      = null;

    function tapChatSchedule() {
        if (tapChatTimer) {
            clearTimeout(tapChatTimer);
            tapChatTimer = null;
        }
        if (!tapChatHasWidgets || document.hidden) {
            return;
        }
        tapChatTimer = setTimeout(tapChatTick, tapChatDelay);
    }

    function tapChatTick() {
        if (!cfg.ajax_url) {
            return;
        }
        $.post(cfg.ajax_url, { action: 'tap_dash_chat_unread', nonce: cfg.nonce })
            .done(function (res) {
                if (res && res.success) {
                    tapChatBadge(res.data.total);
                }
            })
            .always(function (xhr, status) {
                tapChatDelay = (status === 'error' || status === 'timeout')
                    ? Math.min(tapChatDelay * 2, tapChatMax)
                    : tapChatBase;
                tapChatPoll();
                tapChatSchedule();
            });
    }

    if (tapChatHasWidgets) {
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                if (tapChatTimer) {
                    clearTimeout(tapChatTimer);
                    tapChatTimer = null;
                }
            } else {
                tapChatDelay = tapChatBase;
                tapChatTick();
            }
        });
        tapChatSchedule();
    }

    /* ── Agency back-office: team management ──────────────────────── */
    $(document).on('submit', '#tap-team-add', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn  = $form.find('button[type="submit"]').prop('disabled', true);
        var email = $form.find('[name="email"]').val() || '';

        $.post(cfg.ajax_url, {
            action: 'tap_dash_team_add',
            nonce:  cfg.nonce,
            email:  email
        }).done(function (res) {
            if (res && res.success) {
                location.reload();
            } else {
                tapToast(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error), 'error');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            tapToast(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', 'error');
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.tap-team-remove', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        var name = $btn.data('name') || '';

        window.tapConfirm(cfg.i18n && cfg.i18n.confirm_title, (cfg.i18n && cfg.i18n.confirm_team_remove ? cfg.i18n.confirm_team_remove : '¿Quitar a este usuario del equipo?') + ' ' + name + '?', function () {
            $btn.prop('disabled', true);
            $.post(cfg.ajax_url, {
                action:  'tap_dash_team_remove',
                nonce:   cfg.nonce,
                user_id: id
            }).done(function (res) {
                if (res && res.success) {
                    $btn.closest('tr').fadeOut(250, function () { $(this).remove(); });
                    tapToast(cfg.i18n && cfg.i18n.saved ? cfg.i18n.saved : 'Guardado', 'success');
                } else {
                    tapToast(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error), 'error');
                    $btn.prop('disabled', false);
                }
            }).fail(function () {
                tapToast(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', 'error');
                $btn.prop('disabled', false);
            });
        });
    });

    /* ── Agency back-office: destacadoss (promos) ───────────────────── */
    $(document).on('submit', '.tap-promo-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn  = $form.find('.tap-promo-btn').prop('disabled', true);

        $.post(cfg.ajax_url, {
            action:     'tap_promo_request',
            nonce:      cfg.nonce,
            listing_id: $form.find('[name="listing_id"]').val(),
            months:     $form.find('[name="months"]').val()
        }).done(function (res) {
            if (res && res.success) {
                location.reload();
            } else {
                tapToast(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error), 'error');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            tapToast(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', 'error');
            $btn.prop('disabled', false);
        });
    });

    var tapPromoPayPalInit = false;
    function tapInitPromoPayPal() {
        if (typeof window.tapPayPal === 'undefined' || tapPromoPayPalInit) {
            return;
        }
        tapPromoPayPalInit = true;
        document.querySelectorAll('.tap-promo-paypal-btn').forEach(function (holder) {
            var $row       = $(holder).closest('form.tap-promo-paypal-form');
            var listingId  = holder.getAttribute('data-listing-id');
            window.tapPayPal.Buttons({
                style: { label: 'paypal', layout: 'horizontal', height: 32 },
                createOrder: function () {
                    return $.post(cfg.ajax_url, {
                        action:     'tap_promo_paypal',
                        nonce:      cfg.nonce,
                        listing_id: listingId,
                        months:     $row.find('[name="months"]').val() || 1
                    }).then(function (res) {
                        if (res && res.success) {
                            return res.data.order_id;
                        }
                        throw new Error(res && res.data && res.data.message ? res.data.message : 'Error');
                    });
                },
                onApprove: function (data) {
                    return $.post(cfg.ajax_url, {
                        action:           'tap_capture_promo_paypal',
                        nonce:            cfg.nonce,
                        paypal_order_id:  data.orderID
                    }).then(function (res) {
                        if (res && res.success) {
                            tapToast(res.data.message, 'success');
                            location.reload();
                        } else {
                            tapToast(res && res.data && res.data.message ? res.data.message : 'Error', 'error');
                        }
                    });
                }
            }).render(holder);
        });
    }
    var tapPromoPayPalCheck = setInterval(function () {
        if (typeof window.tapPayPal !== 'undefined') {
            tapInitPromoPayPal();
            clearInterval(tapPromoPayPalCheck);
        }
    }, 300);
    setTimeout(function () { clearInterval(tapPromoPayPalCheck); }, 15000);

})(jQuery);
