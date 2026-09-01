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
    }

    public static function output() {
        if (is_admin() || is_feed()) {
            return;
        }

        $post = get_post();
        if ($post instanceof WP_Post && in_array($post->post_type, self::$types, true) && is_singular()) {
            self::meta_tags($post);
            self::og_tags($post);
            self::json_ld($post);
            self::breadcrumb_json_ld($post);
        } elseif (is_post_type_archive('tap_accommodation')) {
            echo '<meta property="og:type" content="website">' . "\n";
            echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
            echo '<meta property="og:title" content="' . esc_attr(wp_get_document_title()) . '">' . "\n";
            echo '<meta property="og:url" content="' . esc_url(home_url(add_query_arg([]))) . '">' . "\n";
        }
    }

    protected static function seo_title($post) {
        return get_post_meta($post->ID, '_tap_seo_title', true) ?: $post->post_title;
    }

    protected static function seo_description($post) {
        $desc = get_post_meta($post->ID, '_tap_seo_description', true);
        if ($desc) {
            return wp_trim_words(wp_strip_all_tags($desc), 40);
        }
        return wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 40);
    }

    protected static function meta_tags($post) {
        $desc = self::seo_description($post);
        if ($desc) {
            echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
        }
    }

    protected static function og_tags($post) {
        $image = get_the_post_thumbnail_url($post, 'large');
        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr(self::seo_title($post)) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr(self::seo_description($post)) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url(get_permalink($post->ID)) . '">' . "\n";
        if ($image) {
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        }
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

    protected static function breadcrumb_json_ld($post) {
        $type  = $post->post_type;
        $items = [
            ['@type' => 'ListItem', 'position' => 1, 'name' => __('Home', 'travel-agency-platform'), 'item' => home_url('/')],
        ];
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

        $graph = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
        echo '<script type="application/ld+json">' . wp_json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }
}