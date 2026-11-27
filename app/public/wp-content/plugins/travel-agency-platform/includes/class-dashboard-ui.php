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
