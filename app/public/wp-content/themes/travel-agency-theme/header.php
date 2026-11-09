<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <script>
    (function() {
        try {
            var t = localStorage.getItem('tap-theme');
            if (t === 'dark' || (!t && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    })();
    </script>
    <style id="tap-fonts-inline">
<?php
$fonts_css = file_get_contents(get_template_directory() . '/assets/fonts/fonts.css');
$fonts_css = str_replace('url(fraunces-', 'url(' . get_template_directory_uri() . '/assets/fonts/fraunces-', $fonts_css);
$fonts_css = str_replace('url(plusjakarta-', 'url(' . get_template_directory_uri() . '/assets/fonts/plusjakarta-', $fonts_css);
echo $fonts_css;
?>
    body { font-family: 'Plus Jakarta Sans', sans-serif !important; }
    h1, h2, h3, h4, h5, h6, .tap-landing-hero-title, .tap-landing-head h2, .tap-landing-itinerary-copy h2, .tap-landing-cta-card h2, .tap-post-header h1, .tap-post-content h2 { font-family: 'Fraunces', serif !important; }
    </style>
    <?php wp_head(); ?>
<link rel="manifest" href="<?php echo esc_url(get_template_directory_uri() . '/assets/manifest.json'); ?>">
<meta name="theme-color" content="#0f766e">
<link rel="preconnect" href="https://images.pexels.com" crossorigin>
<link rel="dns-prefetch" href="https://images.pexels.com">
<?php if (class_exists('TAP_Localization')): ?>
<link rel="alternate" hreflang="es" href="<?php echo esc_url(add_query_arg('lang', 'es', home_url($_SERVER['REQUEST_URI'] ?? '/'))); ?>">
<link rel="alternate" hreflang="en" href="<?php echo esc_url(add_query_arg('lang', 'en', home_url($_SERVER['REQUEST_URI'] ?? '/'))); ?>">
<link rel="alternate" hreflang="x-default" href="<?php echo esc_url(home_url($_SERVER['REQUEST_URI'] ?? '/')); ?>">
<?php endif; ?>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('<?php echo esc_url(get_template_directory_uri() . '/assets/sw.js?ver=2.0.24'); ?>').then(function(reg){ reg.update(); }).catch(function(){});
    });
}
var tapDeferredPrompt = null;
window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    tapDeferredPrompt = e;
    var bar = document.getElementById('tap-install-bar');
    if (bar) bar.style.display = 'flex';
});
function tapInstallApp() {
    if (!tapDeferredPrompt) return;
    tapDeferredPrompt.prompt();
    tapDeferredPrompt.userChoice.then(function() {
        tapDeferredPrompt = null;
        var bar = document.getElementById('tap-install-bar');
        if (bar) bar.style.display = 'none';
    });
}
</script>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a href="#main-content" class="tap-skip-link"><?php esc_html_e('Saltar al contenido principal', 'travel-agency-platform'); ?></a>

<header class="site-header" id="site-header" data-touch="">
<?php if (class_exists('TAP_Localization')): ?>
<div class="tap-lang-selector" style="position:absolute;right:16px;top:12px;z-index:10;">
    <?php echo TAP_Localization::switcher([]); ?>
