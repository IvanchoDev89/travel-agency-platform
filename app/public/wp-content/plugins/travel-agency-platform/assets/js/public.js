jQuery(document).ready(function($) {

    var Favorites = {
        init: function() {
            if (typeof tap_ajax === 'undefined') return;
            $(document).on('click', '.tap-fav-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $btn = $(this);
                var postId = $btn.data('post-id');
                if (tap_ajax.is_logged_in !== '1') {
                    var sep = tap_ajax.login_url.indexOf('?') > -1 ? '&' : '?';
                    window.location.href = tap_ajax.login_url + sep + 'redirect_to=' + encodeURIComponent(window.location.href);
                    return;
                }
                $.post(tap_ajax.ajax_url, {
                    action: 'tap_toggle_favorite',
                    post_id: postId,
                    nonce: tap_ajax.nonce
                }, function(res) {
                    if (res && res.success) {
                        $('button.tap-fav-btn[data-post-id="' + postId + '"]')
                            .toggleClass('active', !!res.data.added)
                            .attr('aria-pressed', res.data.added ? 'true' : 'false');
                        Favorites.toast(res.data.added ? 'Guardado en favoritos' : 'Quitado de favoritos');
                        if ($btn.closest('.tap-fav-card').length && !res.data.added) {
                            $btn.closest('.tap-fav-card').fadeOut(250, function() {
                                $(this).remove();
                                if (!$('.tap-fav-card').length) {
                                    window.location.reload();
                                }
                            });
                        }
                    } else if (res && res.data && res.data.needs_login) {
                        var sep = tap_ajax.login_url.indexOf('?') > -1 ? '&' : '?';
                        window.location.href = tap_ajax.login_url + sep + 'redirect_to=' + encodeURIComponent(window.location.href);
                    }
                });
            });
        },
        toast: function(msg) {
            var $t = $('.tap-toast');
            if (!$t.length) {
                $t = $('<div class="tap-toast"></div>').appendTo('body');
            }
            $t.text(msg).addClass('show');
            clearTimeout(this._t);
            this._t = setTimeout(function() {
                $t.removeClass('show');
            }, 1800);
        }
    };

    var tap = {
        init: function() {
            this.dateInputs();
            this.searchForm();
        },

        dateInputs: function() {
            $('input[type="date"]').each(function() {
                if (!$(this).val()) {
                    var today = new Date().toISOString().split('T')[0];
                    if ($(this).attr('id') === 'tap_check_in') {
                        $(this).attr('min', today);
                    }
                    if ($(this).attr('id') === 'tap_check_out') {
                        $(this).attr('min', today);
                    }
                }
            });
        },

        searchForm: function() {
            $('.tap-search-form-inline').on('submit', function(e) {
                e.preventDefault();
                var form = $(this);
                var data = form.serialize();

                $.ajax({
                    url: tap_ajax.ajax_url,
                    type: 'POST',
                    data: data + '&action=tap_search_services&nonce=' + tap_ajax.nonce,
                    success: function(res) {
                        if (res.success) {
                            var container = form.find('.tap-search-results');
                            if (!container.length) {
                                container = $('<div class="tap-search-results"></div>');
                                form.after(container);
                            }
                            container.empty();
                            if (res.data.count > 0) {
                                container.append('<p>' + res.data.count + ' ' + tapLabels.resultsFound + '</p>');
                                $.each(res.data.results, function(i, item) {
                                    var html = '<div class="tap-search-result-item">';
                                    if (item.thumbnail) {
                                        html += '<img src="' + item.thumbnail + '" alt="' + item.title + '">';
                                    }
                                    html += '<div class="tap-search-result-info">';
                                    html += '<a href="' + item.permalink + '"><strong>' + item.title + '</strong></a>';
                                    html += '<span class="tap-search-type">' + item.type_name + '</span>';
                                    html += '</div></div>';
                                    container.append(html);
                                });
                            } else {
                                container.append('<p>' + tapLabels.noResults + '</p>');
                            }
                        }
                    }
                });
            });
        }
    };

    var tapLabels = tapLabels || {
        resultsFound: 'results found',
        noResults: 'No results found'
    };

    /* ===== Lightbox ===== */
    var Lightbox = {
        images: [],
        current: 0,
        overlay: null,
        init: function(selector) {
            var self = this;
            this.build();
            $(document).on('click', selector, function(e) {
                e.preventDefault();
                var $container = $(this).closest('[data-lightbox]');
                if (!$container.length) return;
                var src = $(this).is('img') ? $(this).attr('src') : $(this).data('src');
                var imgs = $container.find('img');
                self.images = [];
                imgs.each(function() {
                    self.images.push($(this).attr('src'));
                });
                var idx = self.images.indexOf(src);
                if (idx === -1) idx = 0;
                self.open(idx);
            });
            $(document).on('keydown', function(e) {
                if (!self.overlay || !self.overlay.hasClass('open')) return;
                if (e.key === 'Escape') self.close();
                if (e.key === 'ArrowLeft') self.prev();
                if (e.key === 'ArrowRight') self.next();
            });
        },
        build: function() {
            if (document.querySelector('.tap-lightbox-overlay')) return;
            this.overlay = $(
                '<div class="tap-lightbox-overlay">' +
                '<button class="tap-lightbox-close">&times;</button>' +
                '<button class="tap-lightbox-prev">&lsaquo;</button>' +
                '<button class="tap-lightbox-next">&rsaquo;</button>' +
                '<img class="tap-lightbox-img" src="" alt="">' +
                '<div class="tap-lightbox-counter"></div>' +
                '</div>'
            ).appendTo('body');
            var self = this;
            this.overlay.find('.tap-lightbox-close').on('click', function() { self.close(); });
            this.overlay.find('.tap-lightbox-prev').on('click', function() { self.prev(); });
            this.overlay.find('.tap-lightbox-next').on('click', function() { self.next(); });
            this.overlay.on('click', function(e) {
                if ($(e.target).hasClass('tap-lightbox-overlay')) self.close();
            });
        },
        open: function(idx) {
            this.current = idx;
            this.show();
            this.overlay.addClass('open');
            $('body').css('overflow', 'hidden');
        },
        close: function() {
            this.overlay.removeClass('open');
            $('body').css('overflow', '');
        },
        show: function() {
            if (this.images.length === 0) return;
            this.overlay.find('.tap-lightbox-img').attr('src', this.images[this.current]);
            this.overlay.find('.tap-lightbox-counter').text((this.current + 1) + ' / ' + this.images.length);
        },
        prev: function() {
            if (this.images.length <= 1) return;
            this.current = (this.current - 1 + this.images.length) % this.images.length;
            this.show();
        },
        next: function() {
            if (this.images.length <= 1) return;
            this.current = (this.current + 1) % this.images.length;
            this.show();
        }
    };

    /* ===== Reviews ===== */
    var Reviews = {
        init: function() {
            var $section = $('.tap-reviews-section');
            if (!$section.length) return;
            this.serviceType = $section.find('input[name="service_type"]').val() || 'tap_accommodation';
            this.serviceId = $section.find('input[name="service_id"]').val() || 0;
            this.load();
            this.bindForm();
        },
        load: function() {
            var self = this;
            $.getJSON('/wp-json/tap/v1/reviews/' + this.serviceType + '/' + this.serviceId, function(reviews) {
                self.render(reviews);
            });
        },
        render: function(reviews) {
            var $list = $('.tap-reviews-list');
            $list.empty();

            if (!reviews || !reviews.length) {
                $list.html('<p class="tap-reviews-empty">Aún no hay reseñas.</p>');
                $('.tap-reviews-count').text('(0)');
                return;
            }

            $('.tap-reviews-count').text('(' + reviews.length + ')');

            var total = 0;
            $.each(reviews, function(i, r) { total += parseFloat(r.rating); });
            var avg = total / reviews.length;

            $('.tap-reviews-avg-score').text(avg.toFixed(1));
            $('.tap-reviews-avg-stars').html(Reviews.starsHtml(Math.round(avg)));
            $('.tap-reviews-avg-total').text(reviews.length + ' ' + (reviews.length === 1 ? 'reseña' : 'reseñas'));

            $.each(reviews, function(i, r) {
                var date = r.created_at ? r.created_at.split(' ')[0] : '';
                var html =
                    '<div class="tap-review-item">' +
                    '<div class="tap-review-avatar">' + (r.user_name ? r.user_name[0].toUpperCase() : '?') + '</div>' +
                    '<div class="tap-review-body">' +
                    '<div class="tap-review-header">' +
                    '<strong>' + (r.user_name || 'Anónimo') + '</strong>' +
                    '<span class="tap-review-date">' + date + '</span>' +
                    '</div>' +
                    '<div class="tap-review-stars-display">' + Reviews.starsHtml(r.rating) + '</div>' +
                    (r.title ? '<h4>' + r.title + '</h4>' : '') +
                    '<p>' + r.content + '</p>' +
                    '</div></div>';
                $list.append(html);
            });
        },
        starsHtml: function(rating) {
            var s = '';
            for (var i = 1; i <= 5; i++) {
                s += '<span class="tap-star' + (i <= rating ? ' active' : '') + '">&#9733;</span>';
            }
            return s;
        },
        bindForm: function() {
            var self = this;
            $('.tap-review-form').on('submit', function(e) {
                e.preventDefault();
                var $form = $(this);
                var $msg = $form.find('.tap-review-msg');
                $msg.text('Enviando...');

                $.ajax({
                    url: '/wp-json/tap/v1/review',
                    type: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', tap_ajax.rest_nonce);
                    },
                    data: {
                        service_type: self.serviceType,
                        service_id: self.serviceId,
                        rating: $form.find('input[name="rating"]:checked').val(),
                        title: $form.find('input[name="title"]').val(),
                        content: $form.find('textarea[name="content"]').val()
                    },
                    success: function(res) {
                        $msg.text('¡Reseña enviada! Pendiente de aprobación.').css('color', 'var(--tap-success)');
                        $form[0].reset();
                    },
                    error: function(jqXHR) {
                        var msg = 'Error al enviar';
                        if (jqXHR.responseJSON && jqXHR.responseJSON.message) msg = jqXHR.responseJSON.message;
                        $msg.text(msg).css('color', 'var(--tap-error)');
                    }
                });
            });
        }
    };

    /* ===== Autocomplete ===== */
    var Autocomplete = {
        timer: null,
        active: -1,
        items: [],
        init: function() {
            var self = this;

            $(document).on('input', '#hs-destino', function() {
                clearTimeout(self.timer);
                self.active = -1;
                var val = $(this).val();
                if (val.length < 2) { self.close(); return; }
                self.timer = setTimeout(function() { self.search(val); }, 250);
            });

            $(document).on('keydown', '#hs-destino', function(e) {
                var $box = $('.tap-hs-suggestions');
                var count = $box.find('.tap-hs-suggestion-item').length;
                if (!$box.is(':visible') || count === 0) return;

                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        self.active = (self.active + 1) % count;
                        self.highlight();
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        self.active = (self.active <= 0) ? count - 1 : self.active - 1;
                        self.highlight();
                        break;
                    case 'Enter':
                        if (self.active >= 0 && self.items[self.active]) {
                            e.preventDefault();
                            window.location.href = self.items[self.active].url;
                        }
                        break;
                    case 'Escape':
                        e.preventDefault();
                        self.close();
                        break;
                }
            });

            $(document).on('mouseenter click', '.tap-hs-suggestion-item', function(e) {
                if (e.type === 'click') return; // let the <a> handle navigation
                var idx = $(this).index();
                self.active = idx;
                self.highlight();
            });

            $(document).on('click', function(e) {
                if (!$(e.target).closest('.tap-hs-destino').length) {
                    self.close();
                }
            });
        },
        search: function(keyword) {
            var self = this;
            $.post(tap_ajax.ajax_url, {
                action: 'tap_search_suggestions',
                keyword: keyword
            }, function(res) {
                self.items = (res.data && res.data.results) ? res.data.results : [];
                self.render(self.items);
            });
        },
        render: function(results) {
            var $box = $('.tap-hs-suggestions');
            if (!results || !results.length) { this.close(); return; }

            var self = this;
            var html = '';
            $.each(results, function(i, r) {
                var imgHtml = r.img
                    ? '<img class="tap-hs-suggestion-img" src="' + r.img + '" alt="">'
                    : '<div class="tap-hs-suggestion-img-placeholder">' + (r.type === 'Destino' ? '&#128205;' : '&#127983;') + '</div>';
                html += '<a class="tap-hs-suggestion-item" href="' + r.url + '" role="option" id="tap-hs-opt-' + i + '">' +
                    imgHtml +
                    '<div class="tap-hs-suggestion-info">' +
                    '<div class="tap-hs-suggestion-title">' + r.label + '</div>' +
                    '<div class="tap-hs-suggestion-type">' + r.type + '</div>' +
                    '</div></a>';
            });
            $box.attr('role', 'listbox').html(html).show();
            $('#hs-destino').attr('aria-expanded', 'true').attr('aria-activedescendant', '').attr('aria-controls', 'tap-hs-listbox');
            $box.attr('id', 'tap-hs-listbox');
            this.active = -1;
        },
        highlight: function() {
            var $items = $('.tap-hs-suggestion-item');
            $items.removeClass('active');
            if (this.active >= 0 && this.active < $items.length) {
                var $el = $items.eq(this.active);
                $el.addClass('active');
                if ($el.length && $el[0].scrollIntoView) {
                    $el[0].scrollIntoView({ block: 'nearest' });
                }
                $('#hs-destino').attr('aria-activedescendant', $el.attr('id'));
            }
        },
        close: function() {
            $('.tap-hs-suggestions').hide().empty();
            $('#hs-destino').attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
            this.items = [];
            this.active = -1;
        }
    };

    if (typeof tap_ajax !== 'undefined') {
        tap.init();
        Lightbox.init('.tap-lightbox-trigger');
        Reviews.init();
        Autocomplete.init();
        Favorites.init();
    }
});
