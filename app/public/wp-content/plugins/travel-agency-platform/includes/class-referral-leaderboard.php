<?php
defined('ABSPATH') || exit;

/**
 * Public referral leaderboard (gamification).
 */
class TAP_Referral_Leaderboard {

    public static function init() {
        add_shortcode('tap_referral_leaderboard', [__CLASS__, 'shortcode']);
    }

    public static function top($limit = 10) {
        global $wpdb;
        $t = TAP_Customer_Referrals::table();
        $sql = $wpdb->prepare(
            "SELECT referrer_user_id,
                    COUNT(*) AS referrals,
                    SUM(CASE WHEN status = 'rewarded' THEN 1 ELSE 0 END) AS rewarded,
                    COALESCE(SUM(points_awarded),0) AS points
             FROM {$t}
             GROUP BY referrer_user_id
             ORDER BY points DESC, rewarded DESC
             LIMIT %d",
            (int) $limit
        );
        return $wpdb->get_results($sql) ?: [];
    }

    public static function shortcode($atts) {
        $rows = self::top(10);
        ob_start();
        ?>
        <div class="tap-referral-leaderboard">
            <h2><?php esc_html_e('Top referidores', 'travel-agency-platform'); ?></h2>
            <p><?php esc_html_e('Los viajeros que más han compartido y ganado puntos.', 'travel-agency-platform'); ?></p>
            <?php if (!$rows): ?>
                <p class="tap-empty"><?php esc_html_e('Aún no hay referidores. ¡Sé el primero!', 'travel-agency-platform'); ?></p>
            <?php else: ?>
                <ol class="tap-leaderboard-list">
                    <?php foreach ($rows as $i => $r): ?>
                        <?php $u = get_userdata((int) $r->referrer_user_id); ?>
                        <li class="tap-leaderboard-item tap-leaderboard-rank-<?php echo (int) ($i + 1); ?>">
                            <span class="tap-leaderboard-rank"><?php echo (int) ($i + 1); ?></span>
                            <span class="tap-leaderboard-name"><?php echo $u ? esc_html($u->display_name) : esc_html__('Viajero', 'travel-agency-platform'); ?></span>
                            <span class="tap-leaderboard-points"><?php echo (int) $r->points; ?> pts</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}