<?php
/**
 * Branded wp-login.php: a split-screen screen with a Pexels video background
 * and the site branding, aligned with the public theme tokens.
 *
 * Everything is opt-in through the Customizer ("Login (Acceso)" section) and
 * degrades to the stock WordPress login when disabled or on the interim modal.
 */
defined('ABSPATH') || exit;

/**
 * Whether the branded login should render. Disabled via the Customizer, and
 * never applied to the interim-login modal (it is a small popup).
 */
function tap_login_is_branded() {
    if (empty(get_theme_mod('tap_login_enabled', true))) {
        return false;
    }
    if (!empty($GLOBALS['interim_login'])) {
        return false;
    }
    return true;
}

/**
 * Resolve the login background. The dedicated login settings fall back to the
 * landing hero, so enabling the screen reuses the Costa Rica video out of the box.
 *
 * @return array{url:string,poster:string}
 */
function tap_login_media() {
    $url = tap_media_url((string) get_theme_mod('tap_login_video_url', ''));
    if ('' === $url) {
        $url = tap_media_url((string) get_theme_mod('tap_hero_video_url', ''));
    }
    $poster = tap_media_url((string) get_theme_mod('tap_login_video_poster', ''));
    if ('' === $poster) {
        $poster = tap_media_url((string) get_theme_mod('tap_hero_video_poster', ''));
    }
    $show_video = !empty(get_theme_mod('tap_login_show_video', true)) && '' !== $url;

    return [
        'url'    => $show_video ? $url : '',
        'poster' => $poster,
    ];
}

/** Logo for the form heading: dedicated setting, else the site logo. */
function tap_login_logo_url() {
    $id = (int) get_theme_mod('tap_login_brand_logo', 0);
    if (!$id) {
        $id = (int) get_theme_mod('custom_logo', 0);
    }
    return $id ? tap_media_url(wp_get_attachment_image_url($id, 'medium')) : '';
}

/** Enqueue the login stylesheet/script (after core's `login` styles). */
function tap_login_assets() {
    if (!tap_login_is_branded()) {
        return;
    }
    $plugin = content_url('/plugins/travel-agency-platform/');
    $theme  = get_template_directory_uri();

    wp_enqueue_style('tap-tokens', $plugin . 'assets/css/tokens.css', [], TAT_VERSION);
    wp_enqueue_style('tap-login', $theme . '/assets/css/login.css', ['login', 'tap-tokens'], TAT_VERSION);
    wp_enqueue_script('tap-login', $theme . '/assets/js/login.js', [], TAT_VERSION, true);

    $media = tap_login_media();
    wp_localize_script('tap-login', 'tapLogin', [
        'hasVideo' => '' !== $media['url'],
    ]);
}
add_action('login_enqueue_scripts', 'tap_login_assets');

/** Print the video/branding layer right after <body> (before #login). */
function tap_login_render_media() {
    if (!tap_login_is_branded()) {
        return;
    }

    $media    = tap_login_media();
    $opacity  = (float) get_theme_mod('tap_login_overlay_opacity', 0.45);
    $opacity  = max(0, min(0.85, $opacity));
    $eyebrow  = (string) get_theme_mod('tap_login_eyebrow', __('Costa Rica, de verdad', 'travel-agency-platform'));
    $title    = (string) get_theme_mod('tap_login_title', __('Bienvenido de vuelta', 'travel-agency-platform'));
    $subtitle = (string) get_theme_mod('tap_login_subtitle', __('Ingresá para gestionar tus reservas, favoritos y tu próxima aventura.', 'travel-agency-platform'));

    $style = '--tap-login-scrim-opacity:' . $opacity . ';';
    if ('' !== $media['poster']) {
        $style .= '--tap-login-poster:url(' . esc_url($media['poster']) . ');';
    }
    ?>
    <div class="tap-login-media" style="<?php echo esc_attr($style); ?>" aria-hidden="true">
        <?php if ('' !== $media['url']) : ?>
            <video class="tap-login-video" autoplay muted loop playsinline preload="metadata"
                <?php if ('' !== $media['poster']) : ?>poster="<?php echo esc_url($media['poster']); ?>"<?php endif; ?>>
                <source src="<?php echo esc_url($media['url']); ?>" type="video/mp4">
            </video>
        <?php endif; ?>
        <span class="tap-login-scrim"></span>
        <div class="tap-login-brand">
            <?php if ('' !== $eyebrow) : ?><p class="tap-login-eyebrow"><?php echo esc_html($eyebrow); ?></p><?php endif; ?>
            <?php if ('' !== $title) : ?><h2 class="tap-login-title"><?php echo esc_html($title); ?></h2><?php endif; ?>
            <?php if ('' !== $subtitle) : ?><p class="tap-login-tagline"><?php echo esc_html($subtitle); ?></p><?php endif; ?>
        </div>
    </div>
    <?php
}
add_action('login_header', 'tap_login_render_media');

/** Point the header logo to the site home instead of wordpress.org. */
add_filter('login_headerurl', function ($url) {
    return tap_login_is_branded() ? home_url('/') : $url;
});

/** Show the site logo (or name) as the header link text. */
add_filter('login_headertext', function ($text) {
    if (!tap_login_is_branded()) {
        return $text;
    }
    $logo = tap_login_logo_url();
    if ('' !== $logo) {
        return '<img class="tap-login-logo-img" src="' . esc_url($logo) . '" alt="' . esc_attr(get_bloginfo('name')) . '">';
    }
    return esc_html(get_bloginfo('name'));
});

/** Tag the body so the stylesheet can switch to the split-screen layout. */
add_filter('login_body_class', function ($classes) {
    if (!tap_login_is_branded()) {
        return $classes;
    }
    $classes[] = 'tap-login-branded';
    $media     = tap_login_media();
    if ('' !== $media['url']) {
        $classes[] = 'tap-login-has-video';
    }
    return $classes;
});
