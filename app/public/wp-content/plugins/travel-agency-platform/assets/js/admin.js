jQuery(document).ready(function($) {
    $('.tap-booking-status-select').on('change', function() {
        var select = $(this);
        var bookingId = select.data('booking-id');
        var status = select.val();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'tap_update_booking_status',
                booking_id: bookingId,
                status: status,
                _ajax_nonce: tap_ajax ? tap_ajax.nonce : ''
            },
            success: function(res) {
                if (res.success) {
                    select.closest('tr').find('.tap-status-display')
                        .removeClass('tap-status-pending tap-status-confirmed tap-status-cancelled tap-status-completed')
                        .addClass('tap-status-' + status)
                        .text(status.charAt(0).toUpperCase() + status.slice(1));
                }
            }
        });
    });
});
