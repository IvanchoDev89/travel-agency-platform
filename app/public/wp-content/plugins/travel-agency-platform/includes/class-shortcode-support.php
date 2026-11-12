<?php
defined('ABSPATH') || exit;

/**
 * Shortcode: [tap_support_center]
 *
 * Renders a professional support/knowledge-base hub page with:
 * - Search bar (AJAX instant)
 * - Category cards
 * - Featured articles
 * - FAQ accordion
 * - Contact form link
 */
class TAP_Shortcode_Support {

    public static function init() {
        add_shortcode('tap_support_center', [__CLASS__, 'render_center']);
        add_shortcode('tap_help_article', [__CLASS__, 'render_article']);
        add_action('wp_ajax_tap_search_help', [__CLASS__, 'ajax_search']);
        add_action('wp_ajax_nopriv_tap_search_help', [__CLASS__, 'ajax_search']);
    }

    /**
     * Render the support center hub.
     */
    public static function render_center($atts = []) {
        $categories = get_terms([
            'taxonomy'   => 'tap_help_category',
            'hide_empty' => false,
            'orderby'    => 'term_order',
            'order'      => 'ASC',
        ]);

        // Hidden categories for non-admins.
        $hidden_cats = current_user_can('manage_options') ? [] : ['primeros-pasos', 'administradores', 'desarrolladores'];

        // Featured articles.
        $featured = get_posts([
            'post_type'      => 'tap_help',
            'posts_per_page' => 6,
            'meta_key'       => '_tap_help_featured',
            'meta_value'     => '1',
            'post_status'    => 'publish',
        ]);
        // Filter out hidden categories.
        if (!empty($hidden_cats)) {
            $featured = array_filter($featured, function ($f) use ($hidden_cats) {
                $cats = wp_get_post_terms($f->ID, 'tap_help_category', ['fields' => 'slugs']);
                foreach ($cats as $c) {
                    if (in_array($c, $hidden_cats, true)) return false;
                }
                return true;
            });
        }

        // All articles grouped by category.
        $articles_by_cat = [];
        $all_articles = get_posts([
            'post_type'      => 'tap_help',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ]);
        foreach ($all_articles as $a) {
            $cats = wp_get_post_terms($a->ID, 'tap_help_category', ['fields' => 'slugs']);
            foreach ($cats as $cat_slug) {
                $articles_by_cat[$cat_slug][] = $a;
            }
            if (empty($cats)) {
                $articles_by_cat['uncategorized'][] = $a;
            }
        }

        // Category icons and descriptions.
        $cat_meta = [
            'primeros-pasos'    => ['icon' => '🚀', 'desc' => __('Instalación, configuración y primeros pasos.', 'travel-agency-platform')],
            'viajeros'          => ['icon' => '🧳', 'desc' => __('Búsqueda, reserva, pago, cancelación y privacidad.', 'travel-agency-platform')],
            'agencias'          => ['icon' => '🏢', 'desc' => __('Registro, publicación, gestión, comisiones y planes.', 'travel-agency-platform')],
            'administradores'   => ['icon' => '⚙️', 'desc' => __('Back office, settings, moderación y analytics.', 'travel-agency-platform')],
            'desarrolladores'   => ['icon' => '💻', 'desc' => __('Arquitectura, API, hooks, shortcodes y contribución.', 'travel-agency-platform')],
            'faq'               => ['icon' => '❓', 'desc' => __('Preguntas frecuentes.', 'travel-agency-platform')],
        ];

        // Hide admin/developer/first-steps categories from non-admins.
        $is_admin = current_user_can('manage_options');
        $hidden_cats = ['primeros-pasos', 'administradores', 'desarrolladores'];
        if (!$is_admin) {
            foreach ($hidden_cats as $hc) {
                unset($cat_meta[$hc]);
            }
        }

        ob_start();
        ?>
        <div class="tap-support-center">
            <!-- Hero -->
            <section class="tap-support-hero">
                <h1><?php esc_html_e('Centro de Soporte', 'travel-agency-platform'); ?></h1>
                <p><?php esc_html_e('Encuentra respuestas, guías y documentación para viajeros, agencias y desarrolladores.', 'travel-agency-platform'); ?></p>
                <form class="tap-support-search" role="search" method="get" action="<?php echo esc_url(home_url('/soporte/')); ?>">
                    <div class="tap-support-search-wrap">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="search" name="s" id="tap-support-search-input" placeholder="<?php esc_attr_e('Buscar en la documentación...', 'travel-agency-platform'); ?>" aria-label="<?php esc_attr_e('Buscar', 'travel-agency-platform'); ?>" autocomplete="off">
                        <div id="tap-support-search-results" class="tap-support-search-dropdown" aria-live="polite"></div>
                    </div>
                </form>
            </section>

            <!-- Categories -->
            <section class="tap-support-categories">
                <h2><?php esc_html_e('Categorías', 'travel-agency-platform'); ?></h2>
                <div class="tap-support-cat-grid">
                    <?php foreach ($categories as $cat): ?>
                        <?php
                        $slug = $cat->slug;
                        if (!isset($cat_meta[$slug])) continue; // Skip hidden categories.
                        $meta = $cat_meta[$slug];
                        $count = isset($articles_by_cat[$slug]) ? count($articles_by_cat[$slug]) : 0;
                        ?>
                        <a href="<?php echo esc_url(get_term_link($cat)); ?>" class="tap-support-cat-card">
                            <span class="tap-support-cat-icon" aria-hidden="true"><?php echo esc_html($meta['icon']); ?></span>
                            <h3 class="tap-support-cat-title"><?php echo esc_html($cat->name); ?></h3>
                            <p class="tap-support-cat-desc"><?php echo esc_html($meta['desc']); ?></p>
                            <span class="tap-support-cat-count"><?php echo (int) $count; ?> <?php esc_html_e('artículos', 'travel-agency-platform'); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Featured articles -->
            <?php if ($featured): ?>
            <section class="tap-support-featured">
                <h2><?php esc_html_e('Artículos destacados', 'travel-agency-platform'); ?></h2>
                <ul class="tap-support-featured-list">
                    <?php foreach ($featured as $f): ?>
                        <li><a href="<?php echo esc_url(get_permalink($f)); ?>"><?php echo esc_html($f->post_title); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <!-- FAQ -->
            <section class="tap-support-faq">
                <h2><?php esc_html_e('Preguntas frecuentes', 'travel-agency-platform'); ?></h2>
                <div class="tap-support-faq-list">
                    <?php
                    $faqs = isset($articles_by_cat['faq']) ? $articles_by_cat['faq'] : [];
                    foreach ($faqs as $faq):
                    ?>
                    <details class="tap-support-faq-item">
                        <summary class="tap-support-faq-q"><?php echo esc_html($faq->post_title); ?></summary>
                        <div class="tap-support-faq-a"><?php echo apply_filters('the_content', $faq->post_content); ?></div>
                    </details>
                    <?php endforeach; ?>
                    <?php if (empty($faqs)): ?>
                        <p class="tap-empty"><?php esc_html_e('No hay preguntas frecuentes aún.', 'travel-agency-platform'); ?></p>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Contact -->
            <section class="tap-support-contact">
                <h2><?php esc_html_e('¿No encuentras lo que buscas?', 'travel-agency-platform'); ?></h2>
                <p><?php esc_html_e('Contáctanos y te ayudamos.', 'travel-agency-platform'); ?></p>
                <a href="mailto:soporte@travelagencypatform.com" class="tap-btn tap-btn-primary"><?php esc_html_e('Contactar soporte', 'travel-agency-platform'); ?></a>
            </section>
        </div>

        <script>
        (function() {
            var searchInput = document.getElementById('tap-support-search-input');
            var resultsBox = document.getElementById('tap-support-search-results');
            if (!searchInput || !resultsBox) return;

            var timer = null;
            searchInput.addEventListener('input', function() {
                clearTimeout(timer);
                var q = searchInput.value.trim();
                if (q.length < 3) {
                    resultsBox.innerHTML = '';
                    resultsBox.style.display = 'none';
                    return;
                }
                timer = setTimeout(function() {
                    var fd = new FormData();
                    fd.append('action', 'tap_search_help');
                    fd.append('q', q);
                    fd.append('nonce', (window.tap_ajax || {}).nonce || '');
                    fetch((window.tap_ajax || {}).ajax_url || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res.success && res.data.html) {
                                resultsBox.innerHTML = res.data.html;
                                resultsBox.style.display = 'block';
                            } else {
                                resultsBox.innerHTML = '<p class="tap-support-search-empty"><?php echo esc_js(__('No se encontraron resultados.', 'travel-agency-platform')); ?></p>';
                                resultsBox.style.display = 'block';
                            }
                        })
                        .catch(function() {
                            resultsBox.style.display = 'none';
                        });
                }, 300);
            });

            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !resultsBox.contains(e.target)) {
                    resultsBox.style.display = 'none';
                }
            });
        })();
        </script>

        <style>
        .tap-support-center { max-width: 960px; margin: 0 auto; }
        .tap-support-hero { text-align: center; padding: 48px 20px 32px; background: linear-gradient(135deg, var(--tap-primary-bg, #eff6ff), var(--tap-surface, #fff)); border-radius: var(--tap-radius-xl, 12px); margin-bottom: 32px; }
        .tap-support-hero h1 { margin: 0 0 8px; font-size: 2rem; color: var(--tap-neutral-900, #111827); }
        .tap-support-hero p { margin: 0 0 24px; color: var(--tap-neutral-600, #4b5563); font-size: 1rem; }
        .tap-support-search-wrap { position: relative; max-width: 560px; margin: 0 auto; display: flex; align-items: center; gap: 8px; background: var(--tap-surface, #fff); border: 2px solid var(--tap-border, #e2e8f0); border-radius: var(--tap-radius-xl, 12px); padding: 4px 12px; transition: border-color .2s; }
        .tap-support-search-wrap:focus-within { border-color: var(--tap-primary, #2563eb); }
        .tap-support-search-wrap svg { flex-shrink: 0; color: var(--tap-neutral-400, #9ca3af); }
        .tap-support-search-wrap input { flex: 1; border: 0; background: transparent; padding: 10px 0; font-size: 1rem; color: var(--tap-neutral-800, #1f2937); outline: none; }
        .tap-support-search-dropdown { position: absolute; top: 100%; left: 0; right: 0; margin-top: 8px; background: var(--tap-surface, #fff); border: 1px solid var(--tap-border, #e2e8f0); border-radius: var(--tap-radius-lg, 8px); box-shadow: 0 8px 24px rgba(0,0,0,.12); max-height: 400px; overflow-y: auto; z-index: 100; display: none; }
        .tap-support-search-dropdown a { display: block; padding: 12px 16px; text-decoration: none; color: var(--tap-neutral-800, #1f2937); border-bottom: 1px solid var(--tap-neutral-100, #f3f4f6); transition: background .15s; }
        .tap-support-search-dropdown a:hover { background: var(--tap-primary-bg, #eff6ff); }
        .tap-support-search-dropdown a:last-child { border-bottom: 0; }
        .tap-support-search-result-title { font-weight: 600; font-size: 14px; }
        .tap-support-search-result-cat { font-size: 12px; color: var(--tap-neutral-500, #6b7280); margin-top: 2px; }
        .tap-support-search-empty { padding: 16px; text-align: center; color: var(--tap-neutral-500, #6b7280); }
        .tap-support-categories { margin-bottom: 32px; }
        .tap-support-categories h2 { margin: 0 0 16px; font-size: 1.25rem; color: var(--tap-neutral-800, #1f2937); }
        .tap-support-cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
        .tap-support-cat-card { display: block; padding: 20px; background: var(--tap-surface, #fff); border: 1px solid var(--tap-border, #e5e7eb); border-radius: var(--tap-radius-lg, 8px); text-decoration: none; color: inherit; transition: transform .2s, box-shadow .2s; }
        .tap-support-cat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.08); }
        .tap-support-cat-icon { font-size: 2rem; display: block; margin-bottom: 8px; }
        .tap-support-cat-title { margin: 0 0 4px; font-size: 1rem; color: var(--tap-neutral-800, #1f2937); }
        .tap-support-cat-desc { margin: 0 0 8px; font-size: 13px; color: var(--tap-neutral-500, #6b7280); line-height: 1.4; }
        .tap-support-cat-count { font-size: 12px; color: var(--tap-primary, #2563eb); font-weight: 600; }
        .tap-support-featured { margin-bottom: 32px; }
        .tap-support-featured h2 { margin: 0 0 12px; font-size: 1.25rem; color: var(--tap-neutral-800, #1f2937); }
        .tap-support-featured-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
        .tap-support-featured-list li a { display: block; padding: 10px 14px; background: var(--tap-surface, #fff); border: 1px solid var(--tap-border, #e5e7eb); border-radius: var(--tap-radius-md, 6px); text-decoration: none; color: var(--tap-neutral-800, #1f2937); font-size: 14px; transition: background .15s, border-color .15s; }
        .tap-support-featured-list li a:hover { background: var(--tap-primary-bg, #eff6ff); border-color: var(--tap-primary, #2563eb); }
        .tap-support-faq { margin-bottom: 32px; }
        .tap-support-faq h2 { margin: 0 0 12px; font-size: 1.25rem; color: var(--tap-neutral-800, #1f2937); }
        .tap-support-faq-item { border: 1px solid var(--tap-border, #e5e7eb); border-radius: var(--tap-radius-md, 6px); margin-bottom: 8px; overflow: hidden; background: var(--tap-surface, #fff); }
        .tap-support-faq-q { padding: 14px 16px; cursor: pointer; font-weight: 600; font-size: 14px; color: var(--tap-neutral-800, #1f2937); list-style: none; display: flex; justify-content: space-between; align-items: center; }
        .tap-support-faq-q::-webkit-details-marker { display: none; }
        .tap-support-faq-q::after { content: '+'; font-size: 1.2rem; color: var(--tap-primary, #2563eb); transition: transform .2s; }
        .tap-support-faq-item[open] .tap-support-faq-q::after { content: '\2212'; }
        .tap-support-faq-a { padding: 0 16px 16px; font-size: 14px; color: var(--tap-neutral-700, #374151); line-height: 1.6; }
        .tap-support-faq-a p { margin: 0 0 8px; }
        .tap-support-contact { text-align: center; padding: 32px 20px; background: var(--tap-surface-muted, #f9fafb); border-radius: var(--tap-radius-xl, 12px); }
        .tap-support-contact h2 { margin: 0 0 8px; font-size: 1.25rem; color: var(--tap-neutral-800, #1f2937); }
        .tap-support-contact p { margin: 0 0 16px; color: var(--tap-neutral-500, #6b7280); }
        @media (max-width: 768px) {
            .tap-support-hero { padding: 32px 16px 24px; }
            .tap-support-hero h1 { font-size: 1.5rem; }
            .tap-support-cat-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .tap-support-cat-card { padding: 16px; }
            .tap-support-cat-icon { font-size: 1.5rem; }
        }
        @media (max-width: 480px) {
            .tap-support-cat-grid { grid-template-columns: 1fr; }
        }
        /* Dark mode */
        html.dark .tap-support-hero { background: linear-gradient(135deg, var(--tap-primary-bg, #1e3a5f), var(--tap-surface, #1e293b)); }
        html.dark .tap-support-hero h1 { color: var(--tap-neutral-900, #f1f5f9); }
        html.dark .tap-support-hero p { color: var(--tap-neutral-600, #94a3b8); }
        html.dark .tap-support-search-wrap { background: var(--tap-surface, #1e293b); border-color: var(--tap-border, #334155); }
        html.dark .tap-support-search-wrap input { color: var(--tap-neutral-800, #f1f5f9); }
        html.dark .tap-support-search-dropdown { background: var(--tap-surface, #1e293b); border-color: var(--tap-border, #334155); }
        html.dark .tap-support-search-dropdown a { color: var(--tap-neutral-800, #f1f5f9); border-color: var(--tap-border, #334155); }
        html.dark .tap-support-search-dropdown a:hover { background: var(--tap-primary-bg, #1e3a5f); }
        html.dark .tap-support-cat-card { background: var(--tap-surface, #1e293b); border-color: var(--tap-border, #334155); }
        html.dark .tap-support-cat-title { color: var(--tap-neutral-900, #f1f5f9); }
        html.dark .tap-support-cat-desc { color: var(--tap-neutral-600, #94a3b8); }
        html.dark .tap-support-featured-list li a { background: var(--tap-surface, #1e293b); border-color: var(--tap-border, #334155); color: var(--tap-neutral-800, #f1f5f9); }
        html.dark .tap-support-featured-list li a:hover { background: var(--tap-primary-bg, #1e3a5f); border-color: var(--tap-primary, #0d9488); }
        html.dark .tap-support-faq-item { background: var(--tap-surface, #1e293b); border-color: var(--tap-border, #334155); }
        html.dark .tap-support-faq-q { color: var(--tap-neutral-900, #f1f5f9); }
        html.dark .tap-support-faq-a { color: var(--tap-neutral-700, #cbd5e1); }
        html.dark .tap-support-contact { background: var(--tap-surface-2, #1a2540); }
        html.dark .tap-support-contact h2 { color: var(--tap-neutral-900, #f1f5f9); }
        html.dark .tap-support-contact p { color: var(--tap-neutral-600, #94a3b8); }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX search for help articles.
     */
    public static function ajax_search() {
        check_ajax_referer('tap_nonce', 'nonce');

        $q = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
        if (strlen($q) < 3) {
            wp_send_json_error(['message' => __('Query too short', 'travel-agency-platform')]);
        }

        $posts = get_posts([
            'post_type'      => 'tap_help',
            's'              => $q,
            'posts_per_page' => 10,
            'post_status'    => 'publish',
        ]);

        // Filter out hidden categories for non-admins.
        if (!current_user_can('manage_options')) {
            $hidden_cats = ['primeros-pasos', 'administradores', 'desarrolladores'];
            $posts = array_filter($posts, function ($p) use ($hidden_cats) {
                $cats = wp_get_post_terms($p->ID, 'tap_help_category', ['fields' => 'slugs']);
                foreach ($cats as $c) {
                    if (in_array($c, $hidden_cats, true)) return false;
                }
                return true;
            });
        }

        if (empty($posts)) {
            wp_send_json_success(['html' => '<p class="tap-support-search-empty">' . esc_html__('No se encontraron resultados.', 'travel-agency-platform') . '</p>']);
        }

        $html = '';
        foreach ($posts as $p) {
            $cats = wp_get_post_terms($p->ID, 'tap_help_category', ['fields' => 'names']);
            $cat_name = !empty($cats) ? $cats[0] : '';
            $html .= '<a href="' . esc_url(get_permalink($p)) . '">';
            $html .= '<div class="tap-support-search-result-title">' . esc_html($p->post_title) . '</div>';
            if ($cat_name) {
                $html .= '<div class="tap-support-search-result-cat">' . esc_html($cat_name) . '</div>';
            }
            $html .= '</a>';
        }

        wp_send_json_success(['html' => $html]);
    }
}

