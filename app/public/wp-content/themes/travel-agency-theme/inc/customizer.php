<?php
defined('ABSPATH') || exit;

function tat_customize_register($wp_customize) {
    $wp_customize->add_panel('tap_landing', [
        'title'    => __('Landing TAP', 'travel-agency-platform'),
        'priority' => 160,
    ]);

    $wp_customize->add_section('tap_landing_hero', [
        'title'    => __('Hero de Landing', 'travel-agency-platform'),
        'panel'    => 'tap_landing',
        'priority' => 10,
    ]);

    // Eyebrow
    $wp_customize->add_setting('tap_hero_eyebrow', [
        'default'           => __('Costa Rica, de verdad', 'travel-agency-platform'),
        'sanitize_callback' => 'sanitize_text_field',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_hero_eyebrow', [
        'label'   => __('Eyebrow (texto superior)', 'travel-agency-platform'),
        'section' => 'tap_landing_hero',
        'type'    => 'text',
    ]);

    // Title
    $wp_customize->add_setting('tap_hero_title', [
        'default'           => __('Descubrí lo que tu viaje puede ser', 'travel-agency-platform'),
        'sanitize_callback' => 'sanitize_text_field',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_hero_title', [
        'label'   => __('Título del Hero', 'travel-agency-platform'),
        'section' => 'tap_landing_hero',
        'type'    => 'text',
    ]);

    // Subtitle
    $wp_customize->add_setting('tap_hero_subtitle', [
        'default'           => __('Tours, alojamientos y experiencias de agencias locales verificadas. Elegí tus intereses y armá tu propio itinerario.', 'travel-agency-platform'),
        'sanitize_callback' => 'wp_kses_post',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_hero_subtitle', [
        'label'   => __('Subtítulo del Hero', 'travel-agency-platform'),
        'section' => 'tap_landing_hero',
        'type'    => 'textarea',
        'rows'    => 3,
    ]);

    // Video URL
    $wp_customize->add_setting('tap_hero_video_url', [
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_hero_video_url', [
        'label'       => __('URL del video de fondo (MP4/WebM)', 'travel-agency-platform'),
        'description' => __('Pega la URL del video. Recomendado formato MP4 (muted/autoplay/loop). Si el grupo de videos del hero tiene elementos, se elige uno al azar en cada recarga y esta URL se ignora.', 'travel-agency-platform'),
        'section'     => 'tap_landing_hero',
        'type'        => 'url',
    ]);

    // Video Poster
    $wp_customize->add_setting('tap_hero_video_poster', [
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'tap_hero_video_poster', [
        'label'       => __('Imagen de poster/fallback del video', 'travel-agency-platform'),
        'description' => __('Se muestra mientras carga el video o si no se reproduce.', 'travel-agency-platform'),
        'section'     => 'tap_landing_hero',
    ]));

    // Overlay opacity
    $wp_customize->add_setting('tap_hero_overlay_opacity', [
        'default'           => 0.4,
        'sanitize_callback' => function($input) {
            $val = floatval($input);
            if ($val < 0) { $val = 0; }
            if ($val > 0.8) { $val = 0.8; }
            return $val;
        },
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_hero_overlay_opacity', [
        'label'       => __('Opacidad del overlay sobre video', 'travel-agency-platform'),
        'description' => __('0.0 = sin overlay, 0.8 = overlay fuerte. Para mejorar legibilidad.', 'travel-agency-platform'),
        'section'     => 'tap_landing_hero',
        'type'        => 'range',
        'input_attrs' => [
            'min'  => 0,
            'max'  => 0.8,
            'step' => 0.05,
        ],
    ]);

    // Show search
    $wp_customize->add_setting('tap_hero_show_search', [
        'default'           => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_hero_show_search', [
        'label'       => __('Mostrar buscador en el Hero', 'travel-agency-platform'),
        'description' => __('Desmarcar para ocultar el formulario de búsqueda.', 'travel-agency-platform'),
        'section'     => 'tap_landing_hero',
        'type'        => 'checkbox',
    ]);

    // ---------- Landing content & SEO ----------
    $wp_customize->add_section('tap_landing_content', [
        'title'    => __('Contenido y SEO', 'travel-agency-platform'),
        'panel'    => 'tap_landing',
        'priority' => 20,
    ]);

    $wp_customize->add_setting('tap_home_meta_desc', [
        'default'           => '',
        'sanitize_callback' => 'sanitize_textarea_field',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_home_meta_desc', [
        'label'       => __('Meta description de la portada', 'travel-agency-platform'),
        'description' => __('Máximo 155 caracteres. Es lo que Google muestra en los resultados.', 'travel-agency-platform'),
        'section'     => 'tap_landing_content',
        'type'        => 'textarea',
        'input_attrs' => ['maxlength' => 155],
    ]);

    $wp_customize->add_setting('tap_landing_show_faq', [
        'default'           => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_landing_show_faq', [
        'label'       => __('Mostrar preguntas frecuentes', 'travel-agency-platform'),
        'description' => __('Sección educativa interactiva con schema FAQPage para rich results.', 'travel-agency-platform'),
        'section'     => 'tap_landing_content',
        'type'        => 'checkbox',
    ]);

    /* ── Login (Acceso) ───────────────────────────────────────────── */

    $wp_customize->add_section('tap_login', [
        'title'       => __('Login (Acceso)', 'travel-agency-platform'),
        'description' => __('Personalización de la pantalla de acceso de WordPress.', 'travel-agency-platform'),
        'panel'       => 'tap_landing',
        'priority'    => 30,
    ]);

    $wp_customize->add_setting('tap_login_enabled', [
        'default'           => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_login_enabled', [
        'label'       => __('Usar el login con video y marca', 'travel-agency-platform'),
        'description' => __('Si lo desactivás, se ve el login estándar de WordPress.', 'travel-agency-platform'),
        'section'     => 'tap_login',
        'type'        => 'checkbox',
    ]);

    $wp_customize->add_setting('tap_login_video_url', [
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_login_video_url', [
        'label'       => __('URL del video de fondo (MP4)', 'travel-agency-platform'),
        'description' => __('Vacío = usa el mismo video del hero de la landing.', 'travel-agency-platform'),
        'section'     => 'tap_login',
        'type'        => 'url',
    ]);

    $wp_customize->add_setting('tap_login_show_video', [
        'default'           => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_login_show_video', [
        'label'       => __('Reproducir el video de fondo', 'travel-agency-platform'),
        'description' => __('Desactivado: solo se muestra el poster como imagen fija.', 'travel-agency-platform'),
        'section'     => 'tap_login',
        'type'        => 'checkbox',
    ]);

    $wp_customize->add_setting('tap_login_video_poster', [
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'tap_login_video_poster', [
        'label'       => __('Imagen de poster del login', 'travel-agency-platform'),
        'description' => __('Vacío: se reutiliza el poster del hero.', 'travel-agency-platform'),
        'section'     => 'tap_login',
    ]));

    $wp_customize->add_setting('tap_login_overlay_opacity', [
        'default'           => 0.45,
        'sanitize_callback' => function ($input) {
            $val = floatval($input);
            if ($val < 0) { $val = 0; }
            if ($val > 0.85) { $val = 0.85; }
            return $val;
        },
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_login_overlay_opacity', [
        'label'       => __('Opacidad del overlay', 'travel-agency-platform'),
        'description' => __('0.0 = sin overlay, 0.85 = overlay fuerte.', 'travel-agency-platform'),
        'section'     => 'tap_login',
        'type'        => 'range',
        'input_attrs' => ['min' => 0, 'max' => 0.85, 'step' => 0.05],
    ]);

    $wp_customize->add_setting('tap_login_brand_logo', [
        'default'           => 0,
        'sanitize_callback' => 'absint',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control(new WP_Customize_Media_Control($wp_customize, 'tap_login_brand_logo', [
        'label'       => __('Logo del login', 'travel-agency-platform'),
        'description' => __('Vacío: se usa el logo del sitio.', 'travel-agency-platform'),
        'section'     => 'tap_login',
        'mime_type'   => 'image',
    ]));

    $wp_customize->add_setting('tap_login_eyebrow', [
        'default'           => __('Costa Rica, de verdad', 'travel-agency-platform'),
        'sanitize_callback' => 'sanitize_text_field',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_login_eyebrow', [
        'label'   => __('Eyebrow (texto superior)', 'travel-agency-platform'),
        'section' => 'tap_login',
        'type'    => 'text',
    ]);

    $wp_customize->add_setting('tap_login_title', [
        'default'           => __('Bienvenido de vuelta', 'travel-agency-platform'),
        'sanitize_callback' => 'sanitize_text_field',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_login_title', [
        'label'   => __('Título del login', 'travel-agency-platform'),
        'section' => 'tap_login',
        'type'    => 'text',
    ]);

    $wp_customize->add_setting('tap_login_subtitle', [
        'default'           => __('Ingresá para gestionar tus reservas, favoritos y tu próxima aventura.', 'travel-agency-platform'),
        'sanitize_callback' => 'sanitize_text_field',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('tap_login_subtitle', [
        'label'   => __('Subtítulo del login', 'travel-agency-platform'),
        'section' => 'tap_login',
        'type'    => 'textarea',
        'rows'    => 2,
    ]);
}
add_action('customize_register', 'tat_customize_register');
