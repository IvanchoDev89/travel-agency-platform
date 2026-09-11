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

        if (!confirm((cfg.i18n && cfg.i18n.confirm_op ? cfg.i18n.confirm_op : '¿Realizar esta operación?') + ' ' + name + '?')) {
            return;
        }

        $btn.prop('disabled', true).text('…');

        $.post(cfg.ajax_url, {
            action:     'tap_dash_agency_booking',
            nonce:      cfg.nonce,
            booking_id: id,
            op:         op
        }).done(function (res) {
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