</div>
<?php endif; ?>
    <div class="header-inner">
        <div class="site-branding">
            <?php if (function_exists('the_custom_logo') && has_custom_logo()): ?>
                <?php the_custom_logo(); ?>
            <?php else: ?>
                <h1 class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a></h1>
            <?php endif; ?>
        </div>

        <div class="header-center">
            <button class="mobile-close-btn" id="mobile-close-btn" aria-label="<?php esc_attr_e('Close menu', 'travel-agency-platform'); ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
            <nav class="main-navigation" id="main-nav" aria-label="<?php esc_attr_e('Primary Menu', 'travel-agency-platform'); ?>">
                <?php
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'container'      => false,
                    'menu_class'     => 'nav-menu',
                    'fallback_cb'    => false,
                    'depth'          => 3,
                    'walker'         => new TAT_Nav_Walker(),
                ]);
                ?>

                <ul class="nav-menu nav-more-menu" id="nav-more-menu" aria-label="<?php esc_attr_e('More menu', 'travel-agency-platform'); ?>" hidden>
                    <li class="menu-item menu-item-has-children nav-more-toggle-wrapper">
                        <a href="#" class="nav-link nav-more-toggle" aria-haspopup="true" aria-expanded="false" role="button">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="19" r="1.5" fill="currentColor"/></svg>
                            <span><?php esc_html_e('More', 'travel-agency-platform'); ?></span>
                        </a>
                        <ul class="sub-menu nav-more-dropdown" id="nav-more-dropdown"></ul>
                    </li>
                </ul>
            </nav>

            <div class="nav-actions">
                <button class="nav-action-btn nav-theme-toggle" id="nav-theme-toggle" aria-label="<?php esc_attr_e('Toggle dark mode', 'travel-agency-platform'); ?>" title="<?php esc_attr_e('Toggle dark mode', 'travel-agency-platform'); ?>">
                    <svg class="icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                    <svg class="icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                </button>
                <button class="nav-action-btn nav-search-toggle" id="nav-search-toggle" aria-label="<?php esc_attr_e('Toggle search', 'travel-agency-platform'); ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </button>
                <?php if (is_user_logged_in()):
                    $current_user = wp_get_current_user();
                ?>
                <div class="nav-user-dropdown-wrapper">
                    <button class="nav-action-btn nav-user-btn" id="nav-user-btn" aria-haspopup="true" aria-expanded="false">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span class="nav-user-name"><?php echo esc_html($current_user->display_name); ?></span>
                    </button>
                    <div class="nav-user-dropdown" id="nav-user-dropdown">
                        <a href="<?php echo esc_url(home_url('/dashboard')); ?>"><?php esc_html_e('Dashboard', 'travel-agency-platform'); ?></a>
                        <a href="<?php echo esc_url(home_url('/my-bookings')); ?>"><?php esc_html_e('My Bookings', 'travel-agency-platform'); ?></a>
                        <a href="<?php echo esc_url(home_url('/favorites')); ?>"><?php esc_html_e('Favorites', 'travel-agency-platform'); ?></a>
                        <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>"><?php esc_html_e('Log Out', 'travel-agency-platform'); ?></a>
                    </div>
                </div>
                <?php else: ?>
                    <a href="<?php echo esc_url(wp_login_url()); ?>" class="tap-btn tap-btn-sm tap-btn-ghost"><?php esc_html_e('Log In', 'travel-agency-platform'); ?></a>
                    <a href="<?php echo esc_url(home_url('/register/')); ?>" class="tap-btn tap-btn-sm tap-btn-primary"><?php esc_html_e('Sign Up', 'travel-agency-platform'); ?></a>
                <?php endif; ?>
            </div>
        </div>

        <button class="tap-mobile-toggle" id="mobile-toggle" aria-label="<?php esc_attr_e('Menu', 'travel-agency-platform'); ?>" aria-expanded="false" aria-controls="main-nav">
            <span class="hamburger-lines">
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
            </span>
        </button>
    </div>

    <div class="nav-search-overlay" id="nav-search-overlay">
        <div class="nav-search-overlay-inner">
            <form method="get" action="<?php echo esc_url(home_url('/')); ?>" class="nav-search-form" autocomplete="off" role="search">
                <button type="submit" class="nav-search-submit" aria-label="<?php esc_attr_e('Search', 'travel-agency-platform'); ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </button>
                <input type="text" name="s" id="nav-search-input" class="nav-search-input" placeholder="<?php esc_attr_e('Search destinations, tours, accommodations...', 'travel-agency-platform'); ?>" autofocus>
                <button type="button" class="nav-search-close" id="nav-search-close" aria-label="<?php esc_attr_e('Close search', 'travel-agency-platform'); ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </form>
        </div>
    </div>
</header>

<div class="mobile-overlay" id="mobile-overlay" aria-hidden="true"></div>

<main id="main" class="site-main">
