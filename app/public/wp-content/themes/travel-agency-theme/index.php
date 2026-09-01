<?php get_header(); ?>

<section class="tap-hero">
    <?php
    $hero_img = get_header_image();
    if ($hero_img): ?>
    <div class="tap-hero-bg"><img src="<?php echo esc_url($hero_img); ?>" alt=""></div>
    <?php endif; ?>
    <div class="tap-hero-overlay"></div>
    <div class="tap-hero-content">
        <div class="tap-container">
            <h1><?php _e('Discover Your Next Adventure', 'travel-agency-theme'); ?></h1>
            <p><?php _e('Explore curated accommodations, tours, and travel experiences from trusted local agencies.', 'travel-agency-theme'); ?></p>

            <div class="tap-search-box">
                <?php echo do_shortcode('[tap_search]'); ?>
            </div>
        </div>
    </div>
</section>

<section class="tap-section tap-section-categories">
    <div class="tap-container">
        <h2 class="tap-section-title"><?php _e('What are you looking for?', 'travel-agency-theme'); ?></h2>
        <p class="tap-section-subtitle"><?php _e('Choose from a wide range of travel services', 'travel-agency-theme'); ?></p>
        <div class="tap-categories-grid">
            <a href="<?php echo esc_url(home_url('/accommodation')); ?>" class="tap-category-card">
                <div class="tap-category-icon accommodation">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </div>
                <span class="tap-category-name"><?php _e('Hotels & Stays', 'travel-agency-theme'); ?></span>
            </a>
            <a href="<?php echo esc_url(home_url('/tour')); ?>" class="tap-category-card">
                <div class="tap-category-icon tour">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="10" r="3"/><path d="M12 2a8 8 0 0 0-8 8c0 5.4 8 12 8 12s8-6.6 8-12a8 8 0 0 0-8-8z"/></svg>
                </div>
                <span class="tap-category-name"><?php _e('Tours', 'travel-agency-theme'); ?></span>
            </a>
            <a href="<?php echo esc_url(home_url('/transport')); ?>" class="tap-category-card">
                <div class="tap-category-icon transport">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                </div>
                <span class="tap-category-name"><?php _e('Transfers', 'travel-agency-theme'); ?></span>
            </a>
            <a href="<?php echo esc_url(home_url('/car-rental')); ?>" class="tap-category-card">
                <div class="tap-category-icon car">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg>
                </div>
                <span class="tap-category-name"><?php _e('Car Rental', 'travel-agency-theme'); ?></span>
            </a>
            <a href="<?php echo esc_url(home_url('/boat')); ?>" class="tap-category-card">
                <div class="tap-category-icon boat">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2s2.5 2 5 2 2.5-2 5-2 2.5 2 5 2c1.3 0 1.9-.5 2.5-1"/><path d="M19.38 20A11.38 11.38 0 0 0 21 12l-8.19-2.3a4.5 4.5 0 0 0-5.5 3.11L6 16.5"/><path d="M6 10V2l6 2-2 6"/></svg>
                </div>
                <span class="tap-category-name"><?php _e('Boats', 'travel-agency-theme'); ?></span>
            </a>
            <a href="<?php echo esc_url(home_url('/package')); ?>" class="tap-category-card">
                <div class="tap-category-icon package">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 9.4 7.55 4.24a1 1 0 0 0-1.1 0L2 6.5l11 6 11-6-4.4-2.5"/><path d="M2 12.5v5l11 6 11-6v-5"/><path d="M13 18.5v-6"/></svg>
                </div>
                <span class="tap-category-name"><?php _e('Packages', 'travel-agency-theme'); ?></span>
            </a>
        </div>
    </div>
</section>

<section class="tap-section tap-section-featured">
    <div class="tap-container">
        <h2 class="tap-section-title"><?php _e('Featured Services', 'travel-agency-theme'); ?></h2>
        <p class="tap-section-subtitle"><?php _e('Hand-picked experiences our top agencies recommend', 'travel-agency-theme'); ?></p>
        <?php echo do_shortcode('[tap_featured limit="6"]'); ?>
    </div>
</section>

<section class="tap-section tap-section-destinations">
    <div class="tap-container">
        <h2 class="tap-section-title"><?php _e('Top Agencies', 'travel-agency-theme'); ?></h2>
        <p class="tap-section-subtitle"><?php _e('Book with confidence from our verified travel agencies', 'travel-agency-theme'); ?></p>
        <?php echo do_shortcode('[tap_agencies limit="4"]'); ?>
    </div>
</section>

<section class="tap-section-cta">
    <div class="tap-container">
        <h2><?php _e('Ready to Start Your Journey?', 'travel-agency-theme'); ?></h2>
        <p><?php _e('Join our community of travelers and discover amazing experiences curated by local experts.', 'travel-agency-theme'); ?></p>
        <div class="tap-flex tap-flex-center tap-gap-sm" style="flex-wrap: wrap;">
            <a href="<?php echo esc_url(home_url('/accommodation')); ?>" class="tap-btn tap-btn-white tap-btn-lg"><?php _e('Browse Services', 'travel-agency-theme'); ?></a>
            <?php if (!is_user_logged_in()): ?>
            <a href="<?php echo esc_url(wp_registration_url()); ?>" class="tap-btn tap-btn-lg" style="background: rgba(255,255,255,0.15); color: #fff; border-color: rgba(255,255,255,0.3);"><?php _e('Create Account', 'travel-agency-theme'); ?></a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php get_footer(); ?>
