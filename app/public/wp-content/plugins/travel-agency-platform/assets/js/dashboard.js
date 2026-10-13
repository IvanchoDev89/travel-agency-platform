/**
 * TAP Frontend Dashboard — JS interactions
 * @since 1.5.0
 */
(function ($) {
    'use strict';

    var cfg = window.tapDash || {};

    /* ── Cancel booking ───────────────────────────────────────────── */
    $(document).on('click', '.tap-dash-cancel', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        var code = $btn.data('code') || '';
        var originalLabel = $btn.text();

        if (!confirm((cfg.i18n && cfg.i18n.confirm_cancel) + ' ' + code + '?')) {
            return;
        }

        $btn.prop('disabled', true).text('…');

        $.post(cfg.ajax_url, {
            action:     'tap_dash_cancel_booking',
            nonce:      cfg.nonce,
            booking_id: id
        }).done(function (res) {
            if (res && res.success) {
                $btn.closest('.tap-dash-booking-card').fadeOut(300, function () {
                    $(this).remove();
                });
            } else {
                alert(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error));
                $btn.prop('disabled', false).text(originalLabel);
            }
        }).fail(function () {
            alert(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error');
            $btn.prop('disabled', false).text(originalLabel);
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
                alert(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error));
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            alert(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error');
            $btn.prop('disabled', false);
        });
    });

    /* ── Delete review ────────────────────────────────────────────── */
    $(document).on('click', '.tap-dash-review-delete', function () {
        var $btn    = $(this);
        var reviewId = $btn.data('id');

        if (!confirm(cfg.i18n && cfg.i18n.confirm_delete ? cfg.i18n.confirm_delete : '¿Eliminar?')) {
            return;
        }

        $btn.prop('disabled', true);

        $.post(cfg.ajax_url, {
            action:    'tap_dash_delete_review',
            nonce:     cfg.nonce,
            review_id: reviewId
        }).done(function (res) {
            if (res && res.success) {
                $btn.closest('.tap-dash-review-card').fadeOut(300, function () {
                    $(this).remove();
                });
            } else {
                alert(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error));
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            alert(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error');
            $btn.prop('disabled', false);
        });
    });

    /* ── Agency back-office: booking ops ──────────────────────────── */
    $(document).on('click', '.tap-bo-api', function () {
        var $btn  = $(this);
        var op    = $btn.data('op');
        var id    = $btn.data('id');
        var name  = $btn.data('name') || '';
        var reason = '';
        var originalLabel = $btn.text();

        if (op === 'cancel') {
            var reasonInput = window.prompt(cfg.i18n && cfg.i18n.cancel_reason ? cfg.i18n.cancel_reason : 'Motivo de cancelación (opcional):', '');
            if (reasonInput === null) {
                return;
            }
            reason = reasonInput || '';
        }

        if (!confirm((cfg.i18n && cfg.i18n.confirm_op ? cfg.i18n.confirm_op : '¿Realizar esta operación?') + ' ' + name + '?')) {
            return;
        }

        $btn.prop('disabled', true).text('…');

        var data = {
            action:     'tap_dash_agency_booking',
            nonce:      cfg.nonce,
            booking_id: id,
            op:         op
        };
        if (op === 'cancel' && reason !== '') {
            data.reason = reason;
        }

        $.post(cfg.ajax_url, data).done(function (res) {
            if (res && res.success) {
                location.reload();
            } else {
                alert(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error));
                $btn.prop('disabled', false).text(originalLabel);
            }
        }).fail(function () {
            alert(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error');
            $btn.prop('disabled', false).text(originalLabel);
        });
    });

    /* ── Agency back-office: guest chat ────────────────────────────── */
    $(document).on('click', '.tap-chat-toggle', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        var $row = $('.tap-chat-row[data-id="' + id + '"]');
        if (!$row.length) {
            return;
        }
        if ($row.is(':visible')) {
            $row.hide();
            return;
        }
        var loaded = $row.data('loaded');
        if (loaded) {
            $row.show();
            $btn.find('.tap-chat-badge').remove();
            return;
        }
        $.post(cfg.ajax_url, {
            action:     'tap_dash_chat_thread',
            nonce:      cfg.nonce,
            booking_id: id
        }).done(function (res) {
            if (!res || !res.success) {
                alert(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error));
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
        }).fail(function () {
            alert(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error');
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
                alert(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error));
            }
        }).fail(function () {
            alert(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error');
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

    /* ── Agency back-office: payout request ───────────────────────── */
    $(document).on('submit', '#tap-bo-payout', function (e) {
        e.preventDefault();
        var $form = $(this);

        if (!confirm(cfg.i18n && cfg.i18n.confirm_payout ? cfg.i18n.confirm_payout : '¿Enviar solicitud?')) {
            return;
        }

        var $btn = $form.find('button[type="submit"]').prop('disabled', true).text('…');

        $.post(cfg.ajax_url, {
            action: 'tap_dash_agency_payout',
            nonce:  cfg.nonce,
            method: $form.find('[name="method"]').val(),
            note:   $form.find('[name="note"]').val() || ''
        }).done(function (res) {
            if (res && res.success) {
                location.reload();
            } else {
                alert(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error));
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            alert(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error');
            $btn.prop('disabled', false);
        });
    });

})(jQuery);
