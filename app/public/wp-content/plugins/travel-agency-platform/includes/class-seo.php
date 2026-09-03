<?php
defined('ABSPATH') || exit;

class TAP_SEO {

    protected static $types = [
        'tap_accommodation',
        'tap_tour',
        'tap_transport',
        'tap_car_rental',
        'tap_boat',
        'tap_package',
    ];

    public static function init() {
        add_action('wp_head', [__CLASS__, 'output'], 1);
        add_filter('document_title_parts', [__CLASS__, 'document_title_parts'], 10, 1);
    }

    public static function output() {
        if (is_admin() || is_feed()) {
            return;
        }

        $post = get_post();

        if ($post instanceof WP_Post && in_array($post->post_type, self::$types, true) && is_singular()) {
            self::canonical(get_permalink($post->ID));
            self::meta_tags($post);
            self::og_tags($post);
            self::twitter_tags($post);
            self::json_ld($post);
            self::breadcrumb_json_ld($post);
        } elseif ($post instanceof WP_Post && $post->post_type === 'tap_agency' && is_singular()) {
            self::canonical(get_permalink($post->ID));
            self::meta_tags($post);
            self::og_tags($post);
            self::twitter_tags($post);
            self::agency_json_ld($post);
            self::breadcrumb_json_ld($post);
        } elseif (is_post_type_archive('tap_agency')) {
            self::archive_tags(__('Agencias', 'travel-agency-platform'), __('Agencias de viaje y operadores turísticos verificados en la plataforma.', 'travel-agency-platform'), get_post_type_archive_link('tap_agency'));
        } elseif (is_post_type_archive() && is_archive()) {
            $pt = get_query_var('post_type');
            if (in_array($pt, self::$types, true)) {
                $map = self::archive_map();
                $name = isset($map[$pt]) ? $map[$pt]['name'] : ucfirst(str_replace('tap_', '', $pt));
                $desc = isset($map[$pt]) ? $map[$pt]['desc'] : '';
                self::archive_tags($name, $desc, get_post_type_archive_link($pt));
            }
        } elseif (is_tax(['tap_location', 'tap_service_cat', 'tap_property_type', 'tap_amenity', 'tap_tour_type', 'tap_vehicle_type', 'tap_boat_type'])) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                $name = $term->name;
                $desc = term_description($term->term_id) ?: sprintf(__('Listados en %s.', 'travel-agency-platform'), $term->name);
                self::archive_tags($name, wp_strip_all_tags($desc), get_term_link($term));
            }
        }
    }

    protected static function archive_map() {
        return [
            'tap_accommodation' => ['name' => __('Alojamientos', 'travel-agency-platform'), 'desc' => __('Hoteles, hostales, cabañas y más alojamientos verificados para tu viaje.', 'travel-agency-platform')],
            'tap_tour'          => ['name' => __('Tours y Excursiones', 'travel-agency-platform'), 'desc' => __('Tours y excursiones guiadas, privadas y de día completo con operadores verificados.', 'travel-agency-platform')],
            'tap_transport'     => ['name' => __('Transporte', 'travel-agency-platform'), 'desc' => __('Traslados y transporte turístico entre destinos con operadores verificados.', 'travel-agency-platform')],
            'tap_car_rental'    => ['name' => __('Alquiler de Autos', 'travel-agency-platform'), 'desc' => __('Alquiler de autos y vehículos para viajar con libertad.', 'travel-agency-platform')],
            'tap_boat'          => ['name' => __('Barcos y Paseos', 'travel-agency-platform'), 'desc' => __('Paseos en barco, lanchas y actividades acuáticas con operadores verificados.', 'travel-agency-platform')],
            'tap_package'       => ['name' => __('Paquetes Turísticos', 'travel-agency-platform'), 'desc' => __('Paquetes turísticos todo incluido y experiencias combinadas.', 'travel-agency-platform')],
        ];
    }

    protected static function archive_tags($name, $desc, $url) {
        $page = max(1, (int) get_query_var('paged'));
        $title = $name . ($page > 1 ? ' - Página ' . $page : '') . ' | ' . get_bloginfo('name');
        $desc = wp_trim_words($desc, 40);
        $desc = self::truncate($desc, 155);
        self::canonical($url);
        if ($desc) {
            echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
        }
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    }

    protected static function canonical($url) {
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
    }

    protected static function truncate($text, $length = 155) {
        $text = wp_strip_all_tags($text);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (function_exists('mb_strlen') && mb_strlen($text) <= $length) {
            return $text;
        }
        if (function_exists('mb_substr')) {
            $trunc = mb_substr($text, 0, $length - 1);
            return $trunc . '…';
        }
        return wp_trim_words($text, 30);
    }

    public static function document_title_parts($parts) {
        if (!is_admin() && (is_post_type_archive() || is_archive())) {
            $pt = get_query_var('post_type');
            if (in_array($pt, self::$types, true) && is_post_type_archive()) {
                $map  = self::archive_map();
                $name = isset($map[$pt]) ? $map[$pt]['name'] : ucfirst(str_replace('tap_', '', $pt));
                $page = max(1, (int) get_query_var('paged'));
                $parts['title'] = $name . ($page > 1 ? ' - Página ' . $page : '');
                return $parts;
            }
        }
        if (!is_admin() && is_post_type_archive('tap_agency')) {
            $page = max(1, (int) get_query_var('paged'));
            $parts['title'] = __('Agencias', 'travel-agency-platform') . ($page > 1 ? ' - Página ' . $page : '');
            return $parts;
        }
        return $parts;
    }

    protected static function seo_title($post) {
        return get_post_meta($post->ID, '_tap_seo_title', true) ?: $post->post_title;
    }

    protected static function seo_description($post) {
        $desc = get_post_meta($post->ID, '_tap_seo_description', true);
        if ($desc) {
            return self::truncate(wp_strip_all_tags($desc), 155);
        }
        $base = $post->post_excerpt ?: $post->post_content;
        if (!$base) {
            $base = $post->post_title;
        }
        return self::truncate(wp_strip_all_tags($base), 155);
    }

    protected static function meta_tags($post) {
        $desc = self::seo_description($post);
        if ($desc) {
            echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
        }
    }

    protected static function og_tags($post) {
        $image = get_the_post_thumbnail_url($post, 'large') ?: self::site_logo_url();
        echo '<meta property="og:type" content="' . ($post->post_type === 'tap_agency' ? 'profile' : 'article') . '">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr(self::seo_title($post)) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr(self::seo_description($post)) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url(get_permalink($post->ID)) . '">' . "\n";
        if ($image) {
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
            echo '<meta property="og:image:alt" content="' . esc_attr(self::seo_title($post)) . '">' . "\n";
        }
    }

    protected static function twitter_tags($post) {
        $image = get_the_post_thumbnail_url($post, 'large') ?: self::site_logo_url();
        echo '<meta name="twitter:card" content="' . ($image ? 'summary_large_image' : 'summary') . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr(self::seo_title($post)) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr(self::seo_description($post)) . '">' . "\n";
        if ($image) {
            echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
        }
    }

    protected static function site_logo_url() {
        $custom_logo = get_theme_mod('custom_logo');
        if ($custom_logo) {
            return wp_get_attachment_image_url($custom_logo, 'full') ?: '';
        }
        return get_site_icon_url(512) ?: '';
    }

    protected static function json_ld($post) {
        $type     = $post->post_type;
        $price    = floatval(get_post_meta($post->ID, TAP_API::get_price_key($type) ?: '', true));
        $rating   = TAP_API::get_rating_stats($type, $post->ID);
        $image    = get_the_post_thumbnail_url($post, 'large');
        $base     = [
            '@context'    => 'https://schema.org',
            'name'        => self::seo_title($post),
            'description' => wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 60),
            'url'         => get_permalink($post->ID),
            'image'       => $image ?: get_the_post_thumbnail_url($post, 'post-thumbnail') ?: '',
        ];

        if ($rating['count'] > 0) {
            $base['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => number_format($rating['avg'], 1),
                'bestRating'  => 5,
                'ratingCount' => (int) $rating['count'],
            ];
        }

        if ($type === 'tap_accommodation') {
            $base['@type'] = 'Hotel';
            $address = array_filter([
                'streetAddress' => get_post_meta($post->ID, '_tap_acc_address', true) ?: '',
                'addressLocality' => get_post_meta($post->ID, '_tap_acc_city', true) ?: '',
                'addressCountry' => get_post_meta($post->ID, '_tap_acc_country', true) ?: '',
            ]);
            if ($address) {
                $base['address'] = array_merge(['@type' => 'PostalAddress'], $address);
            }
            $lat = get_post_meta($post->ID, '_tap_acc_lat', true);
            $lng = get_post_meta($post->ID, '_tap_acc_lng', true);
            if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)) {
                $base['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) str_replace(',', '.', $lat), 'longitude' => (float) str_replace(',', '.', $lng)];
            }
            $agency_id = (int) get_post_meta($post->ID, '_tap_acc_agency_id', true);
            if ($agency_id) {
                $agency = get_post($agency_id);
                if ($agency) {
                    $base['provider'] = ['@type' => 'TravelAgency', 'name' => $agency->post_title, 'url' => get_permalink($agency->ID)];
                }
            }
        } elseif ($type === 'tap_tour') {
            $base['@type'] = 'TouristTrip';
            $agency_id = (int) get_post_meta($post->ID, '_tap_tour_agency_id', true);
            if ($agency_id) {
                $agency = get_post($agency_id);
                if ($agency) {
                    $base['provider'] = ['@type' => 'TravelAgency', 'name' => $agency->post_title, 'url' => get_permalink($agency->ID)];
                }
            }
        } else {
            $base['@type'] = 'Product';
        }

        if ($price > 0) {
            $base['offers'] = [
                '@type'         => 'Offer',
                'price'         => number_format($price, 2),
                'priceCurrency' => TAP_Currency::code(),
                'availability'  => 'https://schema.org/InStock',
                'url'           => get_permalink($post->ID),
            ];
        }

        echo '<script type="application/ld+json">' . wp_json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    protected static function agency_json_ld($post) {
        $email    = get_post_meta($post->ID, '_tap_agency_email', true);
        $phone    = get_post_meta($post->ID, '_tap_agency_phone', true);
        $whatsapp = get_post_meta($post->ID, '_tap_agency_whatsapp', true);
        $website  = get_post_meta($post->ID, '_tap_agency_website', true);
        $address  = get_post_meta($post->ID, '_tap_agency_address', true);
        $city     = get_post_meta($post->ID, '_tap_agency_city', true);
        $country  = get_post_meta($post->ID, '_tap_agency_country', true);
        $image    = get_the_post_thumbnail_url($post, 'large');

        $data = [
            '@context'    => 'https://schema.org',
            '@type'       => 'TravelAgency',
            'name'        => self::seo_title($post),
            'description' => wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 60),
            'url'         => get_permalink($post->ID),
            'image'       => $image ?: get_the_post_thumbnail_url($post, 'post-thumbnail') ?: '',
        ];
        if ($email)    $data['email'] = $email;
        if ($phone)    $data['telephone'] = $phone;
        if ($website)  $data['sameAs'] = $website;
        if ($address || $city || $country) {
            $data['address'] = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $address ?: '',
                'addressLocality' => $city ?: '',
                'addressCountry' => $country ?: '',
            ]);
        }

        echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    protected static function breadcrumb_json_ld($post) {
        $type  = $post->post_type;
        $items = [
            ['@type' => 'ListItem', 'position' => 1, 'name' => __('Home', 'travel-agency-platform'), 'item' => home_url('/')],
        ];

        if ($type === 'tap_agency') {
            $items[] = ['@type' => 'ListItem', 'position' => 2, 'name' => __('Agencias', 'travel-agency-platform'), 'item' => get_post_type_archive_link('tap_agency')];
            $items[] = ['@type' => 'ListItem', 'position' => 3, 'name' => self::seo_title($post), 'item' => get_permalink($post->ID)];
        } else {
            $archive_map = [
                'tap_accommodation' => 'accommodation',
                'tap_tour'          => 'tour',
                'tap_transport'     => 'transport',
                'tap_car_rental'    => 'car-rental',
                'tap_boat'          => 'boat-rental',
                'tap_package'       => 'package',
            ];
            $slug  = $archive_map[$type] ?? $type;
            $items[] = ['@type' => 'ListItem', 'position' => 2, 'name' => ucfirst(str_replace('-', ' ', $slug)), 'item' => home_url('/' . $slug . '/')];
            $items[] = ['@type' => 'ListItem', 'position' => 3, 'name' => self::seo_title($post), 'item' => get_permalink($post->ID)];
        }

        $graph = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
        echo '<script type="application/ld+json">' . wp_json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }
}