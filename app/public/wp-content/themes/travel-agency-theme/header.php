<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="site-header" id="site-header" data-touch="">
    <div class="header-inner">
        <div class="site-branding">
            <?php if (function_exists('the_custom_logo') && has_custom_logo()): ?>
                <?php the_custom_logo(); ?>
            <?php else: ?>
                <h1 class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a></h1>
            <?php endif; ?>
        </div>

        <div class="header-center">
            <nav class="main-navigation" id="main-nav" aria-label="<?php esc_attr_e('Primary Menu', 'travel-agency-theme'); ?>">
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

                <ul class="nav-menu nav-more-menu" id="nav-more-menu" aria-label="<?php esc_attr_e('More menu', 'travel-agency-theme'); ?>" hidden>
                    <li class="menu-item menu-item-has-children nav-more-toggle-wrapper">
                        <a href="#" class="nav-link nav-more-toggle" aria-haspopup="true" aria-expanded="false" role="button">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="19" r="1.5" fill="currentColor"/></svg>
                            <span><?php esc_html_e('More', 'travel-agency-theme'); ?></span>
                        </a>
                        <ul class="sub-menu nav-more-dropdown" id="nav-more-dropdown"></ul>
                    </li>
                </ul>
            </nav>

            <div class="nav-actions">
                <button class="nav-action-btn nav-search-toggle" id="nav-search-toggle" aria-label="<?php esc_attr_e('Toggle search', 'travel-agency-theme'); ?>">
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
                        <a href="<?php echo esc_url(home_url('/dashboard')); ?>"><?php esc_html_e('Dashboard', 'travel-agency-theme'); ?></a>
                        <a href="<?php echo esc_url(home_url('/my-bookings')); ?>"><?php esc_html_e('My Bookings', 'travel-agency-theme'); ?></a>
                        <a href="<?php echo esc_url(home_url('/favorites')); ?>"><?php esc_html_e('Favorites', 'travel-agency-theme'); ?></a>
                        <a href="<?php echo esc_url(home_url('/edit-profile')); ?>"><?php esc_html_e('Edit Profile', 'travel-agency-theme'); ?></a>
                        <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>"><?php esc_html_e('Log Out', 'travel-agency-theme'); ?></a>
                    </div>
                </div>
                <?php else: ?>
                    <a href="<?php echo esc_url(wp_login_url()); ?>" class="tap-btn tap-btn-sm tap-btn-ghost"><?php esc_html_e('Log In', 'travel-agency-theme'); ?></a>
                    <a href="<?php echo esc_url(wp_registration_url()); ?>" class="tap-btn tap-btn-sm tap-btn-primary"><?php esc_html_e('Sign Up', 'travel-agency-theme'); ?></a>
                <?php endif; ?>
            </div>
        </div>

        <button class="tap-mobile-toggle" id="mobile-toggle" aria-label="<?php esc_attr_e('Menu', 'travel-agency-theme'); ?>" aria-expanded="false">
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
                <button type="submit" class="nav-search-submit" aria-label="<?php esc_attr_e('Search', 'travel-agency-theme'); ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </button>
                <input type="text" name="s" id="nav-search-input" class="nav-search-input" placeholder="<?php esc_attr_e('Search destinations, tours, accommodations...', 'travel-agency-theme'); ?>" autofocus>
                <button type="button" class="nav-search-close" id="nav-search-close" aria-label="<?php esc_attr_e('Close search', 'travel-agency-theme'); ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </form>
        </div>
    </div>
</header>

<div class="mobile-overlay" id="mobile-overlay" aria-hidden="true"></div>

<main id="main" class="site-main">
