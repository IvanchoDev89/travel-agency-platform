<?php
defined('ABSPATH') || exit;

/**
 * Shared presentation helpers for the front-end dashboards.
 *
 * Keeps pagination and empty states consistent across every agency and client
 * screen, so the same controls look and behave the same everywhere.
 */
class TAP_Dashboard_UI {

    /** Windowed pagination with first/last, ellipsis and aria-current. */
    public static function pagination($base_url, array $args, $current, $total_pages) {
        $current = max(1, (int) $current);
        $total_pages = max(1, (int) $total_pages);
        if ($total_pages <= 1) { return ''; }

        // Always show first, last, current and two neighbours; collapse the
        // middle with a single ellipsis instead of printing hundreds of links.
        $keep = [1, $total_pages, $current - 1, $current, $current + 1];
        $keep = array_values(array_unique(array_filter($keep, static fn($p) => $p >= 1 && $p <= $total_pages)));
        sort($keep);
        $pages = [];
        $prev = 0;
        foreach ($keep as $p) {
            if ($prev && $p - $prev > 1) { $pages[] = 'gap'; }
            $pages[] = $p;
            $prev = $p;
        }

        ob_start();
        ?>
        <nav class="tap-pagination" aria-label="<?php esc_attr_e('Paginación', 'travel-agency-platform'); ?>">
            <?php foreach ($pages as $p): ?>
                <?php if ('gap' === $p) : ?>
                    <span class="tap-page-gap" aria-hidden="true">…</span>
                <?php else : ?>
                    <?php
                    $url = add_query_arg(array_merge($args, ['bo_page' => (int) $p]), $base_url);
                    $is_current = ((int) $p === $current);
                    ?>
                    <a class="tap-page-link <?php echo $is_current ? 'is-current' : ''; ?>"
                        href="<?php echo esc_url($url); ?>"
                        <?php echo $is_current ? 'aria-current="page"' : ''; ?>>
                        <?php echo (int) $p; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php
        return ob_get_clean();
    }

    /** "Needs your attention" panel: every item is actionable. */
    public static function attention_panel(array $items) {
        if (!$items) { return ''; }
        ob_start();
        ?>
        <section class="tap-attention" aria-labelledby="tap-attention-title">
            <div class="tap-attention-head">
                <h3 id="tap-attention-title"><?php esc_html_e('Requiere tu atención', 'travel-agency-platform'); ?></h3>
                <span class="tap-attention-count"><?php echo (int) count($items); ?></span>
            </div>
            <ul class="tap-attention-list">
                <?php foreach ($items as $item): ?>
                    <li class="tap-attention-item tap-attention-<?php echo esc_attr($item['severity'] ?? 'info'); ?>">
                        <span class="tap-attention-dot" aria-hidden="true"></span>
                        <div class="tap-attention-text">
                            <strong><?php echo esc_html($item['title'] ?? ''); ?></strong>
                            <?php if (!empty($item['detail'])): ?>
                                <span><?php echo esc_html($item['detail']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($item['url'])): ?>
                            <a class="tap-attention-action" href="<?php echo esc_url($item['url']); ?>">
                                <?php echo esc_html($item['action'] ?? __('Ver', 'travel-agency-platform')); ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
        return ob_get_clean();
    }

    /** Notification bell + dropdown shared by agency and client dashboards. */
    public static function notification_center(array $items) {
        $count = 0;
        foreach ($items as $item) { $count += (int) ($item['count'] ?? 1); }
        ob_start();
        ?>
        <div class="tap-notif-wrap">
            <button type="button" class="tap-notif-bell" aria-label="<?php esc_attr_e('Notificaciones', 'travel-agency-platform'); ?>" aria-expanded="false" aria-haspopup="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?php if ($count > 0): ?><span class="tap-notif-badge"><?php echo (int) $count; ?></span><?php endif; ?>
            </button>
            <div class="tap-notif-dropdown" hidden>
                <?php if ($items): ?>
                    <ul class="tap-notif-list">
                        <?php foreach ($items as $item): ?>
                            <li>
                                <a href="<?php echo esc_url($item['url'] ?? '#'); ?>">
                                    <span class="tap-notif-dot" aria-hidden="true"></span>
                                    <span><?php echo esc_html($item['text'] ?? ''); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="tap-notif-empty"><?php esc_html_e('Sin notificaciones nuevas.', 'travel-agency-platform'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Consistent illustrated empty state with an optional call to action. */
    public static function empty_state($title, $message = '', $url = '', $label = '') {
        ob_start();
        ?>
        <div class="tap-empty-state">
            <span class="tap-empty-state-icon" aria-hidden="true">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/><line x1="12" y1="11" x2="12" y2="21"/></svg>
            </span>
            <h3><?php echo esc_html($title); ?></h3>
            <?php if ('' !== (string) $message): ?>
                <p><?php echo esc_html($message); ?></p>
            <?php endif; ?>
            <?php if ('' !== (string) $url && '' !== (string) $label): ?>
                <a class="tap-btn tap-btn-sm tap-btn-primary" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
