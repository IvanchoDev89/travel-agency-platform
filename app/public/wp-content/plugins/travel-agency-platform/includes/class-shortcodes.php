<?php
defined('ABSPATH') || exit;

class TAP_Shortcodes {
    public static function init() {
        $shortcodes = [
            'tap_search'        => [__CLASS__, 'search_form'],
            'tap_services'      => [__CLASS__, 'services_list'],
            'tap_service_detail' => [__CLASS__, 'service_detail'],
            'tap_agencies'      => [__CLASS__, 'agencies_list'],
            'tap_agency_detail' => [__CLASS__, 'agency_detail'],
            'tap_booking_form'  => [__CLASS__, 'booking_form'],
            'tap_my_bookings'   => [__CLASS__, 'my_bookings'],
            'tap_dashboard'     => [__CLASS__, 'dashboard'],
            'tap_featured'      => [__CLASS__, 'featured_services'],
            'tap_agency_services' => [__CLASS__, 'agency_services'],
            'tap_agency_register' => [__CLASS__, 'agency_register'],
            'tap_agency_manage'  => [__CLASS__, 'agency_manage'],
            'tap_booking_detail' => [__CLASS__, 'booking_detail'],
            'tap_favorites'      => [__CLASS__, 'favorites'],
            'tap_plans'         => [__CLASS__, 'plans'],
        ];

        foreach ($shortcodes as $tag => $callback) {
            add_shortcode($tag, $callback);
        }
    }

