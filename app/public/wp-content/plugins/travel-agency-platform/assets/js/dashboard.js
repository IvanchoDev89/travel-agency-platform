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
                $btn.prop('disabled', false).text('Cancelar');
            }
        }).fail(function () {
            alert(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error');
            $btn.prop('disabled', false).text('Cancelar');
        });
    });

    /* ── Toggle favorite ──────────────────────────────────────────── */
    $(document).on('click', '.tap-dash-fav-remove', function () {
        var $btn   = $(this);
        var postId = $btn.data('id');

        $.post(cfg.ajax_url, {
            action:  'tap_dash_toggle_favorite',
            nonce:   cfg.nonce,
            post_id: postId
        }).done(function (res) {
            if (res && res.success) {
                $btn.closest('.tap-dash-fav-card').fadeOut(300, function () {
                    $(this).remove();
                });
            }
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

        if (op === 'cancel') {
            reason = window.prompt(cfg.i18n && cfg.i18n.cancel_reason ? cfg.i18n.cancel_reason : 'Motivo de cancelación (opcional):', '') || '';
            if (reason === null) {
                return;
            }
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
                $btn.prop('disabled', false).text($btn.data('op'));
            }
        }).fail(function () {
            alert(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error');
            $btn.prop('disabled', false).text($btn.data('op'));
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
            $row.data('loaded', 1).show();
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
                var $thread = $form.closest('.tap-chat-wrap').find('.tap-chat-thread');
                $thread.find('.tap-empty').remove();
                $thread.append(res.data.row);
                $ta.val('');
            } else {
                alert(res && res.data && res.data.message ? res.data.message : (cfg.i18n && cfg.i18n.error));
            }
        }).fail(function () {
            alert(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

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
