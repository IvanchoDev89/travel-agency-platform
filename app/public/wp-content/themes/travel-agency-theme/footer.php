</main>

<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-grid">
            <div class="footer-brand">
                <h3><?php bloginfo('name'); ?></h3>
                <p><?php _e('Your trusted multi-agency travel platform. Discover, compare, and book the best travel experiences from verified local agencies.', 'travel-agency-platform'); ?></p>
                <div class="footer-social">
                    <a href="#" aria-label="Facebook">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                    </a>
                    <a href="#" aria-label="Instagram">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="5"/><circle cx="17.5" cy="6.5" r="1.5"/></svg>
                    </a>
                    <a href="#" aria-label="Twitter">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M22 4s-.7 2.1-2 3.4c1.6 10-9.4 17.3-18 11.6 2.2.1 4.4-.6 6-2C3 15.5.5 9.6 3 5c2.2 2.6 5.6 4.1 9 4-.9-4.2 4-6.6 7-3.8 1.1 0 3-1.2 3-1.2z"/></svg>
                    </a>
                </div>
            </div>
            <div>
                <h3><?php _e('Services', 'travel-agency-platform'); ?></h3>
                <ul>
                    <li><a href="<?php echo esc_url(home_url('/accommodation')); ?>"><?php _e('Accommodations', 'travel-agency-platform'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/tour')); ?>"><?php _e('Tours', 'travel-agency-platform'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/transport')); ?>"><?php _e('Transport', 'travel-agency-platform'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/car-rental')); ?>"><?php _e('Car Rental', 'travel-agency-platform'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/boat')); ?>"><?php _e('Boats', 'travel-agency-platform'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/package')); ?>"><?php _e('Packages', 'travel-agency-platform'); ?></a></li>
                </ul>
            </div>
            <div>
                <h3><?php _e('For Agencies', 'travel-agency-platform'); ?></h3>
                <ul>
                    <li><a href="<?php echo esc_url(home_url('/dashboard')); ?>"><?php _e('Agency Dashboard', 'travel-agency-platform'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/register')); ?>"><?php _e('Join as Agency', 'travel-agency-platform'); ?></a></li>
                </ul>
            </div>
            <div>
                <h3><?php _e('Support', 'travel-agency-platform'); ?></h3>
                <ul>
                    <li><a href="#"><?php _e('Help Center', 'travel-agency-platform'); ?></a></li>
                    <li><a href="#"><?php _e('Contact Us', 'travel-agency-platform'); ?></a></li>
                    <li><a href="#"><?php _e('Privacy Policy', 'travel-agency-platform'); ?></a></li>
                    <li><a href="#"><?php _e('Terms of Service', 'travel-agency-platform'); ?></a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            &copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?>. <?php _e('All rights reserved.', 'travel-agency-platform'); ?>
        </div>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