    public static function search_form($atts) {
        $atts = shortcode_atts(['type' => '', 'placeholder' => __('Where do you want to go?', 'travel-agency-platform')], $atts);
        ob_start();
        ?>
        <div class="tap-search-form">
            <form method="get" action="<?php echo esc_url(home_url('/search-results')); ?>" class="tap-search-form-inner">
                <input type="hidden" name="tap_search" value="1">
                <div class="tap-search-fields">
                    <div class="tap-search-field">
                        <label><?php esc_html_e('Destination', 'travel-agency-platform'); ?></label>
                        <input type="text" name="keyword" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" class="tap-input">
                    </div>
                    <div class="tap-search-field">
                        <label><?php esc_html_e('Check-in', 'travel-agency-platform'); ?></label>
                        <input type="date" name="check_in" class="tap-input">
                    </div>
                    <div class="tap-search-field">
                        <label><?php esc_html_e('Check-out', 'travel-agency-platform'); ?></label>
                        <input type="date" name="check_out" class="tap-input">
                    </div>
                    <div class="tap-search-field">
                        <label><?php esc_html_e('Guests', 'travel-agency-platform'); ?></label>
                        <input type="number" name="guests" min="1" value="1" class="tap-input tap-input-sm">
                    </div>
                    <div class="tap-search-field">
                        <label>&nbsp;</label>
                        <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Search', 'travel-agency-platform'); ?></button>
                    </div>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function services_list($atts) {
        $atts = shortcode_atts([
            'type'      => '',
            'limit'     => 12,
            'agency'    => '',
            'location'  => '',
            'featured'  => '',
            'columns'   => 3,
        ], $atts);

        $types = $atts['type'] ? (array) $atts['type'] : ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];

        $all_posts = [];
        foreach ($types as $pt) {
            $pt_args = [
                'post_type'      => $pt,
                'posts_per_page' => intval($atts['limit']),
                'post_status'    => 'publish',
            ];

            if ($atts['agency']) {
                $prefix = '_tap_' . str_replace('tap_', '', $pt) . '_agency_id';
                $pt_args['meta_query'] = [
                    ['key' => $prefix, 'value' => intval($atts['agency'])],
                ];
            }

            if ($atts['location']) {
                $pt_args['tax_query'] = [
                    ['taxonomy' => 'tap_location', 'field' => 'term_id', 'terms' => intval($atts['location'])],
                ];
            }

            if ($atts['featured'] === 'yes') {
                $prefix = '_tap_' . str_replace('tap_', '', $pt);
                $pt_args['meta_query'][] = ['key' => $prefix . '_is_featured', 'value' => '1'];
            }

            $q = new WP_Query($pt_args);
            foreach ($q->posts as $p) {
                $all_posts[] = $p;
            }
        }

        if (empty($all_posts)) {
            return '<p class="tap-no-results">' . __('No services found.', 'travel-agency-platform') . '</p>';
        }

        $query = (object) ['posts' => $all_posts, 'have_posts' => function() use (&$all_posts) { return !empty($all_posts); }];
        $query->have_posts = function() use (&$all_posts) {
            $has = !empty($all_posts);
            if (!$has) return false;
            $GLOBALS['post'] = array_shift($all_posts);
            setup_postdata($GLOBALS['post']);
            return true;
        };

        ob_start();
        ?>
        <div class="tap-services-grid" style="display: grid; grid-template-columns: repeat(<?php echo $cols; ?>, 1fr); gap: 20px;">
            <?php while ($query->have_posts()): $query->have_posts(); setup_postdata($GLOBALS['post']); ?>
                <div class="tap-service-card">
                    <?php if (has_post_thumbnail()): ?>
                        <a href="<?php the_permalink(); ?>"><?php the_post_thumbnail('medium'); ?></a>
                    <?php endif; ?>
                    <div class="tap-service-card-body">
                        <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                        <p class="tap-service-excerpt"><?php echo wp_trim_words(get_the_excerpt(), 20); ?></p>
                        <a href="<?php the_permalink(); ?>" class="tap-btn tap-btn-outline"><?php esc_html_e('View Details', 'travel-agency-platform'); ?></a>
                    </div>
                </div>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function service_detail($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $post = get_post(intval($atts['id']));

        if (!$post) return '<p>' . __('Service not found.', 'travel-agency-platform') . '</p>';

        setup_postdata($post);
        $type = $post->post_type;
        $prefix = '_tap_' . str_replace('tap_', '', $type);

        ob_start();
        ?>
        <div class="tap-service-detail">
            <h1><?php the_title(); ?></h1>
            <?php if (has_post_thumbnail()): ?>
                <div class="tap-featured-image"><?php the_post_thumbnail('large'); ?></div>
            <?php endif; ?>
            <div class="tap-content"><?php the_content(); ?></div>
            <div class="tap-meta-grid">
                <?php
                $price_fields = [
                    'tap_accommodation' => ['_tap_acc_price_per_night', __('Price per Night', 'travel-agency-platform')],
                    'tap_tour' => ['_tap_tour_price_adult', __('Price per Adult', 'travel-agency-platform')],
                    'tap_transport' => ['_tap_trans_price', __('Price', 'travel-agency-platform')],
                    'tap_car_rental' => ['_tap_car_price_per_day', __('Price per Day', 'travel-agency-platform')],
                    'tap_boat' => ['_tap_boat_price_half', __('Half Day Price', 'travel-agency-platform')],
                    'tap_package' => ['_tap_pkg_price', __('Total Price', 'travel-agency-platform')],
                ];

                if (isset($price_fields[$type])) {
                    list($price_key, $price_label) = $price_fields[$type];
                    $price = get_post_meta($post->ID, $price_key, true);
                    $currency = get_post_meta($post->ID, $prefix . '_currency', true) ?: TAP_Currency::symbol();
                    if ($price) {
                        echo '<div class="tap-meta-item"><strong>' . esc_html($price_label) . ':</strong> ' . esc_html($currency . number_format(floatval($price), 2)) . '</div>';
                    }
                }

                $exclude_keys = [$prefix . '_agency_id', $prefix . '_is_active', $price_key ?? '', $prefix . '_currency'];
                $all_meta = get_post_meta($post->ID);

                foreach ($all_meta as $key => $values) {
                    if (strpos($key, $prefix) !== 0) continue;
                    if (in_array($key, $exclude_keys)) continue;

                    $label = str_replace([$prefix . '_', '_'], ['', ' '], $key);
                    $label = ucwords(trim($label));
                    $value = $values[0];

                    if ($value === '1') $value = __('Yes', 'travel-agency-platform');
                    elseif ($value === '0') $value = __('No', 'travel-agency-platform');

                    echo '<div class="tap-meta-item"><strong>' . esc_html($label) . ':</strong> ' . esc_html($value) . '</div>';
                }
                ?>
            </div>
            <?php echo do_shortcode('[tap_booking_form service_type="' . esc_attr($type) . '" service_id="' . $post->ID . ']'); ?>
        </div>
        <?php
        wp_reset_postdata();
        return ob_get_clean();
    }

    public static function agencies_list($atts) {
        $atts = shortcode_atts(['limit' => 20], $atts);

        $agencies = get_posts([
            'post_type'      => 'tap_agency',
            'posts_per_page' => intval($atts['limit']),
            'post_status'    => 'publish',
            'meta_query'     => [
                ['key' => '_tap_agency_verified', 'value' => '1'],
            ],
        ]);

        if (empty($agencies)) {
            return '<p>' . __('No agencies found.', 'travel-agency-platform') . '</p>';
        }

        ob_start();
        ?>
        <div class="tap-agencies-grid">
            <?php foreach ($agencies as $agency): ?>
                <div class="tap-agency-card">
                    <?php if (has_post_thumbnail($agency->ID)): ?>
                        <?php echo get_the_post_thumbnail($agency->ID, 'medium'); ?>
                    <?php endif; ?>
                    <h3><a href="<?php echo esc_url(get_permalink($agency->ID)); ?>"><?php echo esc_html($agency->post_title); ?></a></h3>
                    <p><?php echo esc_html(wp_trim_words($agency->post_excerpt ?: wp_trim_words($agency->post_content, 30), 20)); ?></p>
                    <a href="<?php echo esc_url(get_permalink($agency->ID)); ?>" class="tap-btn tap-btn-outline"><?php esc_html_e('View Profile', 'travel-agency-platform'); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function agency_detail($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $post = get_post(intval($atts['id']));

        if (!$post || $post->post_type !== 'tap_agency') {
            return '<p>' . __('Agency not found.', 'travel-agency-platform') . '</p>';
        }

        $email = get_post_meta($post->ID, '_tap_agency_email', true);
        $phone = get_post_meta($post->ID, '_tap_agency_phone', true);
        $whatsapp = get_post_meta($post->ID, '_tap_agency_whatsapp', true);
        $website = get_post_meta($post->ID, '_tap_agency_website', true);
        $address = get_post_meta($post->ID, '_tap_agency_address', true);
        $city = get_post_meta($post->ID, '_tap_agency_city', true);
        $country = get_post_meta($post->ID, '_tap_agency_country', true);
        $is_verified = get_post_meta($post->ID, '_tap_agency_verified', true);

        ob_start();
        ?>
        <div class="tap-agency-detail">
            <div class="tap-agency-header">
                <?php if (has_post_thumbnail($post->ID)): ?>
                    <div class="tap-agency-logo"><?php echo get_the_post_thumbnail($post->ID, 'medium'); ?></div>
                <?php endif; ?>
                <div class="tap-agency-info">
                    <h1><?php echo esc_html($post->post_title); ?></h1>
                    <?php if ($is_verified): ?><span class="tap-badge tap-badge-success"><?php esc_html_e('Verified', 'travel-agency-platform'); ?></span><?php endif; ?>
                    <?php if ($city || $country): ?>
                        <p class="tap-agency-location"><?php echo esc_html($city . ($city && $country ? ', ' : '') . $country); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="tap-agency-body">
                <div class="tap-content"><?php echo apply_filters('the_content', $post->post_content); ?></div>
                <div class="tap-agency-contact">
                    <h3><?php esc_html_e('Contact Information', 'travel-agency-platform'); ?></h3>
                    <?php if ($email): ?><p><strong><?php esc_html_e('Email:', 'travel-agency-platform'); ?></strong> <a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></p><?php endif; ?>
                    <?php if ($phone): ?><p><strong><?php esc_html_e('Phone:', 'travel-agency-platform'); ?></strong> <a href="tel:<?php echo esc_attr($phone); ?>"><?php echo esc_html($phone); ?></a></p><?php endif; ?>
                    <?php if ($whatsapp): ?><p><strong><?php esc_html_e('WhatsApp:', 'travel-agency-platform'); ?></strong> <a href="https://wa.me/<?php echo esc_attr($whatsapp); ?>" target="_blank"><?php echo esc_html($whatsapp); ?></a></p><?php endif; ?>
                    <?php if ($website): ?><p><strong><?php esc_html_e('Website:', 'travel-agency-platform'); ?></strong> <a href="<?php echo esc_url($website); ?>" target="_blank"><?php echo esc_html($website); ?></a></p><?php endif; ?>
                    <?php if ($address): ?><p><strong><?php esc_html_e('Address:', 'travel-agency-platform'); ?></strong> <?php echo esc_html($address); ?></p><?php endif; ?>
                </div>
            </div>
            <h3><?php esc_html_e('Our Services', 'travel-agency-platform'); ?></h3>
            <?php echo do_shortcode('[tap_agency_services agency="' . $post->ID . '"]'); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function booking_form($atts) {
        $atts = shortcode_atts([
            'service_type' => '',
            'service_id'   => 0,
        ], $atts);

        if (empty($atts['service_type']) || empty($atts['service_id'])) {
            return '<p>' . __('Invalid service configuration.', 'travel-agency-platform') . '</p>';
        }

        $service = get_post(intval($atts['service_id']));
        if (!$service) return '<p>' . __('Service not found.', 'travel-agency-platform') . '</p>';

        $prefix = '_tap_' . str_replace('tap_', '', $atts['service_type']);

        ob_start();
        ?>
        <div class="tap-booking-form">
            <h3><?php esc_html_e('Book Now', 'travel-agency-platform'); ?></h3>
            <form id="tap-booking-form" class="tap-form" method="post">
                <input type="hidden" name="action" value="tap_booking_create">
                <input type="hidden" name="service_type" value="<?php echo esc_attr($atts['service_type']); ?>">
                <input type="hidden" name="service_id" value="<?php echo esc_attr($atts['service_id']); ?>">
                <?php wp_nonce_field('tap_booking_nonce', 'tap_booking_nonce'); ?>

                <div class="tap-form-row">
                    <div class="tap-form-group">
                        <label for="tap_check_in"><?php esc_html_e('Check-in Date', 'travel-agency-platform'); ?></label>
                        <input type="date" id="tap_check_in" name="check_in" class="tap-input" required>
                    </div>
                    <div class="tap-form-group">
                        <label for="tap_check_out"><?php esc_html_e('Check-out Date', 'travel-agency-platform'); ?></label>
                        <input type="date" id="tap_check_out" name="check_out" class="tap-input" required>
                    </div>
                </div>
                <div class="tap-form-row">
                    <div class="tap-form-group">
                        <label for="tap_adults"><?php esc_html_e('Adults', 'travel-agency-platform'); ?></label>
                        <input type="number" id="tap_adults" name="adults" min="1" value="1" class="tap-input tap-input-sm" required>
                    </div>
                    <div class="tap-form-group">
                        <label for="tap_children"><?php esc_html_e('Children', 'travel-agency-platform'); ?></label>
                        <input type="number" id="tap_children" name="children" min="0" value="0" class="tap-input tap-input-sm">
                    </div>
                </div>
                <div class="tap-form-group">
                    <label for="tap_notes"><?php esc_html_e('Special Requests', 'travel-agency-platform'); ?></label>
                    <textarea id="tap_notes" name="notes" rows="3" class="tap-input"></textarea>
                </div>
                <div class="tap-form-group">
                    <span class="tap-total-display" style="font-size: 18px; font-weight: bold; display: block; margin-bottom: 10px;"><?php esc_html_e('Total: ', 'travel-agency-platform'); ?>$<span id="tap-total-amount">0.00</span></span>
                </div>
                <div class="tap-form-group">
                    <button type="submit" class="tap-btn tap-btn-primary tap-btn-lg"><?php esc_html_e('Book Now', 'travel-agency-platform'); ?></button>
                </div>
                <div class="tap-booking-message"></div>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            function calculateTotal() {
                var data = {
                    action: 'tap_calculate_booking_total',
                    service_type: '<?php echo esc_js($atts['service_type']); ?>',
                    service_id: '<?php echo esc_js($atts['service_id']); ?>',
                    check_in: $('#tap_check_in').val(),
                    check_out: $('#tap_check_out').val(),
                    adults: $('#tap_adults').val(),
                    children: $('#tap_children').val(),
                    nonce: tap_ajax.nonce
                };

                $.post(tap_ajax.ajax_url, data, function(res) {
                    if (res.success) {
                        $('#tap-total-amount').text(res.data.total.toFixed(2));
                    }
                });
            }

            $('#tap_check_in, #tap_check_out, #tap_adults, #tap_children').on('change', calculateTotal);

            $('#tap-booking-form').on('submit', function(e) {
                e.preventDefault();
                var form = $(this);
                var msg = form.find('.tap-booking-message');
                var total = parseFloat($('#tap-total-amount').text()) || 0;

                if (total <= 0) {
                    msg.removeClass('tap-success').addClass('tap-error')
                       .html('<p><?php echo esc_js(__('Please select dates to calculate the price.', 'travel-agency-platform')); ?></p>');
                    return;
                }

                var data = form.serialize();
                data += '&total_amount=' + total + '&nonce=' + tap_ajax.nonce;

                msg.html('<p><?php echo esc_js(__('Creating booking...', 'travel-agency-platform')); ?></p>');

                $.post(tap_ajax.ajax_url, data, function(res) {
                    if (res.success) {
                        msg.removeClass('tap-error').addClass('tap-success')
                           .html('<p><?php echo esc_js(__('Booking created! Redirecting to payment...', 'travel-agency-platform')); ?></p>');
                        setTimeout(function() {
                            window.location.href = '<?php echo esc_js(home_url('/checkout')); ?>?code=' + res.data.booking_code;
                        }, 1000);
                    } else {
                        msg.removeClass('tap-success').addClass('tap-error')
                           .html('<p>' + (res.data.message || '<?php echo esc_js(__('Error creating booking.', 'travel-agency-platform')); ?>') + '</p>');
                    }
                }).fail(function() {
                    msg.removeClass('tap-success').addClass('tap-error')
                        .html('<p><?php echo esc_js(__('Connection error.', 'travel-agency-platform')); ?></p>');
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public static function my_bookings($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view your bookings.', 'travel-agency-platform') . '</p>';
        }

        $user_id = get_current_user_id();
        $bookings = TAP_Booking::get_client_bookings($user_id);

        global $wpdb;
        $reviewed = $wpdb->get_col($wpdb->prepare(
            "SELECT CONCAT(service_type, ':', service_id) FROM {$wpdb->prefix}tap_reviews WHERE user_id = %d",
            $user_id
        ));

        ob_start();
        ?>
        <div class="tap-my-bookings">
            <h2><?php esc_html_e('My Bookings', 'travel-agency-platform'); ?></h2>
            <?php if (empty($bookings)): ?>
                <p><?php esc_html_e('No bookings found.', 'travel-agency-platform'); ?></p>
            <?php else: ?>
                <table class="tap-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Code', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Service', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Dates', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Status', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Payment', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Actions', 'travel-agency-platform'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking):
                            $service = get_post($booking->service_id);
                            $service_marker = $booking->service_type . ':' . $booking->service_id;
                            $can_review = 'completed' === $booking->status
                                && in_array($booking->service_type, ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'], true)
                                && $service
                                && !in_array($service_marker, $reviewed, true);
                        ?>
                        <tr>
                            <td><?php echo esc_html($booking->booking_code); ?></td>
                            <td><?php echo $service ? esc_html($service->post_title) : 'N/A'; ?></td>
                            <td><?php echo esc_html($booking->check_in . ($booking->check_out ? ' - ' . $booking->check_out : '')); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($booking->total_amount)); ?></td>
                            <td><span class="tap-status tap-status-<?php echo esc_attr($booking->status); ?>"><?php echo esc_html(ucfirst($booking->status)); ?></span></td>
                            <td><span class="tap-status tap-status-<?php echo esc_attr($booking->payment_status); ?>"><?php echo esc_html(ucfirst($booking->payment_status)); ?></span></td>
                            <td>
                                <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/booking-detail/?code=' . $booking->booking_code)); ?>"><?php esc_html_e('Ver voucher', 'travel-agency-platform'); ?></a>
                                <?php if ($can_review): ?>
                                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(add_query_arg('tap_review', $booking->booking_code, get_permalink($booking->service_id))); ?>"><?php esc_html_e('Deja una reseña ★', 'travel-agency-platform'); ?></a>
                                <?php elseif ('completed' === $booking->status && in_array($service_marker, $reviewed, true)): ?>
                                    <span class="tap-review-done"><?php esc_html_e('✓ Reseña enviada', 'travel-agency-platform'); ?></span>
                                <?php endif; ?>
                                <?php if (in_array($booking->status, ['pending', 'confirmed'], true) && (!$booking->check_in || $booking->check_in >= gmdate('Y-m-d'))): ?>
                                    <button type="button" class="tap-btn tap-btn-sm tap-btn-danger tap-cancel-booking-btn" data-booking-id="<?php echo (int) $booking->id; ?>" data-confirm="<?php echo esc_attr($booking->booking_code); ?>"><?php esc_html_e('Cancelar', 'travel-agency-platform'); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <script>
        (function($) {
            var nonce = '<?php echo esc_js(wp_create_nonce('tap_booking_nonce')); ?>';
            $('.tap-cancel-booking-btn').on('click', function() {
                var $btn = $(this), id = $btn.data('booking-id');
                var code = $btn.data('confirm') || '';
                if (!confirm('<?php echo esc_js(__('¿Cancelar la reserva ', 'travel-agency-platform')); ?>' + code + '?')) return;
                $btn.prop('disabled', true).text('...');
                $.post(tap_ajax.ajax_url, { action: 'tap_cancel_booking', nonce: nonce, booking_id: id })
                    .done(function(res) {
                        if (res && res.success) { location.reload(); }
                        else { alert(res && res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>'); $btn.prop('disabled', false).text('<?php echo esc_js(__('Cancelar', 'travel-agency-platform')); ?>'); }
                    })
                    .fail(function() { alert('<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>'); $btn.prop('disabled', false).text('<?php echo esc_js(__('Cancelar', 'travel-agency-platform')); ?>'); });
            });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    public static function dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to access the dashboard.', 'travel-agency-platform') . '</p>';
        }

        $user = wp_get_current_user();
        $is_agency = in_array('tap_agency_admin', (array) $user->roles) || in_array('tap_agency_employee', (array) $user->roles);

        if ($is_agency) {
            return self::agency_panel($user);
        }

        ob_start();
        ?>
        <div class="tap-dashboard">
            <h2><?php printf(__('Welcome, %s!', 'travel-agency-platform'), esc_html($user->display_name)); ?></h2>
            <div class="tap-dashboard-links">
                <a href="<?php echo esc_url(home_url('/my-bookings')); ?>" class="tap-btn"><?php esc_html_e('My Bookings', 'travel-agency-platform'); ?></a>
                <a href="<?php echo esc_url(home_url('/agency-profile')); ?>" class="tap-btn"><?php esc_html_e('Agency Profile', 'travel-agency-platform'); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function agency_panel($user) {
        global $wpdb;

        $agency_id  = TAP_Booking::get_agency_for_user($user->ID);
        $agency     = $agency_id ? get_post($agency_id) : null;
        $agency_name = $agency ? $agency->post_title : __('Your Agency', 'travel-agency-platform');
        $verified   = $agency_id ? get_post_meta($agency_id, '_tap_agency_verified', true) : false;
        $commission = $agency_id ? TAP_Booking::get_agency_commission($agency_id) : 10;
        $stats      = TAP_Booking::get_booking_stats($agency_id);

        $comm_rows = $agency_id ? $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN commission_status != 'paid' THEN commission_amount ELSE 0 END),0) owed,
                COALESCE(SUM(CASE WHEN commission_status = 'paid' THEN commission_amount ELSE 0 END),0) settled
             FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d AND commission_amount > 0",
            $agency_id
        )) : (object) ['owed' => 0, 'settled' => 0];
        $settlements = $agency_id ? $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_commission_payments WHERE agency_id = %d ORDER BY created_at DESC LIMIT 10",
            $agency_id
        )) : [];

        $ab_status = sanitize_key($_GET['ab_status'] ?? 'all');
        if (!in_array($ab_status, ['all', 'pending', 'confirmed', 'completed', 'cancelled', 'refunded'], true)) {
            $ab_status = 'all';
        }
        $ab_page    = max(1, intval($_GET['ab_page'] ?? 1));
        $ab_per_page = 20;
        $where = $agency_id
            ? $wpdb->prepare("WHERE agency_id = %d", $agency_id)
            : 'WHERE 1=0';
        if ('all' !== $ab_status) {
            $where .= $wpdb->prepare(" AND status = %s", $ab_status);
        }
        $ab_count_rows = $agency_id ? $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) c FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d GROUP BY status",
            $agency_id
        )) : [];
        $ab_map = ['all' => 0, 'pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'refunded' => 0];
        foreach ($ab_count_rows as $rc) {
            $ab_map[$rc->status] = (int) $rc->c;
        }
        $ab_map['all'] = array_sum($ab_map);
        $ab_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings {$where}");
        $ab_offset = ($ab_page - 1) * $ab_per_page;
        $bookings  = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $ab_per_page, $ab_offset
        ));
        $ab_pages = (int) ceil($ab_total / $ab_per_page);
        $booking_counts = [];
        if ($agency_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT service_type, service_id, COUNT(*) as c,
                        SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total_amount ELSE 0 END) as rev
                 FROM {$wpdb->prefix}tap_bookings WHERE agency_id = %d
                 GROUP BY service_type, service_id", $agency_id
            ));
            foreach ($rows as $r) {
                $booking_counts[$r->service_type . ':' . $r->service_id] = [$r->c, (float) $r->rev];
            }
        }

        $listing_types = [
            'tap_accommodation' => ['meta' => '_tap_acc_agency_id',  'label' => __('Accommodations', 'travel-agency-platform')],
            'tap_tour'          => ['meta' => '_tap_tour_agency_id', 'label' => __('Tours', 'travel-agency-platform')],
            'tap_transport'     => ['meta' => '_tap_trans_agency_id', 'label' => __('Transports', 'travel-agency-platform')],
            'tap_car_rental'    => ['meta' => '_tap_car_agency_id',  'label' => __('Car Rentals', 'travel-agency-platform')],
            'tap_boat'          => ['meta' => '_tap_boat_agency_id', 'label' => __('Boats', 'travel-agency-platform')],
            'tap_package'       => ['meta' => '_tap_pkg_agency_id',  'label' => __('Packages', 'travel-agency-platform')],
        ];

        $listings = [];
        foreach ($listing_types as $type => $cfg) {
            $ids = get_posts([
                'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => -1,
                'meta_key' => $cfg['meta'], 'meta_value' => $agency_id, 'fields' => 'ids',
            ]);
            if ($ids) {
                $listings[$type] = [
                    'label'  => $cfg['label'],
                    'ids'    => $ids,
                    'count'  => count($ids),
                    'edit'   => admin_url('edit.php?post_type=' . $type),
                ];
            }
        }

        $empty_state = sprintf(__('No bookings yet. Share your listing and start receiving reservations. Commission: %s%%', 'travel-agency-platform'), esc_html($commission));
        ?>
        <div class="tap-agency-panel">
            <div class="tap-agency-header">
                <div>
                    <h2><?php echo esc_html($agency_name); ?>
                        <?php if ($verified): ?>
                            <span class="tap-verified-badge" title="<?php esc_attr_e('Verified agency', 'travel-agency-platform'); ?>">&#10003; <?php esc_html_e('Verified', 'travel-agency-platform'); ?></span>
                        <?php endif; ?>
                    </h2>
                    <p class="tap-agency-meta"><?php esc_html_e('Commission', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($commission); ?>%</strong></p>
                </div>
                <a href="<?php echo esc_url(home_url('/agency-profile/?id=' . $agency_id)); ?>" class="tap-btn"><?php esc_html_e('View Public Profile', 'travel-agency-platform'); ?></a>
            </div>

            <?php if ($agency_id && class_exists('TAP_Subscriptions')):
                $tap_plan    = TAP_Subscriptions::active_plan($agency_id);
                $tap_current = TAP_Subscriptions::current_subscription($agency_id);
                $tap_limit   = TAP_Subscriptions::listing_limit($agency_id);
                $tap_used    = TAP_Subscriptions::listing_count($agency_id);
            ?>
            <div class="tap-plan-summary">
                <span><?php esc_html_e('Plan', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($tap_plan->name); ?></strong></span>
                <?php if (!empty($tap_plan->paid_until)): ?>
                    <span><?php esc_html_e('Válido hasta', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($tap_plan->paid_until); ?></strong></span>
                <?php endif; ?>
                <span><?php esc_html_e('Listados', 'travel-agency-platform'); ?>: <strong><?php echo (int) $tap_limit < 0 ? esc_html__('Ilimitados', 'travel-agency-platform') : esc_html($tap_used . ' / ' . $tap_limit); ?></strong></span>
                <span><?php esc_html_e('Comisión', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($commission); ?>%</strong></span>
                <a href="<?php echo esc_url(home_url('/planes/')); ?>" class="tap-btn tap-btn-sm"><?php esc_html_e('Ver planes', 'travel-agency-platform'); ?></a>
            </div>
            <?php if ($tap_current && 'pending' === $tap_current->status): ?>
                <div class="tap-capacity-note" style="background:#fffbeb;color:#92400e;font-size:13px;font-weight:600;padding:10px 14px;border-radius:10px;margin-bottom:16px;">
                    <?php esc_html_e('Tienes una suscripción pendiente de confirmación de pago.', 'travel-agency-platform'); ?>
                </div>
            <?php elseif ((int) $tap_limit >= 0 && $tap_used >= (int) $tap_limit): ?>
                <div class="tap-capacity-note tap-full" style="margin-bottom:16px;">
                    <?php echo esc_html(sprintf(__('Alcanzaste el límite de %d listados de tu plan. Mejora de plan para publicar más.', 'travel-agency-platform'), (int) $tap_limit)); ?>
                </div>
            <?php endif; endif; ?>

            <div class="tap-stats-grid">
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['total']); ?></span><span class="tap-stat-label"><?php esc_html_e('Total Bookings', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['pending']); ?></span><span class="tap-stat-label"><?php esc_html_e('Pending', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html($stats['confirmed']); ?></span><span class="tap-stat-label"><?php esc_html_e('Confirmed', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(number_format($stats['completed'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Completed', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($stats['revenue'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Revenue', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number"><?php echo esc_html(TAP_Currency::fmt($stats['commission'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Commission', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html(TAP_Currency::fmt($stats['net'])); ?></span><span class="tap-stat-label"><?php esc_html_e('Net to you', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#b45309;"><?php echo esc_html(TAP_Currency::fmt($comm_rows->owed)); ?></span><span class="tap-stat-label"><?php esc_html_e('Por cobrar', 'travel-agency-platform'); ?></span></div>
                <div class="tap-stat-card"><span class="tap-stat-number" style="color:#047857;"><?php echo esc_html(TAP_Currency::fmt($comm_rows->settled)); ?></span><span class="tap-stat-label"><?php esc_html_e('Cobrado', 'travel-agency-platform'); ?></span></div>
            </div>

            <div class="tap-panel-section">
                <h3><?php esc_html_e('Bookings', 'travel-agency-platform'); ?></h3>
                <div class="tap-agency-tabs">
                    <?php
                    $tab_labels = [
                        'all'       => __('All', 'travel-agency-platform'),
                        'pending'   => __('Pending', 'travel-agency-platform'),
                        'confirmed' => __('Confirmed', 'travel-agency-platform'),
                        'completed' => __('Completed', 'travel-agency-platform'),
                        'cancelled' => __('Cancelled', 'travel-agency-platform'),
                    ];
                    foreach ($tab_labels as $k => $l):
                    ?>
                    <a class="tap-tab <?php echo $ab_status === $k ? 'active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['ab_status' => $k, 'ab_page' => 1])); ?>">
                        <?php echo esc_html($l); ?> <span class="tap-tab-count"><?php echo (int) ($ab_map[$k] ?? 0); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($bookings)): ?>
                    <p class="tap-empty"><?php echo esc_html($empty_state); ?></p>
                <?php else: ?>
                <div class="tap-table-scroll">
                <table class="tap-booking-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Code', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Service', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Guest', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Dates', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Commission', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Net', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Status', 'travel-agency-platform'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b):
                            $service_title = get_the_title($b->service_id);
                            if (empty($service_title)) {
                                $service_title = __('Deleted listing', 'travel-agency-platform');
                            }
                            $client = get_userdata($b->client_id);
                            $client_name = $client ? $client->display_name : __('Deleted user', 'travel-agency-platform');
                            $date_label = $b->check_in . ($b->check_out ? ' &rarr; ' . $b->check_out : '');
                        ?>
                        <tr>
                            <td><span class="tap-booking-code"><?php echo esc_html($b->booking_code); ?></span><br>
                                <a class="tap-btn tap-btn-xs" href="<?php echo esc_url(home_url('/booking-detail/?code=' . $b->booking_code)); ?>"><?php esc_html_e('Ver voucher', 'travel-agency-platform'); ?></a>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(get_permalink($b->service_id) ?: home_url()); ?>"><?php echo esc_html($service_title); ?></a>
                                <?php if ($b->room_id): $room = get_post($b->room_id); if ($room): ?>
                                    <span class="tap-booking-room"><?php echo esc_html($room->post_title); ?></span>
                                <?php endif; endif; ?>
                            </td>
                            <td><?php echo esc_html($client_name); ?><br>
                                <small><?php echo esc_html($b->adults . ' ' . __('adults', 'travel-agency-platform') . ($b->children ? ', ' . $b->children . ' ' . __('children', 'travel-agency-platform') : '')); ?></small>
                            </td>
                            <td><?php echo esc_html($date_label); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($b->total_amount)); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($b->commission_amount)); ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt(max(0, (float) $b->total_amount - (float) ($b->booking_fee ?? 0) - (float) $b->commission_amount))); ?></td>
                            <td><span class="tap-status tap-status-<?php echo esc_attr($b->status); ?>"><?php echo esc_html(TAP_Emails::STATUS_LABELS[$b->status] ?? ucfirst($b->status)); ?></span></td>
                            <td class="tap-actions">
                                <?php if (in_array($b->status, ['pending', 'confirmed'])):
                                    $options = ['confirmed' => __('Confirm', 'travel-agency-platform'), 'completed' => __('Complete', 'travel-agency-platform'), 'cancelled' => __('Cancel', 'travel-agency-platform')];
                                    foreach ($options as $s => $label):
                                        if ($b->status === $s) continue;
                                ?>
                                    <button class="tap-btn tap-status-btn" data-booking-id="<?php echo intval($b->id); ?>" data-status="<?php echo esc_attr($s); ?>" <?php echo ($b->status === 'pending' && $s === 'completed') ? 'disabled' : ''; ?>><?php echo esc_html($label); ?></button>
                                <?php endforeach; endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($ab_pages > 1): ?>
                <nav class="tap-pagination"><?php
                    echo paginate_links([
                        'base'      => add_query_arg('ab_page', '%#%'),
                        'format'    => '',
                        'current'   => $ab_page,
                        'total'     => $ab_pages,
                        'prev_text' => '&larr;',
                        'next_text' => '&rarr;',
                    ]);
                ?></nav>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($listings)): ?>
            <div class="tap-panel-section">
                <div class="tap-section-head">
                    <h3><?php esc_html_e('Your Listings', 'travel-agency-platform'); ?></h3>
                    <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/manage-listing/')); ?>">+ <?php esc_html_e('Nuevo alojamiento', 'travel-agency-platform'); ?></a>
                </div>
                <div class="tap-listing-grid">
                    <?php foreach ($listings as $type => $info):
                        $booked = 0;
                        $revenue = 0.0;
                        foreach ($info['ids'] as $pid) {
                            if (!empty($booking_counts[$type . ':' . $pid])) {
                                $booked += $booking_counts[$type . ':' . $pid][0];
                                $revenue += $booking_counts[$type . ':' . $pid][1];
                            }
                        }
                    ?>
                    <div class="tap-listing-card">
                        <div class="tap-listing-title"><?php echo esc_html($info['label']); ?></div>
                        <div class="tap-listing-count"><?php echo esc_html($info['count']); ?></div>
                        <div class="tap-listing-meta"><?php echo esc_html__('listings', 'travel-agency-platform'); ?> &middot; <?php echo esc_html($booked); ?> <?php echo esc_html__('bookings', 'travel-agency-platform'); ?> &middot; <?php echo esc_html(TAP_Currency::fmt($revenue)); ?></div>
                        <?php
                        $manage_url = ('tap_accommodation' === $type)
                            ? home_url('/manage-listing/?id=' . reset($info['ids']))
                            : $info['edit'];
                        ?>
                        <a href="<?php echo esc_url($manage_url); ?>" class="tap-btn tap-btn-sm"><?php esc_html_e('Manage', 'travel-agency-platform'); ?></a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="tap-panel-section">
                <h3><?php esc_html_e('Liquidaciones de comisiones', 'travel-agency-platform'); ?></h3>
                <?php if (!$settlements): ?>
                    <p class="tap-agency-meta"><?php esc_html_e('Aún no hay liquidaciones registradas. El administrador procesa los pagos de tus comisiones.', 'travel-agency-platform'); ?></p>
                <?php else: ?>
                <table class="tap-agency-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Monto', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Método', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Bookings', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Nota', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Fecha', 'travel-agency-platform'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settlements as $s): ?>
                        <tr>
                            <td>#<?php echo (int) $s->id; ?></td>
                            <td><?php echo esc_html(TAP_Currency::fmt($s->amount)); ?></td>
                            <td><?php echo esc_html($s->method); ?></td>
                            <td><?php echo esc_html($s->booking_ids); ?></td>
                            <td><?php echo esc_html($s->note); ?></td>
                            <td><?php echo esc_html($s->created_at); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        </div>
        <script>
        (function($) {
            $('body').on('click', '.tap-status-btn', function(e) {
                e.preventDefault();
                var $btn = $(this), bookingId = $btn.data('booking-id'), status = $btn.data('status');
                $btn.prop('disabled', true).text('...');
                $.post(tap_ajax.ajax_url, {
                    action: 'tap_update_booking_status',
                    booking_id: bookingId,
                    status: status,
                    nonce: tap_ajax.agency_nonce
                }).done(function(res) {
                    if (res && res.success) { window.location.reload(); }
                    else { $btn.prop('disabled', false).text(res && res.data && res.data.message ? '' : '!'); alert(res && res.data ? res.data.message : 'Error'); }
                }).fail(function() {
                    $btn.prop('disabled', false).text('!');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function plans($atts) {
        if (!class_exists('TAP_Subscriptions')) {
            return '<p class="tap-empty">' . esc_html__('Plans are not available.', 'travel-agency-platform') . '</p>';
        }
        $plans = TAP_Subscriptions::get_plans();
        if (!$plans) {
            return '<p class="tap-empty">' . esc_html__('No plans configured yet.', 'travel-agency-platform') . '</p>';
        }

        $user      = wp_get_current_user();
        $agency_id = 0;
        if (is_user_logged_in()) {
            $agency_id = (int) TAP_Booking::get_agency_for_user($user->ID);
        }
        $current      = $agency_id ? TAP_Subscriptions::current_subscription($agency_id) : null;
        $active_plan  = $agency_id ? TAP_Subscriptions::active_plan($agency_id) : null;
        $nonce        = wp_create_nonce('tap_plan_nonce');

        $html = '<div class="tap-plans-grid">';
        foreach ($plans as $plan) {
            $is_current  = $active_plan && (int) $active_plan->id === (int) $plan->id;
            $is_pending  = $current && 'pending' === $current->status && (int) $current->plan_id === (int) $plan->id;
            $features    = json_decode($plan->features ?: '', true);
            if (!is_array($features)) {
                $features = array_filter(array_map('trim', explode("\n", (string) $plan->features)));
            }
            $limit_txt = (int) $plan->listing_limit < 0
                ? __('Listados ilimitados', 'travel-agency-platform')
                : sprintf(__('Hasta %d listados', 'travel-agency-platform'), (int) $plan->listing_limit);
            $commission_txt = null !== $plan->commission_rate
                ? sprintf(__('Comisión solo %s%%', 'travel-agency-platform'), (float) $plan->commission_rate)
                : __('Comisión estándar', 'travel-agency-platform');

            $html .= '<div class="tap-plan-card' . ($is_current ? ' tap-plan-active' : '') . '">';
            $html .= '<div class="tap-plan-head">';
            $html .= '<h3>' . esc_html($plan->name) . '</h3>';
            $html .= '<p class="tap-plan-price">' . esc_html(TAP_Currency::fmt($plan->price_monthly)) . ' <span>' . esc_html__('/mes', 'travel-agency-platform') . '</span></p>';
            $html .= '</div>';
            $html .= '<ul class="tap-plan-features">';
            $html .= '<li>' . esc_html($limit_txt) . '</li>';
            $html .= '<li>' . esc_html($commission_txt) . '</li>';
            $html .= '<li>' . sprintf(esc_html__('%d destacado(s)', 'travel-agency-platform'), (int) $plan->featured_slots) . '</li>';
            foreach ($features as $f) {
                if (is_string($f) && '' !== trim($f)) {
                    $html .= '<li>' . esc_html($f) . '</li>';
                }
            }
            $html .= '</ul>';
            if ($is_current) {
                $html .= '<p class="tap-plan-badge">' . esc_html__('Plan actual', 'travel-agency-platform') . '</p>';
            } elseif ($is_pending) {
                $html .= '<p class="tap-plan-badge tap-plan-pending">' . esc_html__('Pago pendiente de confirmación', 'travel-agency-platform') . '</p>';
            } elseif ($agency_id) {
                $html .= '<button type="button" class="tap-btn tap-btn-primary tap-plan-subscribe" data-plan-id="' . (int) $plan->id . '" data-nonce="' . esc_attr($nonce) . '">' . esc_html__('Seleccionar plan', 'travel-agency-platform') . '</button>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        if ($agency_id && !$current) {
            $html .= '<p class="tap-plan-note">' . esc_html__('Tu agencia usa el plan Gratis. Elige un plan para desbloquear más listados y una comisión menor.', 'travel-agency-platform') . '</p>';
        } elseif (!$agency_id) {
            $html .= '<p class="tap-plan-note">' . esc_html__('Inicia sesión como agencia para seleccionar un plan.', 'travel-agency-platform') . '</p>';
        }

        $html .= '<script>
        (function($){
          $(document).on("click", ".tap-plan-subscribe", function(){
            var $btn = $(this).prop("disabled", true);
            $.post(tap_ajax.ajax_url, {
              action: "tap_agency_subscribe",
              plan_id: $btn.data("plan-id"),
              nonce: $btn.data("nonce")
            }).done(function(res){
              if (res && res.success) {
                alert(res.data.message);
                window.location.reload();
              } else {
                alert(res && res.data && res.data.message ? res.data.message : "Error");
                $btn.prop("disabled", false);
              }
            }).fail(function(){ alert("Error"); $btn.prop("disabled", false); });
          });
        })(jQuery);
        </script>';

        return $html;
    }

    public static function featured_services($atts) {
        $atts = shortcode_atts(['type' => '', 'limit' => 6], $atts);

        $types = $atts['type'] ? (array) $atts['type'] : ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];
        $all_posts = [];

        foreach ($types as $pt) {
            $prefix = '_tap_' . str_replace('tap_', '', $pt);
            $q = new WP_Query([
                'post_type'      => $pt,
                'posts_per_page' => intval($atts['limit']),
                'post_status'    => 'publish',
                'meta_query'     => [
                    ['key' => $prefix . '_is_featured', 'value' => '1'],
                ],
            ]);
            foreach ($q->posts as $p) { $all_posts[] = $p; }
        }

        if (empty($all_posts)) {
            return '<div class="tap-empty"><div class="tap-empty-icon">🏝️</div><h3>' . __('No featured services yet', 'travel-agency-platform') . '</h3><p>' . __('Check back soon for new featured listings.', 'travel-agency-platform') . '</p></div>';
        }

        ob_start();
        ?>
        <div class="tap-grid tap-grid-auto">
            <?php foreach ($all_posts as $post): setup_postdata($post);
                $pt = $post->post_type;
                $price_key = TAP_API::get_price_key($pt);
                $price = $price_key ? floatval(get_post_meta($post->ID, $price_key, true)) : 0;
            ?>
            <div class="tap-card tap-service-card">
                <div class="tap-card-img-wrapper">
                    <?php if (has_post_thumbnail($post->ID)): ?>
                        <?php echo get_the_post_thumbnail($post->ID, 'medium', ['class' => 'tap-card-img']); ?>
                    <?php else: ?>
                        <div class="tap-card-img" style="background: var(--tap-neutral-200); display: flex; align-items: center; justify-content: center; color: var(--tap-neutral-400); font-size: 2rem;">🏖️</div>
                    <?php endif; ?>
                    <span class="tap-badge tap-badge-warning top-right"><?php esc_html_e('Featured', 'travel-agency-platform'); ?></span>
                    <?php if ($price): ?>
                    <div class="tap-card-price"><span class="tap-card-price-amount"><?php echo esc_html(TAP_Currency::fmt0($price)); ?></span> <span class="tap-card-price-label"><?php esc_html_e('starting', 'travel-agency-platform'); ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="tap-card-body">
                    <div class="tap-card-meta">
                        <span><?php echo esc_html(TAP_Post_Types::get_service_types()[$pt] ?? $pt); ?></span>
                    </div>
                    <h3 class="tap-card-title"><a href="<?php the_permalink(); ?>"><?php echo esc_html($post->post_title); ?></a></h3>
                    <p class="tap-card-text"><?php echo wp_trim_words(get_the_excerpt($post), 15); ?></p>
                    <a href="<?php the_permalink(); ?>" class="tap-btn tap-btn-outline tap-btn-block"><?php esc_html_e('View Details', 'travel-agency-platform'); ?></a>
                </div>
            </div>
            <?php endforeach; wp_reset_postdata(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function agency_services($atts) {
        $atts = shortcode_atts(['agency' => 0, 'limit' => -1], $atts);
        $agency_id = intval($atts['agency']);
        if (!$agency_id) return '';

        $all_services = [];
        $types = ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'];

        foreach ($types as $type) {
            $prefix = '_tap_' . str_replace('tap_', '', $type) . '_agency_id';
            $posts = get_posts([
                'post_type'      => $type,
                'posts_per_page' => intval($atts['limit']),
                'post_status'    => 'publish',
                'meta_key'       => $prefix,
                'meta_value'     => $agency_id,
            ]);

            foreach ($posts as $p) {
                $all_services[] = $p;
            }
        }

        if (empty($all_services)) {
            return '<p>' . __('No services from this agency.', 'travel-agency-platform') . '</p>';
        }

        ob_start();
        ?>
        <div class="tap-agency-services">
            <?php foreach ($all_services as $service): ?>
                <div class="tap-service-mini">
                    <a href="<?php echo esc_url(get_permalink($service->ID)); ?>">
                        <?php echo esc_html($service->post_title); ?>
                    </a>
                    <span class="tap-service-type">(<?php echo esc_html(TAP_Post_Types::get_service_types()[$service->post_type] ?? $service->post_type); ?>)</span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function agency_register($atts) {
        if (is_user_logged_in()) {
            return '<p>' . __('You already have an account. Go to your', 'travel-agency-platform') . ' <a href="' . esc_url(home_url('/dashboard/')) . '">' . __('dashboard', 'travel-agency-platform') . '</a>.</p>';
        }

        ob_start();
        ?>
        <div class="tap-register">
            <h2><?php esc_html_e('Registra tu agencia', 'travel-agency-platform'); ?></h2>
            <p class="tap-register-intro"><?php esc_html_e('Crea una cuenta de agencia, publica tus alojamientos y tours, y empieza a recibir reservas de miles de viajeros.', 'travel-agency-platform'); ?></p>

            <form id="tap-agency-register-form" class="tap-form tap-form-grid" autocomplete="on">
                <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
                    <input type="text" name="tap_hp" tabindex="-1" autocomplete="off" value="">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_agency_name"><?php esc_html_e('Agency Name', 'travel-agency-platform'); ?> *</label>
                    <input type="text" id="tap_reg_agency_name" name="agency_name" class="tap-input" required>
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_email"><?php esc_html_e('Email', 'travel-agency-platform'); ?> *</label>
                    <input type="email" id="tap_reg_email" name="email" class="tap-input" required>
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_username"><?php esc_html_e('Username', 'travel-agency-platform'); ?> *</label>
                    <input type="text" id="tap_reg_username" name="username" class="tap-input" pattern="[a-zA-Z0-9_]+" required>
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_password"><?php esc_html_e('Password', 'travel-agency-platform'); ?> *</label>
                    <input type="password" id="tap_reg_password" name="password" class="tap-input" minlength="8" required>
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_phone"><?php esc_html_e('Phone', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap_reg_phone" name="phone" class="tap-input">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_whatsapp"><?php esc_html_e('WhatsApp', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap_reg_whatsapp" name="whatsapp" class="tap-input">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_website"><?php esc_html_e('Website', 'travel-agency-platform'); ?></label>
                    <input type="url" id="tap_reg_website" name="website" class="tap-input">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_city"><?php esc_html_e('City', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap_reg_city" name="city" class="tap-input">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_country"><?php esc_html_e('Country', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap_reg_country" name="country" class="tap-input">
                </div>
                <div class="tap-input-group">
                    <label for="tap_reg_address"><?php esc_html_e('Address', 'travel-agency-platform'); ?></label>
                    <input type="text" id="tap_reg_address" name="address" class="tap-input">
                </div>
                <div class="tap-input-group tap-span-2">
                    <label for="tap_reg_description"><?php esc_html_e('Describe tu agencia', 'travel-agency-platform'); ?></label>
                    <textarea id="tap_reg_description" name="description" class="tap-input" rows="4"></textarea>
                </div>
                <div class="tap-span-2">
                    <div id="tap-register-message" class="tap-booking-message"></div>
                    <button type="submit" class="tap-btn tap-btn-primary tap-btn-block"><?php esc_html_e('Crear mi cuenta de agencia', 'travel-agency-platform'); ?></button>
                </div>
            </form>
        </div>
        <script>
        (function($) {
            $('#tap-agency-register-form').on('submit', function(e) {
                e.preventDefault();
                var $form = $(this), $btn = $form.find('button[type=submit]'), $msg = $('#tap-register-message');
                var data = {
                    action: 'tap_agency_register',
                    nonce: tap_ajax.register_nonce
                };
                $.each($form.serializeArray(), function(i, f) { data[f.name] = f.value; });
                $msg.removeClass('tap-success tap-error').html('');
                $btn.prop('disabled', true).text('...');
                $.post(tap_ajax.ajax_url, data)
                    .done(function(res) {
                        if (res && res.success) {
                            $msg.removeClass('tap-error').addClass('tap-success')
                                .html('<p>' + (res.data.message || 'OK') + '</p>');
                            window.location.href = res.data.redirect;
                        } else {
                            $msg.removeClass('tap-success').addClass('tap-error')
                                .html('<p>' + (res && res.data ? res.data.message : 'Error') + '</p>');
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Crear mi cuenta de agencia', 'travel-agency-platform')); ?>');
                        }
                    })
                    .fail(function() {
                        $msg.removeClass('tap-success').addClass('tap-error')
                            .html('<p><?php echo esc_js(__('Network error. Please try again.', 'travel-agency-platform')); ?></p>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Crear mi cuenta de agencia', 'travel-agency-platform')); ?>');
                    });
            });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    public static function checkout($atts) {
        $atts = shortcode_atts(['code' => ''], $atts);

        $booking_code = $atts['code'] ?: sanitize_text_field($_GET['code'] ?? '');
        if (!$booking_code) {
            return '<p>' . __('No booking specified.', 'travel-agency-platform') . '</p>';
        }

        $booking = TAP_Booking::get_booking_by_code($booking_code);
        if (!$booking) {
            return '<p>' . __('Booking not found.', 'travel-agency-platform') . '</p>';
        }

        if (!is_user_logged_in() || $booking->client_id != get_current_user_id()) {
            return '<p>' . __('Please log in to view this booking.', 'travel-agency-platform') . '</p>';
        }

        $service = get_post($booking->service_id);
        $status_success = isset($_GET['status']) && $_GET['status'] === 'success';
        $status_cancel  = isset($_GET['status']) && $_GET['status'] === 'cancel';

        ob_start();
        ?>
        <div class="tap-checkout">
            <h2><?php esc_html_e('Complete Your Payment', 'travel-agency-platform'); ?></h2>

            <?php if ($status_success): ?>
                <div class="tap-success"><?php esc_html_e('Payment completed! Thank you for your booking.', 'travel-agency-platform'); ?></div>
            <?php elseif ($status_cancel): ?>
                <div class="tap-error"><?php esc_html_e('Payment was cancelled. You can try again.', 'travel-agency-platform'); ?></div>
            <?php endif; ?>

            <?php if ($booking->payment_status === 'paid'): ?>
                <div class="tap-success">
                    <p><?php esc_html_e('This booking has been paid.', 'travel-agency-platform'); ?></p>
                    <p><strong><?php esc_html_e('Booking Code:', 'travel-agency-platform'); ?></strong> <?php echo esc_html($booking->booking_code); ?></p>
                    <a href="<?php echo esc_url(home_url('/my-bookings')); ?>" class="tap-btn tap-btn-primary"><?php esc_html_e('View My Bookings', 'travel-agency-platform'); ?></a>
                </div>
                <?php return ob_get_clean(); ?>
            <?php endif; ?>

            <div class="tap-checkout-summary">
                <h3><?php esc_html_e('Booking Summary', 'travel-agency-platform'); ?></h3>
                <table class="tap-table">
                    <tr><td><strong><?php esc_html_e('Code:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->booking_code); ?></td></tr>
                    <tr><td><strong><?php esc_html_e('Service:', 'travel-agency-platform'); ?></strong></td><td><?php echo $service ? esc_html($service->post_title) : 'N/A'; ?></td></tr>
                    <?php if ($booking->check_in): ?>
                    <tr><td><strong><?php esc_html_e('Check-in:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->check_in); ?></td></tr>
                    <?php endif; ?>
                    <?php if ($booking->check_out): ?>
                    <tr><td><strong><?php esc_html_e('Check-out:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->check_out); ?></td></tr>
                    <?php endif; ?>
                    <tr><td><strong><?php esc_html_e('Adults:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->adults); ?></td></tr>
                    <tr><td><strong><?php esc_html_e('Children:', 'travel-agency-platform'); ?></strong></td><td><?php echo esc_html($booking->children); ?></td></tr>
                    <tr><td><strong><?php esc_html_e('Total:', 'travel-agency-platform'); ?></strong></td><td><strong><?php echo esc_html(TAP_Currency::fmt($booking->total_amount)); ?></strong></td></tr>
                    <tr><td><strong><?php esc_html_e('Status:', 'travel-agency-platform'); ?></strong></td><td><span class="tap-status tap-status-<?php echo esc_attr($booking->status); ?>"><?php echo esc_html(ucfirst($booking->status)); ?></span></td></tr>
                </table>
            </div>

<?php if ($booking->total_amount > 0 && $booking->payment_status !== 'paid'): ?>
                <div class="tap-checkout-payment">
                    <h3><?php esc_html_e('Pay with PayPal', 'travel-agency-platform'); ?></h3>
                    <?php if (TAP_PayPal::is_ready()): ?>
                    <div id="tap-paypal-button-container"></div>
                    <div id="tap-paypal-message" class="tap-booking-message"></div>

                    <script src="https://www.paypal.com/sdk/js?client-id=<?php echo esc_attr(TAP_PayPal::get_client_id()); ?>&currency=USD" data-namespace="tapPayPal"></script>
                <script>
                jQuery(document).ready(function($) {
                    var bookingId = <?php echo intval($booking->id); ?>;
                    var paypalLoaded = false;

                    function loadPayPal() {
                        if (typeof tapPayPal === 'undefined' || paypalLoaded) return;
                        paypalLoaded = true;

                        tapPayPal.Buttons({
                            createOrder: function() {
                                return $.ajax({
                                    url: tap_ajax.ajax_url,
                                    type: 'POST',
                                    data: {
                                        action: 'tap_create_paypal_order',
                                        booking_id: bookingId,
                                        nonce: tap_ajax.nonce
                                    }
                                }).then(function(res) {
                                    if (res.success) {
                                        return res.data.order_id;
                                    }
                                    throw new Error(res.data.message || '<?php echo esc_js(__('Error creating PayPal order', 'travel-agency-platform')); ?>');
                                });
                            },
                            onApprove: function(data) {
                                var msg = $('#tap-paypal-message');
                                msg.removeClass('tap-error tap-success').html('<p><?php echo esc_js(__('Processing payment...', 'travel-agency-platform')); ?></p>');

                                $.ajax({
                                    url: tap_ajax.ajax_url,
                                    type: 'POST',
                                    data: {
                                        action: 'tap_capture_paypal_order',
                                        paypal_order_id: data.orderID,
                                        nonce: tap_ajax.nonce
                                    }
                                }).then(function(res) {
                                    if (res.success) {
                                        msg.removeClass('tap-error').addClass('tap-success')
                                           .html('<p><?php echo esc_js(__('Payment successful! Your booking is confirmed.', 'travel-agency-platform')); ?></p>');
                                        setTimeout(function() {
                                            window.location.href = '<?php echo esc_url(home_url('/my-bookings')); ?>';
                                        }, 2000);
                                    } else {
                                        msg.removeClass('tap-success').addClass('tap-error')
                                           .html('<p>' + (res.data.message || '<?php echo esc_js(__('Payment failed.', 'travel-agency-platform')); ?>') + '</p>');
                                    }
                                });
                            },
                            onCancel: function() {
                                $('#tap-paypal-message').removeClass('tap-success').addClass('tap-error')
                                    .html('<p><?php echo esc_js(__('Payment cancelled.', 'travel-agency-platform')); ?></p>');
                            },
                            onError: function(err) {
                                $('#tap-paypal-message').removeClass('tap-success').addClass('tap-error')
                                    .html('<p><?php echo esc_js(__('An error occurred with PayPal.', 'travel-agency-platform')); ?></p>');
                                console.error('PayPal Error:', err);
                            }
                        }).render('#tap-paypal-button-container');
                    }

                    var checkInterval = setInterval(function() {
                        if (typeof tapPayPal !== 'undefined') {
                            loadPayPal();
                            clearInterval(checkInterval);
                        }
                    }, 300);

                    setTimeout(function() { clearInterval(checkInterval); }, 15000);
                });
                </script>
                    <?php else: ?>
                    <p class="tap-empty"><?php esc_html_e('Online payment is temporarily unavailable. Your reservation is registered and will be confirmed by the agency.', 'travel-agency-platform'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function agency_manage($atts) {
        if (!is_user_logged_in()) {
            return '<p class="tap-empty"><a href="' . esc_url(wp_login_url(home_url('/dashboard/'))) . '">' . esc_html__('Log in to manage your listings', 'travel-agency-platform') . '</a></p>';
        }

        $user    = wp_get_current_user();
        $is_admin = user_can($user, 'manage_options');
        $agency  = $is_admin ? 0 : TAP_Booking::get_agency_for_user($user->ID);
        if (!$is_admin && !$agency) {
            return '<p class="tap-empty">' . esc_html__('Only agency accounts can manage listings.', 'travel-agency-platform') . '</p>';
        }

        $acc_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $is_new = isset($_GET['new']);

        if ($acc_id) {
            $acc = get_post($acc_id);
            if (!$acc || 'tap_accommodation' !== $acc->post_type) {
                return '<p class="tap-empty">' . esc_html__('Listing not found.', 'travel-agency-platform') . '</p>';
            }
            if (!$is_admin && (int) get_post_meta($acc_id, '_tap_acc_agency_id', true) !== (int) $agency) {
                return '<p class="tap-empty">' . esc_html__('You can only manage your own listings.', 'travel-agency-platform') . '</p>';
            }
            return self::render_manage_editor($acc_id);
        }

        return self::render_manage_index($is_new, $agency, $is_admin);
    }

    private static function manage_agency_id($agency, $is_admin) {
        if ($is_admin) {
            $posted = isset($_GET['agency_id']) ? intval($_GET['agency_id']) : 0;
            return $posted ? $posted : 0;
        }
        return (int) $agency;
    }

    private static function render_manage_index($is_new, $agency, $is_admin) {
        $agency_id = self::manage_agency_id($agency, $is_admin);
        ob_start();
        ?>
        <div class="tap-agency-panel tap-manage-wrap">
            <div class="tap-panel-head">
                <div>
                    <h2 class="tap-panel-title"><?php esc_html_e('Manage your listings', 'travel-agency-platform'); ?></h2>
                    <p class="tap-panel-sub"><?php esc_html_e('Create and edit your accommodations and rooms from the front-end.', 'travel-agency-platform'); ?></p>
                </div>
                <a class="tap-btn tap-btn-primary" href="<?php echo esc_url(home_url('/manage-listing/?new=accommodation')); ?>">+ <?php esc_html_e('Nuevo alojamiento', 'travel-agency-platform'); ?></a>
            </div>

            <?php if ($is_new): ?>
                <?php echo self::render_manage_editor(0); ?>
            <?php else: ?>
            <div class="tap-grid tap-listings-grid">
                <?php
                $args = [
                    'post_type'      => 'tap_accommodation',
                    'post_status'    => 'publish',
                    'posts_per_page' => 50,
                    'meta_key'       => '_tap_acc_agency_id',
                ];
                $args = $agency_id
                    ? array_merge($args, ['meta_value' => $agency_id])
                    : ['post_type' => 'tap_accommodation', 'post_status' => 'publish', 'posts_per_page' => 50];

                $listings = get_posts($args);
                if (!$listings) {
                    echo '<p class="tap-empty">' . esc_html__('You have no listings yet. Create your first accommodation.', 'travel-agency-platform') . '</p>';
                }
                foreach ($listings as $l) {
                    $rooms      = get_posts(['post_type' => 'tap_room', 'post_status' => 'publish', 'posts_per_page' => -1, 'meta_key' => '_tap_room_accommodation_id', 'meta_value' => $l->ID]);
                    $active     = '1' === get_post_meta($l->ID, '_tap_acc_is_active', true);
                    $edit_url   = home_url('/manage-listing/?id=' . $l->ID);
                    ?>
                    <div class="tap-listing-card">
                        <div class="tap-listing-card-head">
                            <strong><?php echo esc_html($l->post_title); ?></strong>
                            <span class="tap-badge <?php echo $active ? 'tap-badge-ok' : 'tap-badge-off'; ?>"><?php echo $active ? esc_html__('Activo', 'travel-agency-platform') : esc_html__('Inactivo', 'travel-agency-platform'); ?></span>
                        </div>
                        <div class="tap-listing-card-meta">
                            <span><?php echo count($rooms); ?> <?php esc_html_e('habitaciones', 'travel-agency-platform'); ?></span>
                            <span><?php echo esc_html(get_post_meta($l->ID, '_tap_acc_city', true) ?: 'Sin ciudad'); ?></span>
                        </div>
                        <a class="tap-btn tap-btn-sm" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Editar', 'travel-agency-platform'); ?></a>
                    </div>
                    <?php
                }
                ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_manage_editor($acc_id) {
        $is_edit = (bool) $acc_id;
        $acc     = $is_edit ? get_post($acc_id) : null;

        $types = ['hotel' => 'Hotel', 'hostel' => 'Hostel', 'resort' => 'Resort', 'villa' => 'Villa', 'cabin' => 'Cabin', 'apartment' => 'Apartment', 'boutique' => 'Boutique Hotel', 'eco' => 'Eco-Lodge'];

        ob_start();
        ?>
        <div class="tap-agency-panel tap-manage-wrap">
            <div class="tap-panel-head">
                <div>
                    <h2 class="tap-panel-title"><?php echo $is_edit ? esc_html__('Edit listing', 'travel-agency-platform') : esc_html__('New accommodation', 'travel-agency-platform'); ?></h2>
                </div>
                <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/manage-listing/')); ?>">&larr; <?php esc_html_e('Volver', 'travel-agency-platform'); ?></a>
            </div>

            <form class="tap-manage-form" id="tap-listing-form">
                <?php wp_nonce_field('tap_agency_listing_nonce', 'nonce'); ?>
                <input type="hidden" name="listing_id" value="<?php echo (int) $acc_id; ?>">
                <div class="tap-form-grid">
                    <div class="tap-field tap-span-2">
                        <label><?php esc_html_e('Nombre del alojamiento *', 'travel-agency-platform'); ?></label>
                        <input type="text" name="title" required value="<?php echo esc_attr($acc ? $acc->post_title : ''); ?>">
                    </div>
                    <div class="tap-field tap-span-2">
                        <label><?php esc_html_e('Descripción', 'travel-agency-platform'); ?></label>
                        <textarea name="description" rows="4"><?php echo esc_textarea($acc ? $acc->post_content : ''); ?></textarea>
                    </div>
                    <div class="tap-field">
                        <label><?php esc_html_e('Tipo', 'travel-agency-platform'); ?></label>
                        <select name="type">
                            <?php foreach ($types as $k => $v) {
                                $sel = $acc && $k === get_post_meta($acc_id, '_tap_acc_type', true) ? ' selected' : '';
                                echo '<option value="' . esc_attr($k) . '"' . $sel . '>' . esc_html($v) . '</option>';
                            } ?>
                        </select>
                    </div>
                    <div class="tap-field">
                        <label><?php esc_html_e('Estrellas', 'travel-agency-platform'); ?></label>
                        <select name="stars">
                            <option value="">—</option>
                            <?php foreach (['1', '2', '3', '4', '5'] as $s) {
                                $sel = $acc && $s === get_post_meta($acc_id, '_tap_acc_stars', true) ? ' selected' : '';
                                echo '<option value="' . esc_attr($s) . '"' . $sel . '>' . esc_html($s) . '★</option>';
                            } ?>
                        </select>
                    </div>
                    <div class="tap-field">
                        <label><?php esc_html_e('Precio/noche ($) — fallback sin habitaciones', 'travel-agency-platform'); ?></label>
                        <input type="number" name="price_per_night" min="0" step="0.01" value="<?php echo esc_attr($acc ? get_post_meta($acc_id, '_tap_acc_price_per_night', true) : ''); ?>">
                    </div>
                    <div class="tap-field">
                        <label><?php esc_html_e('Máx. huéspedes', 'travel-agency-platform'); ?></label>
                        <input type="number" name="capacity" min="0" value="<?php echo esc_attr($acc ? get_post_meta($acc_id, '_tap_acc_capacity', true) : ''); ?>">
                    </div>
                    <div class="tap-field">
                        <label><?php esc_html_e('Dormitorios', 'travel-agency-platform'); ?></label>
                        <input type="number" name="bedrooms" min="0" value="<?php echo esc_attr($acc ? get_post_meta($acc_id, '_tap_acc_bedrooms', true) : ''); ?>">
                    </div>
                    <div class="tap-field">
                        <label><?php esc_html_e('Baños', 'travel-agency-platform'); ?></label>
                        <input type="number" name="bathrooms" min="0" value="<?php echo esc_attr($acc ? get_post_meta($acc_id, '_tap_acc_bathrooms', true) : ''); ?>">
                    </div>
                    <div class="tap-field">
                        <label><?php esc_html_e('Check-in', 'travel-agency-platform'); ?></label>
                        <input type="time" name="checkin_time" value="<?php echo esc_attr($acc ? get_post_meta($acc_id, '_tap_acc_checkin_time', true) : '15:00'); ?>">
                    </div>
                    <div class="tap-field">
                        <label><?php esc_html_e('Check-out', 'travel-agency-platform'); ?></label>
                        <input type="time" name="checkout_time" value="<?php echo esc_attr($acc ? get_post_meta($acc_id, '_tap_acc_checkout_time', true) : '11:00'); ?>">
                    </div>
                    <div class="tap-field">
                        <label><?php esc_html_e('Latitud', 'travel-agency-platform'); ?></label>
                        <input type="text" name="lat" value="<?php echo esc_attr($acc ? get_post_meta($acc_id, '_tap_acc_lat', true) : ''); ?>">
                    </div>
                    <div class="tap-field">
                        <label><?php esc_html_e('Longitud', 'travel-agency-platform'); ?></label>
                        <input type="text" name="lng" value="<?php echo esc_attr($acc ? get_post_meta($acc_id, '_tap_acc_lng', true) : ''); ?>">
                    </div>
                    <div class="tap-field">
                        <label><?php esc_html_e('Moneda', 'travel-agency-platform'); ?></label>
                        <input type="text" name="currency" value="<?php echo esc_attr($acc ? get_post_meta($acc_id, '_tap_acc_currency', true) : 'USD'); ?>">
                    </div>
                    <div class="tap-field">
                        <label class="tap-check-label">
                            <input type="checkbox" name="is_active" value="1" <?php checked('1', $acc ? get_post_meta($acc_id, '_tap_acc_is_active', true) : '1'); ?>>
                            <?php esc_html_e('Activo (visible en la web)', 'travel-agency-platform'); ?>
                        </label>
                    </div>
                </div>
                <button type="submit" class="tap-btn tap-btn-primary"><?php echo $is_edit ? esc_html__('Guardar cambios', 'travel-agency-platform') : esc_html__('Crear alojamiento', 'travel-agency-platform'); ?></button>
                <span class="tap-form-msg"></span>
            </form>

            <?php if ($is_edit): ?>
                <?php
                $rooms = get_posts(['post_type' => 'tap_room', 'post_status' => ['publish', 'draft'], 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => '_tap_room_accommodation_id', 'meta_value' => $acc_id]);
                ?>
                <hr class="tap-manage-hr">
                <h3 class="tap-panel-title"><?php esc_html_e('Habitaciones', 'travel-agency-platform'); ?></h3>

                <?php if ($rooms): ?>
                <div class="tap-rooms-list">
                    <?php foreach ($rooms as $r): ?>
                    <form class="tap-manage-form tap-room-form" data-room="<?php echo (int) $r->ID; ?>">
                        <?php wp_nonce_field('tap_agency_listing_nonce', 'nonce'); ?>
                        <input type="hidden" name="room_id" value="<?php echo (int) $r->ID; ?>">
                        <input type="hidden" name="accommodation_id" value="<?php echo (int) $acc_id; ?>">
                        <div class="tap-form-grid">
                            <div class="tap-field"><label><?php esc_html_e('Nombre *', 'travel-agency-platform'); ?></label><input type="text" name="title" required value="<?php echo esc_attr($r->post_title); ?>"></div>
                            <div class="tap-field"><label><?php esc_html_e('Precio/noche ($) *', 'travel-agency-platform'); ?></label><input type="number" name="price_per_night" min="0.01" step="0.01" required value="<?php echo esc_attr(get_post_meta($r->ID, '_tap_room_price_per_night', true)); ?>"></div>
                            <div class="tap-field"><label><?php esc_html_e('Máx. adultos', 'travel-agency-platform'); ?></label><input type="number" name="max_adults" min="1" value="<?php echo esc_attr(get_post_meta($r->ID, '_tap_room_max_adults', true)); ?>"></div>
                            <div class="tap-field"><label><?php esc_html_e('Máx. ocupación', 'travel-agency-platform'); ?></label><input type="number" name="max_occupancy" min="1" value="<?php echo esc_attr(get_post_meta($r->ID, '_tap_room_max_occupancy', true)); ?>"></div>
                            <div class="tap-field"><label><?php esc_html_e('Cantidad de esta habitación', 'travel-agency-platform'); ?></label><input type="number" name="inventory" min="1" value="<?php echo esc_attr(get_post_meta($r->ID, '_tap_room_inventory', true)); ?>"></div>
                            <div class="tap-field"><label><?php esc_html_e('Estadía mínima', 'travel-agency-platform'); ?></label><input type="number" name="min_stay" min="1" value="<?php echo esc_attr(get_post_meta($r->ID, '_tap_room_min_stay', true)); ?>"></div>
                            <div class="tap-field"><label><?php esc_html_e('Tamaño', 'travel-agency-platform'); ?></label><input type="text" name="size" value="<?php echo esc_attr(get_post_meta($r->ID, '_tap_room_size', true)); ?>"></div>
                            <div class="tap-field"><label><?php esc_html_e('Vista', 'travel-agency-platform'); ?></label><input type="text" name="view" value="<?php echo esc_attr(get_post_meta($r->ID, '_tap_room_view', true)); ?>"></div>
                            <div class="tap-field">
                                <label class="tap-check-label"><input type="checkbox" name="is_active" value="1" <?php checked('1', get_post_meta($r->ID, '_tap_room_is_active', true)); ?>> <?php esc_html_e('Activa', 'travel-agency-platform'); ?></label>
                            </div>
                            <div class="tap-field tap-field-actions">
                                <button type="submit" class="tap-btn tap-btn-primary tap-btn-sm"><?php esc_html_e('Guardar', 'travel-agency-platform'); ?></button>
                                <button type="button" class="tap-btn tap-btn-danger tap-btn-sm tap-delete-room"><?php esc_html_e('Eliminar', 'travel-agency-platform'); ?></button>
                            </div>
                        </div>
                    </form>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form class="tap-manage-form tap-room-form" id="tap-room-add">
                    <?php wp_nonce_field('tap_agency_listing_nonce', 'nonce'); ?>
                    <input type="hidden" name="room_id" value="0">
                    <input type="hidden" name="accommodation_id" value="<?php echo (int) $acc_id; ?>">
                    <div class="tap-form-grid">
                        <div class="tap-field"><label><?php esc_html_e('Nueva habitación — nombre *', 'travel-agency-platform'); ?></label><input type="text" name="title" required placeholder="<?php esc_attr_e('e.g. Habitación Doble Estándar', 'travel-agency-platform'); ?>"></div>
                        <div class="tap-field"><label><?php esc_html_e('Precio/noche ($) *', 'travel-agency-platform'); ?></label><input type="number" name="price_per_night" min="0.01" step="0.01" required></div>
                        <div class="tap-field"><label><?php esc_html_e('Máx. adultos', 'travel-agency-platform'); ?></label><input type="number" name="max_adults" min="1" value="2"></div>
                        <div class="tap-field"><label><?php esc_html_e('Máx. ocupación', 'travel-agency-platform'); ?></label><input type="number" name="max_occupancy" min="1" value="2"></div>
                        <div class="tap-field"><label><?php esc_html_e('Cantidad de esta habitación', 'travel-agency-platform'); ?></label><input type="number" name="inventory" min="1" value="1"></div>
                        <div class="tap-field tap-field-actions"><button type="submit" class="tap-btn tap-btn-primary">+ <?php esc_html_e('Añadir habitación', 'travel-agency-platform'); ?></button><span class="tap-form-msg"></span></div>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <script>
        (function(){
            var url = tap_ajax.ajax_url;
            var nonce = tap_ajax.listing_nonce;
            var msgBox = function(f, m){ var el = f.querySelector('.tap-form-msg'); if(el){ el.textContent = m; } };

            var listing = document.getElementById('tap-listing-form');
            if(listing){
                listing.addEventListener('submit', function(ev){
                    ev.preventDefault();
                    var btn = listing.querySelector('button[type=submit]'); btn.disabled = true;
                    var data = new FormData(listing); data.append('action','tap_agency_save_listing');
                    data.set('nonce', nonce);
                    fetch(url, {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        btn.disabled = false;
                        if(j.success){ window.location.href = j.data.edit_url; }
                        else { msgBox(listing, j.data.message || 'Error'); }
                    })
                    .catch(function(){ btn.disabled = false; msgBox(listing, 'Network error'); });
                });
            }

            var addRoom = document.getElementById('tap-room-add');
            if(addRoom){
                addRoom.addEventListener('submit', function(ev){
                    ev.preventDefault();
                    var btn = addRoom.querySelector('button[type=submit]'); btn.disabled = true;
                    var data = new FormData(addRoom); data.append('action','tap_agency_save_room');
                    data.set('nonce', nonce);
                    fetch(url, {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        btn.disabled = false;
                        if(j.success){ window.location.reload(); }
                        else { msgBox(addRoom, j.data.message || 'Error'); }
                    })
                    .catch(function(){ btn.disabled = false; msgBox(addRoom, 'Network error'); });
                });
            }

            document.querySelectorAll('.tap-room-form[data-room]').forEach(function(form){
                form.addEventListener('submit', function(ev){
                    ev.preventDefault();
                    var btn = form.querySelector('button[type=submit]'); btn.disabled = true;
                    var data = new FormData(form); data.append('action','tap_agency_save_room');
                    data.set('nonce', nonce);
                    fetch(url, {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        btn.disabled = false;
                        if(j.success){ msgBox(form, j.data.message); }
                        else { msgBox(form, j.data.message || 'Error'); }
                    })
                    .catch(function(){ btn.disabled = false; msgBox(form, 'Network error'); });
                });
                var del = form.querySelector('.tap-delete-room');
                if(del){
                    del.addEventListener('click', function(){
                        if(!window.confirm('Delete this room?')){ return; }
                        var data = new FormData(); data.append('action','tap_agency_delete_room');
                        data.append('room_id', form.getAttribute('data-room'));
                        data.append('nonce', nonce);
                        fetch(url, {method:'POST', body:data, credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(j){ if(j.success){ window.location.reload(); } else { window.alert(j.data.message || 'Error'); } });
});
            }
        });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public static function booking_detail($atts) {
        if (!is_user_logged_in()) {
            return '<p class="tap-empty"><a href="' . esc_url(wp_login_url(home_url('/booking-detail/'))) . '">' . esc_html__('Log in to view your booking voucher', 'travel-agency-platform') . '</a></p>';
        }

        global $wpdb;
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';

        if ('' === $code) {
            ob_start();
            ?>
            <div class="tap-voucher-lookup">
                <p><?php esc_html_e('Enter your booking code to view the detail and voucher.', 'travel-agency-platform'); ?></p>
                <form class="tap-manage-form" method="get" action="">
                    <div class="tap-form-grid">
                        <div class="tap-field tap-span-2">
                            <label><?php esc_html_e('Código de reserva', 'travel-agency-platform'); ?></label>
                            <input type="text" name="code" required placeholder="TAP-XXXXXXXX-XXXXXX">
                        </div>
                        <div class="tap-field tap-field-actions">
                            <button type="submit" class="tap-btn tap-btn-primary"><?php esc_html_e('Ver voucher', 'travel-agency-platform'); ?></button>
                        </div>
                    </div>
                </form>
            </div>
            <?php
            return ob_get_clean();
        }

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tap_bookings WHERE booking_code = %s",
            $code
        ));
        if (!$booking) {
            return '<p class="tap-empty">' . esc_html__('Booking not found.', 'travel-agency-platform') . '</p>';
        }

        $uid  = get_current_user_id();
        $can  = (int) $booking->client_id === $uid;
        if (!$can && user_can($uid, 'manage_options')) $can = true;
        if (!$can && $booking->agency_id) {
            $agency = TAP_Booking::get_agency_for_user($uid);
            if ($agency && (int) $agency === (int) $booking->agency_id) $can = true;
        }
        if (!$can) {
            return '<p class="tap-empty">' . esc_html__('You do not have permission to view this booking.', 'travel-agency-platform') . '</p>';
        }

        $user    = get_userdata($booking->client_id);
        $service = get_post($booking->service_id);
        $room    = $booking->room_id ? get_post($booking->room_id) : null;
        $agency  = $booking->agency_id ? get_post($booking->agency_id) : null;

        $service_name = $room ? $room->post_title : ($service ? $service->post_title : 'N/A');
        $nights = max(1, (int) $booking->nights);
        $lines  = [
            [
                'desc' => sprintf('%s · %d %s', $service_name, $nights, _n('night', 'nights', $nights, 'travel-agency-platform')),
                'qty'  => 1,
                'unit' => (float) $booking->total_amount,
            ],
        ];

        $status_labels = TAP_Emails::STATUS_LABELS;
        $pstatus = [
            'pending' => __('Pendiente', 'travel-agency-platform'),
            'paid'    => __('Pagado', 'travel-agency-platform'),
            'partial' => __('Parcial', 'travel-agency-platform'),
            'refunded'=> __('Reembolsado', 'travel-agency-platform'),
            'failed'  => __('Fallido', 'travel-agency-platform'),
        ];

        $agency_email = $agency ? get_post_meta($agency->ID, '_tap_agency_email', true) : '';
        $agency_phone = $agency ? get_post_meta($agency->ID, '_tap_agency_phone', true) : '';
        $agency_wa    = $agency ? get_post_meta($agency->ID, '_tap_agency_whatsapp', true) : '';
        $agency_web   = $agency ? get_post_meta($agency->ID, '_tap_agency_website', true) : '';
        $agency_city  = $agency ? get_post_meta($agency->ID, '_tap_agency_city', true) : '';
        $agency_country = $agency ? get_post_meta($agency->ID, '_tap_agency_country', true) : '';

        ob_start();
        ?>
        <div class="tap-voucher">
            <div class="tap-voucher-tools">
                <a class="tap-btn tap-btn-sm" href="<?php echo esc_url(home_url('/my-bookings/')); ?>">&larr; <?php esc_html_e('Mis reservas', 'travel-agency-platform'); ?></a>
                <button type="button" class="tap-btn tap-btn-sm" onclick="window.print()">🖨 <?php esc_html_e('Imprimir voucher', 'travel-agency-platform'); ?></button>
                <?php if ((int) $booking->client_id === $uid && in_array($booking->status, ['pending', 'confirmed'], true) && (!$booking->check_in || $booking->check_in >= gmdate('Y-m-d'))): ?>
                    <button type="button" class="tap-btn tap-btn-sm tap-btn-danger tap-cancel-booking-btn" data-booking-id="<?php echo (int) $booking->id; ?>" data-confirm="<?php echo esc_attr($booking->booking_code); ?>"><?php esc_html_e('Cancelar reserva', 'travel-agency-platform'); ?></button>
                <?php endif; ?>
            </div>
            <div class="tap-voucher-inner">
                <div class="tap-voucher-head">
                    <div>
                        <h2><?php esc_html_e('Voucher de Reserva', 'travel-agency-platform'); ?></h2>
                        <span class="tap-voucher-ref"><?php esc_html_e('Ref', 'travel-agency-platform'); ?>: <strong><?php echo esc_html($booking->booking_code); ?></strong></span>
                    </div>
                    <div class="tap-voucher-badges">
                        <span class="tap-badge tap-badge-ok"><?php echo esc_html($status_labels[$booking->status] ?? ucfirst($booking->status)); ?></span>
                        <span class="tap-badge"><?php echo esc_html($pstatus[$booking->payment_status] ?? ucfirst($booking->payment_status)); ?></span>
                    </div>
                </div>

                <div class="tap-voucher-body">
                    <div class="tap-voucher-service">
                        <h3><?php echo esc_html($service_name); ?></h3>
                        <?php if ($agency): ?>
                        <p><?php esc_html_e('Operada por', 'travel-agency-platform'); ?> <strong><?php echo esc_html($agency->post_title); ?></strong></p>
                        <?php endif; ?>
                    </div>

                    <div class="tap-voucher-grid">
                        <div class="tap-voucher-cell">
                            <span class="tap-voucher-label"><?php esc_html_e('Check-in', 'travel-agency-platform'); ?></span>
                            <strong><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($booking->check_in))); ?></strong>
                        </div>
                        <div class="tap-voucher-cell">
                            <span class="tap-voucher-label"><?php esc_html_e('Check-out', 'travel-agency-platform'); ?></span>
                            <strong><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($booking->check_out))); ?></strong>
                        </div>
                        <div class="tap-voucher-cell">
                            <span class="tap-voucher-label"><?php esc_html_e('Noches', 'travel-agency-platform'); ?></span>
                            <strong><?php echo (int) $nights; ?></strong>
                        </div>
                        <div class="tap-voucher-cell">
                            <span class="tap-voucher-label"><?php esc_html_e('Huéspedes', 'travel-agency-platform'); ?></span>
                            <strong><?php echo (int) $booking->adults; ?> <?php esc_html_e('adultos', 'travel-agency-platform'); ?><?php echo $booking->children ? ' · ' . (int) $booking->children . ' ' . esc_html__('niños', 'travel-agency-platform') : ''; ?></strong>
                        </div>
                    </div>

                    <table class="tap-voucher-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Descripción', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Cant.', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Precio', 'travel-agency-platform'); ?></th>
                                <th><?php esc_html_e('Total', 'travel-agency-platform'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lines as $item): ?>
                            <tr>
                                <td><?php echo esc_html($item['desc']); ?></td>
                                <td><?php echo (int) $item['qty']; ?></td>
                                <td><?php echo esc_html(TAP_Currency::fmt($item['unit'] / max(1, $item['qty']))); ?></td>
                                <td><?php echo esc_html(TAP_Currency::fmt($item['unit'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <?php if ((float) ($booking->booking_fee ?? 0) > 0): ?>
                            <tr>
                                <td colspan="3" class="tap-voucher-total-label"><?php esc_html_e('Tarifa de servicio', 'travel-agency-platform'); ?></td>
                                <td class="tap-voucher-total"><?php echo esc_html(TAP_Currency::fmt((float) $booking->booking_fee)); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td colspan="3" class="tap-voucher-total-label"><?php esc_html_e('Total', 'travel-agency-platform'); ?></td>
                                <td class="tap-voucher-total"><?php echo esc_html(TAP_Currency::fmt((float) $booking->total_amount)); ?></td>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="tap-voucher-two-col">
                        <div class="tap-voucher-info">
                            <h4><?php esc_html_e('Titular', 'travel-agency-platform'); ?></h4>
                            <p><strong><?php echo $user ? esc_html($user->display_name) : 'N/A'; ?></strong><br><?php echo $user ? esc_html($user->user_email) : ''; ?></p>
                            <?php if ($booking->guest_name): ?>
                            <h4><?php esc_html_e('Huésped de contacto', 'travel-agency-platform'); ?></h4>
                            <p>
                                <strong><?php echo esc_html($booking->guest_name); ?></strong>
                                <?php if ($booking->guest_email): ?><br><?php echo esc_html($booking->guest_email); ?><?php endif; ?>
                                <?php if ($booking->guest_phone): ?><br><?php echo esc_html($booking->guest_phone); ?><?php endif; ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php if ($agency): ?>
                        <div class="tap-voucher-info">
                            <h4><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></h4>
                            <p>
                                <strong><?php echo esc_html($agency->post_title); ?></strong><br>
                                <?php echo esc_html(implode(', ', array_filter([$agency_city, $agency_country]))); ?><br>
                                <?php if ($agency_email): ?><?php echo esc_html($agency_email); ?><br><?php endif; ?>
                                <?php if ($agency_phone || $agency_wa): ?><?php echo esc_html($agency_wa ?: $agency_phone); ?><?php endif; ?>
                                <?php if ($agency_web): ?><br><?php echo esc_html($agency_web); ?><?php endif; ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="tap-voucher-notes">
                        <small><?php esc_html_e('Presenta este voucher el día del check-in. Cualquier cambio debe gestionarse con la agencia.', 'travel-agency-platform'); ?></small>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function($) {
            var nonce = '<?php echo esc_js(wp_create_nonce('tap_booking_nonce')); ?>';
            $('.tap-cancel-booking-btn').on('click', function() {
                var $btn = $(this), id = $btn.data('booking-id');
                var code = $btn.data('confirm') || '';
                if (!confirm('<?php echo esc_js(__('¿Cancelar la reserva ', 'travel-agency-platform')); ?>' + code + '?')) return;
                $btn.prop('disabled', true).text('...');
                $.post(tap_ajax.ajax_url, { action: 'tap_cancel_booking', nonce: nonce, booking_id: id })
                    .done(function(res) {
                        if (res && res.success) { location.reload(); }
                        else { alert(res && res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>'); $btn.prop('disabled', false).text('<?php echo esc_js(__('Cancelar reserva', 'travel-agency-platform')); ?>'); }
                    })
                    .fail(function() { alert('<?php echo esc_js(__('Error', 'travel-agency-platform')); ?>'); $btn.prop('disabled', false).text('<?php echo esc_js(__('Cancelar reserva', 'travel-agency-platform')); ?>'); });
            });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    public static function favorites() {
        if (!is_user_logged_in()) {
            return '<div class="tap-box tap-box-warn"><a href="' . esc_url(wp_login_url(get_permalink())) . '">' . esc_html__('Inicia sesión para ver tus favoritos.', 'travel-agency-platform') . '</a></div>';
        }

        $ids = TAP_API::get_favorites();
        if (empty($ids)) {
            return '<p class="tap-empty-state">' . esc_html__('Aún no tienes favoritos. Toca el corazón ♥ en cualquier servicio para guardarlo aquí.', 'travel-agency-platform') . '</p>';
        }

        $posts = get_posts([
            'post_type'      => ['tap_accommodation', 'tap_tour', 'tap_transport', 'tap_car_rental', 'tap_boat', 'tap_package'],
            'post__in'       => $ids,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'post__in',
        ]);

        if (empty($posts)) {
            return '<p class="tap-empty-state">' . esc_html__('No se encontraron servicios favoritos.', 'travel-agency-platform') . '</p>';
        }

        $type_labels = [
            'tap_accommodation' => __('Alojamiento', 'travel-agency-platform'),
            'tap_tour'          => __('Tour', 'travel-agency-platform'),
            'tap_transport'     => __('Transporte', 'travel-agency-platform'),
            'tap_car_rental'    => __('Alquiler de autos', 'travel-agency-platform'),
            'tap_boat'          => __('Paseo en bote', 'travel-agency-platform'),
            'tap_package'       => __('Paquete', 'travel-agency-platform'),
        ];

        ob_start();
        ?>
        <div class="tap-fav-grid">
            <?php foreach ($posts as $post):
                $type  = $post->post_type;
                $price = floatval(get_post_meta($post->ID, TAP_API::get_price_key($type) ?: '', true));
                $rat   = TAP_API::get_rating_stats($type, $post->ID);
                $img   = get_the_post_thumbnail($post->ID, 'medium');
                ?>
                <article class="tap-service-card tap-fav-card">
                    <?php if ($img): ?>
                        <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" class="tap-fav-thumb"><?php echo $img; ?></a>
                    <?php endif; ?>
                    <div class="tap-service-card-body">
                        <span class="tap-badge tap-badge-primary"><?php echo esc_html($type_labels[$type] ?? $type); ?></span>
                        <h3><a href="<?php echo esc_url(get_permalink($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a></h3>
                        <?php if ($price): ?>
                            <p class="tap-price"><?php echo esc_html(TAP_Currency::fmt($price)); ?></p>
                        <?php endif; ?>
                        <?php if ($rat['count'] > 0): ?>
                            <p class="tap-fav-rating"><?php echo esc_html(number_format($rat['avg'], 1)); ?> ★ (<?php echo (int) $rat['count']; ?>)</p>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" class="tap-btn tap-btn-outline"><?php esc_html_e('Ver detalles', 'travel-agency-platform'); ?></a>
                        <button class="tap-btn tap-btn-xs tap-fav-btn active" data-post-id="<?php echo (int) $post->ID; ?>" type="button" title="<?php esc_attr_e('Quitar de favoritos', 'travel-agency-platform'); ?>">
                            <span class="tap-fav-heart">♥</span> <?php esc_html_e('Quitar', 'travel-agency-platform'); ?>
                        </button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
