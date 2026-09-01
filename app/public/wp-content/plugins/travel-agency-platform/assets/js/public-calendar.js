;(function($){
    'use strict';

    var MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    var WEEKDAYS = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

    var state = {
        accommodationId: 0,
        rooms: [],
        roomPricing: {},
        year: 0,
        month: 0,
        rangeStart: null,
        rangeEnd: null,
        isLoading: false
    };

    function init(accommodationId) {
        var now = new Date();
        state.accommodationId = accommodationId;
        state.year = now.getFullYear();
        state.month = now.getMonth() + 1;
        loadRooms();
        bindEvents();
    }

    function loadRooms() {
        var self = this;
        $.post(tap_ajax.ajax_url, {
            action: 'tap_get_rooms',
            accommodation_id: state.accommodationId
        }, function(res) {
            if (res.success && res.data.rooms) {
                state.rooms = res.data.rooms;
                loadAllPricing();
            }
        });
    }

    function loadAllPricing() {
        var rooms = state.rooms;
        if (!rooms.length) { render(); return; }
        var loaded = 0;
        $.each(rooms, function(i, room) {
            loadRoomPricing(room.id, function() {
                loaded++;
                if (loaded >= rooms.length) render();
            });
        });
    }

    function loadRoomPricing(roomId, cb) {
        $.post(tap_ajax.ajax_url, {
            action: 'tap_get_public_pricing',
            room_id: roomId,
            year: state.year,
            month: state.month
        }, function(res) {
            if (res.success) state.roomPricing[roomId] = res.data;
            if (cb) cb();
        });
    }

    function render() {
        var $wrap = $('.tap-avcal-grid');
        if (!$wrap.length) return;

        $('.tap-avcal-label').text(MONTHS[state.month-1] + ' ' + state.year);

        var html = '';
        for (var w = 0; w < 7; w++) html += '<div class="tap-avcal-weekday">' + WEEKDAYS[w] + '</div>';

        var firstDow = new Date(state.year, state.month-1, 1).getDay();
        var daysInMonth = new Date(state.year, state.month, 0).getDate();
        var today = new Date();
        var todayStr = dateStr(today);

        for (var p = 0; p < firstDow; p++) {
            html += '<div class="tap-avcal-day disabled"></div>';
        }

        for (var d = 1; d <= daysInMonth; d++) {
            var date = state.year + '-' + pad(state.month) + '-' + pad(d);
            var dt = new Date(state.year, state.month-1, d);
            var isBefore = dt < new Date(today.getFullYear(), today.getMonth(), today.getDate());

            var cls = 'tap-avcal-day';
            if (isBefore) cls += ' disabled';

            var isBlocked = isDateBlocked(date);
            if (isBlocked && !isBefore) cls += ' blocked';

            if (date === todayStr) cls += ' today';
            if (state.rangeStart && date === state.rangeStart) cls += ' range-start';
            if (state.rangeEnd && date === state.rangeEnd) cls += ' range-end';

            if (state.rangeStart && !state.rangeEnd && date > state.rangeStart && !isBefore && !isBlocked) {
                cls += ' in-range';
            }
            if (state.rangeStart && state.rangeEnd && date > state.rangeStart && date < state.rangeEnd) {
                cls += ' in-range';
            }

            var priceInfo = getMinPriceForDate(date);
            var priceFmt = priceInfo.min > 0 ? '$' + Math.round(priceInfo.min) : '';
            if (priceInfo.hasBlocked) priceFmt = '—';

            html += '<div class="' + cls + '" data-date="' + date + '" data-min-price="' + priceInfo.min + '">' +
                '<span class="tap-avcal-num">' + d + '</span>' +
                (priceFmt ? '<span class="tap-avcal-price">' + priceFmt + '</span>' : '') +
                '</div>';
        }

        $wrap.html(html);
        highlightSelectedRoom();
    }

    function isDateBlocked(date) {
        var rooms = state.rooms;
        for (var i = 0; i < rooms.length; i++) {
            var data = state.roomPricing[rooms[i].id];
            if (!data || !data.days) continue;
            for (var j = 0; j < data.days.length; j++) {
                if (data.days[j].date === date && data.days[j].is_blocked) return true;
            }
        }
        return false;
    }

    function getMinPriceForDate(date) {
        var min = 999999;
        var hasBlocked = false;
        var hasPrice = false;
        var rooms = state.rooms;
        for (var i = 0; i < rooms.length; i++) {
            var data = state.roomPricing[rooms[i].id];
            if (!data || !data.days) continue;
            for (var j = 0; j < data.days.length; j++) {
                if (data.days[j].date !== date) continue;
                if (data.days[j].is_blocked) { hasBlocked = true; continue; }
                if (data.days[j].price > 0) {
                    hasPrice = true;
                    if (data.days[j].price < min) min = data.days[j].price;
                }
            }
        }
        if (hasPrice && !hasBlocked) return { min: min, hasBlocked: false };
        if (hasPrice && hasBlocked) return { min: min, hasBlocked: true };
        return { min: 0, hasBlocked: hasBlocked };
    }

    function handleDateClick(date) {
        if (!state.rangeStart || (state.rangeStart && state.rangeEnd)) {
            state.rangeStart = date;
            state.rangeEnd = null;
        } else {
            if (date <= state.rangeStart) {
                state.rangeStart = date;
                state.rangeEnd = null;
            } else {
                state.rangeEnd = date;
            }
        }
        render();
        syncFormDates();
        refreshRoomAvailability();
    }

    function refreshRoomAvailability() {
        var checkIn = state.rangeStart || '';
        var checkOut = state.rangeEnd || '';
        var adults = $('#bw-adults').val() || 2;

        if (!checkIn || !checkOut) {
            // No dates selected — show all rooms with base pricing
            updateRoomCards(state.rooms);
            return;
        }

        $.post(tap_ajax.ajax_url, {
            action: 'tap_get_rooms',
            accommodation_id: state.accommodationId,
            check_in: checkIn,
            check_out: checkOut,
            adults: adults
        }, function(res) {
            if (res.success && res.data.rooms) {
                updateRoomCards(res.data.rooms);
                $('#bw-check-in, #bw-check-out').trigger('change');
            }
        });
    }

    function updateRoomCards(rooms) {
        var $list = $('.tap-rooms-list');
        if (!$list.length) return;

        $list.find('.tap-room-card').each(function() {
            var $card = $(this);
            var roomId = parseInt($card.data('room-id'));

            var found = null;
            $.each(rooms, function(i, r) {
                if (r.id === roomId) { found = r; return false; }
            });

            if (!found) {
                $card.addClass('tap-room-unavailable');
                $card.find('.tap-select-room').prop('disabled', true).text('No disponible');
                return;
            }

            $card.toggleClass('tap-room-unavailable', !found.available);
            $card.find('.tap-select-room')
                .prop('disabled', !found.available)
                .text(found.available ? 'Seleccionar' : 'No disponible');

            // Update pricing display
            var $price = $card.find('.tap-room-price-amount');
            var $total = $card.find('.tap-room-total');
            var $minStay = $card.find('.tap-room-min-stay');

            $price.text('$' + Number(found.price || 0).toFixed(0));

            if (found.nights > 0 && found.total > 0) {
                if (!$total.length) {
                    $card.find('.tap-room-price').append('<span class="tap-room-total"></span>');
                }
                $card.find('.tap-room-total').text('$' + Number(found.total).toFixed(2) + ' total');
                $card.find('.tap-room-total').show();
            } else {
                $card.find('.tap-room-total').hide();
            }

            if (found.min_stay && found.min_stay > 1) {
                if (!$minStay.length) {
                    $card.find('.tap-room-price').append('<span class="tap-room-min-stay"></span>');
                }
                $card.find('.tap-room-min-stay').text('Mín ' + found.min_stay + ' noches').show();
            } else {
                $card.find('.tap-room-min-stay').hide();
            }
        });
    }

    function highlightSelectedRoom() {
        var rid = $('#bw-room-id').val();
        if (rid) {
            $('.tap-room-card').removeClass('tap-room-selected');
            $('.tap-room-card[data-room-id="' + rid + '"]').addClass('tap-room-selected');
        }
    }

    function syncFormDates() {
        if (state.rangeStart) $('#bw-check-in').val(state.rangeStart);
        if (state.rangeEnd) $('#bw-check-out').val(state.rangeEnd);
    }

    function navigate(dir) {
        state.month += dir;
        if (state.month < 1) { state.month = 12; state.year--; }
        if (state.month > 12) { state.month = 1; state.year++; }
        state.roomPricing = {};
        loadAllPricing();
    }

    function bindEvents() {
        $(document).on('click', '.tap-avcal-nav-btn', function() {
            navigate(parseInt($(this).data('dir')));
        });
        $(document).on('click', '.tap-avcal-day:not(.disabled):not(.blocked)', function() {
            handleDateClick($(this).data('date'));
        });
        $(document).on('click', '.tap-select-room', function() {
            var $card = $(this).closest('.tap-room-card');
            $('.tap-room-card').removeClass('tap-room-selected');
            $card.addClass('tap-room-selected');
            var rid = $card.data('room-id');
            var name = $card.find('h3').text();
            $('#bw-room-id').val(rid);
            $('#bw-room-name').text(name);
            $('#bw-room-selected').show();
            setTimeout(function() { $('#bw-check-in, #bw-check-out').trigger('change'); }, 100);
        });
        // Re-check when booking form inputs change
        $('#bw-check-in, #bw-check-out').on('change', function() {
            var ci = $('#bw-check-in').val();
            var co = $('#bw-check-out').val();
            if (ci !== state.rangeStart || co !== state.rangeEnd) {
                state.rangeStart = ci;
                state.rangeEnd = co;
                render();
                if (ci && co) refreshRoomAvailability();
            }
        });
    }

    function dateStr(d) {
        return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());
    }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    /* Expose for debugging */
    window.tapCalState = state;

    $(document).ready(function() {
        var $wrap = $('.tap-avcal-wrap');
        if ($wrap.length) {
            init(parseInt($wrap.data('accommodation')));
        }
    });

})(jQuery);
