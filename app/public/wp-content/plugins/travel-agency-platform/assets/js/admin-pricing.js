(function($){
    'use strict';

    var state = {
        roomId: tapPricing.room_id,
        year:   0,
        month:  0,
        days:   [],
        basePrice: 0,
        selectedDate: null
    };

    function init() {
        var now = new Date();
        state.year  = now.getFullYear();
        state.month = now.getMonth() + 1;
        loadMonth(state.year, state.month);

        $('.tap-pricing-nav-btn').on('click', function(){
            var dir = parseInt($(this).data('dir'));
            state.month += dir;
            if (state.month < 1)  { state.month = 12; state.year--; }
            if (state.month > 12) { state.month = 1;  state.year++; }
            loadMonth(state.year, state.month);
        });

        $(document).on('click', '.tap-pricing-day', function(){
            var $day = $(this);
            if ($day.hasClass('tap-day-other-month') || $day.hasClass('tap-day-blocked')) return;
            var date = $day.data('date');
            selectDate(date);
        });

        $('.tap-edit-save').on('click', savePricing);
        $('.tap-edit-delete').on('click', deletePricing);

        $('.tap-pricing-bulk-btn[data-action="set-season"]').on('click', openSeasonDialog);
        $('.tap-pricing-bulk-btn[data-action="clear-all"]').on('click', clearAllOverrides);
    }

    function loadMonth(year, month) {
        var $cal = $('.tap-pricing-calendar');
        $cal.html('<div class="tap-pricing-loading">Cargando calendario...</div>');

        var months = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $('.tap-pricing-month-label').text(months[month-1] + ' ' + year);

        $.post(tapPricing.ajax_url, {
            action:  'tap_get_pricing',
            nonce:   tapPricing.nonce,
            room_id: state.roomId,
            year:    year,
            month:   month
        }, function(res){
            if (!res.success) { $cal.html('<div class="tap-pricing-loading">Error al cargar</div>'); return; }
            state.days      = res.days;
            state.basePrice = res.base_price;
            state.month     = res.month;
            state.year      = res.year;
            renderCalendar(res);
        });
    }

    function renderCalendar(data) {
        var $cal = $('.tap-pricing-calendar');
        var html = '<div class="tap-pricing-weekdays">';
        var wds = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
        for (var i = 0; i < 7; i++) html += '<div class="tap-pricing-weekday">'+wds[i]+'</div>';
        html += '</div><div class="tap-pricing-days">';

        var firstDoW = new Date(data.year, data.month-1, 1).getDay();

        for (var p = 0; p < firstDoW; p++) {
            html += '<div class="tap-pricing-day tap-day-other-month"></div>';
        }

        var today = new Date();
        var todayStr = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-' + String(today.getDate()).padStart(2,'0');

        $.each(data.days, function(i, day){
            var cls = 'tap-pricing-day';
            if (day.overridden && day.is_blocked) cls += ' tap-day-blocked';
            else if (day.overridden) cls += ' tap-day-override';
            if (day.date === todayStr) cls += ' tap-day-today';
            if (state.selectedDate === day.date) cls += ' tap-day-selected';

            var priceFmt = '$' + parseFloat(day.price).toFixed(2);
            var labelHtml = day.label ? '<div class="tap-day-label">'+day.label+'</div>' : '';

            html += '<div class="'+cls+'" data-date="'+day.date+'">' +
                '<span class="tap-day-number">'+day.day+'</span>' +
                '<span class="tap-day-price">'+priceFmt+'</span>' +
                labelHtml +
                '</div>';
        });

        html += '</div>';
        $cal.html(html);
        $('.tap-pricing-edit-panel').hide();
        state.selectedDate = null;
    }

    function selectDate(date) {
        state.selectedDate = date;
        $('.tap-day-selected').removeClass('tap-day-selected');
        $('.tap-pricing-day[data-date="'+date+'"]').addClass('tap-day-selected');

        var day = null;
        $.each(state.days, function(i, d){ if (d.date === date) { day = d; return false; } });

        var months = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        var parts = date.split('-');
        var display = parts[2] + ' de ' + months[parseInt(parts[1])-1] + ' ' + parts[0];
        $('.tap-edit-date').text(display);
        $('.tap-edit-date-input').val(date);

        if (day && day.overridden) {
            $('.tap-edit-price').val(day.price !== null && day.price !== state.basePrice ? day.price : '');
            $('.tap-edit-minstay').val(day.min_stay !== null ? day.min_stay : '');
            $('.tap-edit-available').val(day.is_blocked ? 'no' : (day.overridden ? 'yes' : ''));
            $('.tap-edit-label').val(day.label || '');
        } else {
            $('.tap-edit-price').val('');
            $('.tap-edit-minstay').val('');
            $('.tap-edit-available').val('');
            $('.tap-edit-label').val('');
        }
        $('.tap-edit-msg').text('');
        $('.tap-pricing-edit-panel').show();
    }

    function savePricing() {
        var date = $('.tap-edit-date-input').val();
        var price = $('.tap-edit-price').val();
        var minStay = $('.tap-edit-minstay').val();
        var avail = $('.tap-edit-available').val();
        var label = $('.tap-edit-label').val();
        var $msg = $('.tap-edit-msg').text('Guardando...');

        $.post(tapPricing.ajax_url, {
            action:     'tap_save_pricing',
            nonce:      tapPricing.nonce,
            room_id:    state.roomId,
            date:       date,
            price:      price !== '' ? price : '',
            min_stay:   minStay !== '' ? minStay : '',
            is_blocked: avail === 'no' ? 1 : 0,
            label:      label
        }, function(res){
            if (res.success) {
                $msg.text('Guardado ✓');
                loadMonth(state.year, state.month);
            } else {
                $msg.text('Error: ' + (res.message || 'desconocido'));
            }
        });
    }

    function deletePricing() {
        var date = $('.tap-edit-date-input').val();
        if (!confirm('¿Eliminar la configuración de precio para esta fecha?')) return;
        var $msg = $('.tap-edit-msg').text('Eliminando...');

        $.post(tapPricing.ajax_url, {
            action:     'tap_save_pricing',
            nonce:      tapPricing.nonce,
            room_id:    state.roomId,
            date:       date,
            price:      '',
            min_stay:   '',
            is_blocked: 0,
            label:      ''
        }, function(res){
            if (res.success) {
                $msg.text('Eliminado ✓');
                loadMonth(state.year, state.month);
            } else {
                $msg.text('Error: ' + (res.message || 'desconocido'));
            }
        });
    }

    function openSeasonDialog() {
        var $overlay = $('<div class="tap-season-dialog-overlay"><div class="tap-season-dialog"><h3>Fijar temporada</h3>' +
            '<label>Nombre de la temporada</label><input type="text" class="tap-season-name" placeholder="p.ej. Temporada Alta" maxlength="100">' +
            '<div class="tap-season-dates">' +
            '<div><label>Fecha inicio</label><input type="date" class="tap-season-from"></div>' +
            '<div><label>Fecha fin</label><input type="date" class="tap-season-to"></div>' +
            '</div>' +
            '<label>Precio por noche ($)</label><input type="number" step="0.01" min="0" class="tap-season-price" placeholder="Dejar vacío para no cambiar">' +
            '<label>Estadía mínima (noches)</label><input type="number" min="0" step="1" class="tap-season-minstay" placeholder="Dejar vacío para no cambiar">' +
            '<label>Disponibilidad</label><select class="tap-season-available"><option value="">No cambiar</option><option value="yes">Disponible</option><option value="no">Bloquear</option></select>' +
            '<div class="tap-season-actions"><button type="button" class="button button-primary tap-season-apply">Aplicar</button><button type="button" class="button tap-season-cancel">Cancelar</button></div>' +
            '</div></div>');

        $('body').append($overlay);
        $('.tap-season-cancel').on('click', function(){ $overlay.remove(); });
        $('.tap-season-overlay, .tap-season-dialog-overlay').on('click', function(e){
            if ($(e.target).closest('.tap-season-dialog').length === 0) $overlay.remove();
        });
        $('.tap-season-apply').on('click', function(){
            applySeason($overlay);
        });
    }

    function applySeason($overlay) {
        var from  = $overlay.find('.tap-season-from').val();
        var to    = $overlay.find('.tap-season-to').val();
        var name  = $overlay.find('.tap-season-name').val().trim();
        var price = $overlay.find('.tap-season-price').val();
        var minStay = $overlay.find('.tap-season-minstay').val();
        var avail = $overlay.find('.tap-season-available').val();

        if (!from || !to) { alert('Selecciona fecha de inicio y fin'); return; }

        var dates = [];
        var d = new Date(from);
        var end = new Date(to);
        while (d <= end) {
            dates.push(d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'));
            d.setDate(d.getDate() + 1);
        }

        var $msg = $('.tap-pricing-loading').length ? $('.tap-pricing-loading') : $('.tap-pricing-calendar');
        $msg.html('<div class="tap-pricing-loading">Aplicando temporada...</div>');

        var completed = 0;
        var total = dates.length;

        dates.forEach(function(date){
            $.post(tapPricing.ajax_url, {
                action:     'tap_save_pricing',
                nonce:      tapPricing.nonce,
                room_id:    state.roomId,
                date:       date,
                price:      price !== '' ? price : '',
                min_stay:   minStay !== '' ? minStay : '',
                is_blocked: avail === 'no' ? 1 : 0,
                label:      name
            }, function(){
                completed++;
                if (completed >= total) {
                    $overlay.remove();
                    loadMonth(state.year, state.month);
                }
            });
        });
    }

    function clearAllOverrides() {
        if (!confirm('¿Eliminar TODAS las configuraciones de precio para esta habitación?')) return;
        var $cal = $('.tap-pricing-calendar');
        $cal.html('<div class="tap-pricing-loading">Limpiando...</div>');

        var dates = [];
        $.each(state.days, function(i, day){
            if (day.overridden) dates.push(day.date);
        });

        if (!dates.length) { loadMonth(state.year, state.month); return; }

        var completed = 0;
        dates.forEach(function(date){
            $.post(tapPricing.ajax_url, {
                action:     'tap_save_pricing',
                nonce:      tapPricing.nonce,
                room_id:    state.roomId,
                date:       date,
                price:      '',
                min_stay:   '',
                is_blocked: 0,
                label:      ''
            }, function(){
                completed++;
                if (completed >= dates.length) loadMonth(state.year, state.month);
            });
        });
    }

    $(document).ready(init);

})(jQuery);
