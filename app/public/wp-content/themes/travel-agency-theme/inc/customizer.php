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

// ---------- Apariencia: colores ----------
$wp_customize->add_section('tap_colors', [
    'title'    => __('Colores', 'travel-agency-platform'),
    'panel'    => 'tap_landing',
    'priority' => 5,
]);

$color_settings = [
    'tap_color_primary'      => ['default' => '#0d9488', 'label' => __('Color primario', 'travel-agency-platform')],
    'tap_color_primary_dark' => ['default' => '#0f766e', 'label' => __('Color primario oscuro', 'travel-agency-platform')],
    'tap_color_primary_bg'   => ['default' => '#f0fdfa', 'label' => __('Fondo primario', 'travel-agency-platform')],
    'tap_color_primary_border' => ['default' => '#99f6e4', 'label' => __('Borde primario', 'travel-agency-platform')],
    'tap_color_text'         => ['default' => '#0f172a', 'label' => __('Color de texto', 'travel-agency-platform')],
    'tap_color_text_muted'   => ['default' => '#64748b', 'label' => __('Color de texto secundario', 'travel-agency-platform')],
    'tap_color_background'   => ['default' => '#ffffff', 'label' => __('Color de fondo', 'travel-agency-platform')],
    'tap_color_border'       => ['default' => '#e2e8f0', 'label' => __('Color de borde', 'travel-agency-platform')],
];
foreach ($color_settings as $key => $cfg) {
    $wp_customize->add_setting($key, [
        'default'           => $cfg['default'],
        'sanitize_callback' => 'sanitize_hex_color',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, $key, [
        'label'   => $cfg['label'],
        'section' => 'tap_colors',
    ]));
}

// ---------- Apariencia: tipografía ----------
$wp_customize->add_section('tap_typography', [
    'title'    => __('Tipografía', 'travel-agency-platform'),
    'panel'    => 'tap_landing',
    'priority' => 6,
]);

$wp_customize->add_setting('tap_font_family', [
    'default'           => 'inter',
    'sanitize_callback' => 'sanitize_text_field',
    'transport'         => 'refresh',
]);
$wp_customize->add_control('tap_font_family', [
    'label'   => __('Familia de fuente', 'travel-agency-platform'),
    'section' => 'tap_typography',
    'type'    => 'select',
    'choices' => [
        'system'  => __('Sistema (por defecto)', 'travel-agency-platform'),
        'inter'   => 'Inter',
        'roboto'  => 'Roboto',
        'lato'    => 'Lato',
        'poppins' => 'Poppins',
        'montserrat' => 'Montserrat',
    ],
]);

$wp_customize->add_setting('tap_font_size_base', [
    'default'           => 16,
    'sanitize_callback' => 'absint',
    'transport'         => 'refresh',
]);
$wp_customize->add_control('tap_font_size_base', [
    'label'       => __('Tamaño base de fuente (px)', 'travel-agency-platform'),
    'section'     => 'tap_typography',
    'type'        => 'range',
    'input_attrs' => ['min' => 14, 'max' => 20, 'step' => 1],
]);

// ---------- Apariencia: espaciado ----------
$wp_customize->add_section('tap_spacing', [
    'title'    => __('Espaciado', 'travel-agency-platform'),
    'panel'    => 'tap_landing',
    'priority' => 7,
]);

$wp_customize->add_setting('tap_container_max', [
    'default'           => 1200,
    'sanitize_callback' => 'absint',
    'transport'         => 'refresh',
]);
$wp_customize->add_control('tap_container_max', [
    'label'       => __('Ancho máximo del contenedor (px)', 'travel-agency-platform'),
    'section'     => 'tap_spacing',
    'type'        => 'range',
    'input_attrs' => ['min' => 960, 'max' => 1440, 'step' => 20],
]);

$wp_customize->add_setting('tap_section_padding', [
    'default'           => 64,
    'sanitize_callback' => 'absint',
    'transport'         => 'refresh',
]);
$wp_customize->add_control('tap_section_padding', [
    'label'       => __('Padding vertical de secciones (px)', 'travel-agency-platform'),
    'section'     => 'tap_spacing',
    'type'        => 'range',
    'input_attrs' => ['min' => 32, 'max' => 128, 'step' => 8],
]);

}
add_action('customize_register', 'tat_customize_register');
