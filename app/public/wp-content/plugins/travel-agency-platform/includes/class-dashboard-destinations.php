<?php
defined('ABSPATH') || exit;

/**
 * Admin management screen for the destination hierarchy (país → provincia →
 * cantón → distrito → lugar). Lets an admin re-parent, re-order, add and
 * correct destination terms in one place instead of through each post type's
 * separate term UI. The `tap_dest_level` is derived from the term's depth so
 * the tree stays coherent with what the cascading picker renders.
 */
class TAP_Dashboard_Destinations {

    const CAP = 'manage_options';

    private static function all_terms() {
        $terms = get_terms([
            'taxonomy'   => TAP_Destinations::TAX,
            'hide_empty' => false,
            'number'     => 0,
        ]);
        return is_wp_error($terms) ? [] : $terms;
    }

    /** Depth-first list of rows (term + indent depth) for display. */
    private static function tree_rows(array $terms) {
        $children = [];
        foreach ($terms as $t) {
            $children[(int) $t->parent][] = $t;
        }
        foreach ($children as &$list) {
            usort($list, function ($a, $b) {
                $ao = (int) get_term_meta($a->term_id, 'tap_dest_order', true);
                $bo = (int) get_term_meta($b->term_id, 'tap_dest_order', true);
                if ($ao !== $bo) {
                    return $ao - $bo;
                }
                return strnatcasecmp($a->name, $b->name);
            });
        }
        unset($list);

        $rows = [];
        $walk = function ($parent, $depth) use (&$walk, &$rows, $children) {
            foreach ($children[$parent] ?? [] as $child) {
                $rows[] = ['term' => $child, 'depth' => $depth];
                $walk((int) $child->term_id, $depth + 1);
            }
        };
        $walk(0, 0);
        return $rows;
    }

    /** Ids of a term and all of its descendants (used to block cyclic parents). */
    private static function descendants(array $terms, $term_id) {
        $kids = [];
        foreach ($terms as $t) {
            $kids[(int) $t->parent][] = (int) $t->term_id;
        }
        $out   = [(int) $term_id];
        $queue = [(int) $term_id];
        $guard = 0;
        while ($queue && ++$guard < 1000) {
            $current = array_shift($queue);
            foreach ($kids[$current] ?? [] as $kid) {
                if (!in_array($kid, $out, true)) {
                    $out[] = $kid;
                    $queue[] = $kid;
                }
            }
        }
        return $out;
    }

    public static function render_page() {
        if (!current_user_can(self::CAP)) {
            wp_die(__('No tienes permiso para ver esta pantalla.', 'travel-agency-platform'));
        }

        $terms = self::all_terms();
        $rows  = self::tree_rows($terms);
        $by_id = [];
        foreach ($terms as $t) {
            $by_id[(int) $t->term_id] = $t;
        }

        if (isset($_GET['tap_dest_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Destinos guardados.', 'travel-agency-platform') . '</p></div>';
        }
        if (isset($_GET['tap_dest_added'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Destino agregado.', 'travel-agency-platform') . '</p></div>';
        }
        if (isset($_GET['tap_dest_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('No se pudo guardar el destino.', 'travel-agency-platform') . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Destinations', 'travel-agency-platform'); ?></h1>
            <p class="description"><?php esc_html_e('Reordena, recoloca y agrega destinos. El nivel (país, provincia, cantón, distrito, lugar) se deriva de la posición en el árbol.', 'travel-agency-platform'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tap_destinations_save', 'tap_destinations_nonce'); ?>
                <input type="hidden" name="action" value="tap_destinations_save">
                <table class="widefat striped tap-dest-admin-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Destination', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Level', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Order', 'travel-agency-platform'); ?></th>
                            <th><?php esc_html_e('Parent', 'travel-agency-platform'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) : ?>
                    <?php $t = $row['term']; $tid = (int) $t->term_id; ?>
                        <?php $blocked = self::descendants($terms, $tid); ?>
                        <tr>
                            <td>
                                <?php echo esc_html(str_repeat('— ', $row['depth'])); ?>
                                <a href="<?php echo esc_url(get_edit_term_link($tid, TAP_Destinations::TAX)); ?>"><?php echo esc_html($t->name); ?></a>
                            </td>
                            <td>
                                <?php $lvl = TAP_Destinations::term_level($tid); ?>
                                <?php echo esc_html(TAP_Destinations::level_label($lvl)); ?>
                            </td>
                            <td>
                                <input type="number" name="order[<?php echo esc_attr($tid); ?>]" value="<?php echo esc_attr((int) get_term_meta($tid, 'tap_dest_order', true)); ?>" min="0" style="width:80px;">
                            </td>
                            <td>
                                <select name="parent[<?php echo esc_attr($tid); ?>]">
                                    <option value="0"><?php esc_html_e('— Root —', 'travel-agency-platform'); ?></option>
                                    <?php foreach ($by_id as $pid => $p) : ?>
                                        <?php if ($pid === $tid || in_array($pid, $blocked, true)) continue; ?>
                                        <option value="<?php echo esc_attr($pid); ?>"<?php echo((int) $t->parent === $pid) ? ' selected="selected"' : ''; ?>><?php echo esc_html($p->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Add destination', 'travel-agency-platform'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tap_destinations_add', 'tap_destinations_add_nonce'); ?>
                <input type="hidden" name="action" value="tap_destinations_add">
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="tap-dest-name"><?php esc_html_e('Name', 'travel-agency-platform'); ?></label></th>
                        <td><input name="dest_name" id="tap-dest-name" type="text" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="tap-dest-parent"><?php esc_html_e('Parent', 'travel-agency-platform'); ?></label></th>
                        <td>
                            <select name="dest_parent" id="tap-dest-parent">
                                <option value="0"><?php esc_html_e('— Root —', 'travel-agency-platform'); ?></option>
                                <?php foreach ($by_id as $pid => $p) : ?>
                                    <option value="<?php echo esc_attr($pid); ?>"><?php echo esc_html($p->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tap-dest-order"><?php esc_html_e('Order', 'travel-agency-platform'); ?></label></th>
                        <td><input name="dest_order" id="tap-dest-order" type="number" class="small-text" value="0" min="0"></td>
                    </tr>
                </table>
                <?php submit_button(__('Add destination', 'travel-agency-platform')); ?>
            </form>
        </div>
        <?php
    }

    private static function redirect($flag) {
        wp_safe_redirect(admin_url('admin.php?page=tap-destinations&' . $flag . '=1'));
        exit;
    }

    public static function handle_save() {
        if (!current_user_can(self::CAP)) {
            wp_die(__('No tienes permiso para editar destinos.', 'travel-agency-platform'));
        }
        check_admin_referer('tap_destinations_save', 'tap_destinations_nonce');

        $parents = isset($_POST['parent']) && is_array($_POST['parent']) ? wp_unslash($_POST['parent']) : [];
        $orders  = isset($_POST['order']) && is_array($_POST['order']) ? wp_unslash($_POST['order']) : [];

        $ok = true;
        foreach ($parents as $tid => $parent) {
            $tid    = (int) $tid;
            $parent = (int) $parent;
            if ($tid <= 0) {
                continue;
            }
            // Block a term becoming its own ancestor (cycle) and root/self moves.
            if ($parent === $tid) {
                $parent = 0;
            } elseif ($parent && in_array($parent, self::descendants(self::all_terms(), $tid), true)) {
                $parent = 0;
            }
            $res = wp_update_term($tid, TAP_Destinations::TAX, ['parent' => $parent]);
            if (is_wp_error($res)) {
                $ok = false;
                continue;
            }
            update_term_meta($tid, 'tap_dest_level', TAP_Destinations::term_depth($tid));
            if (isset($orders[$tid])) {
                update_term_meta($tid, 'tap_dest_order', max(0, (int) $orders[$tid]));
            }
        }

        self::redirect($ok ? 'tap_dest_saved' : 'tap_dest_error');
    }

    public static function handle_add() {
        if (!current_user_can(self::CAP)) {
            wp_die(__('No tienes permiso para agregar destinos.', 'travel-agency-platform'));
        }
        check_admin_referer('tap_destinations_add', 'tap_destinations_add_nonce');

        $name   = isset($_POST['dest_name']) ? sanitize_text_field(wp_unslash($_POST['dest_name'])) : '';
        $parent = isset($_POST['dest_parent']) ? (int) $_POST['dest_parent'] : 0;
        $order  = isset($_POST['dest_order']) ? max(0, (int) $_POST['dest_order']) : 0;

        if ('' === $name) {
            self::redirect('tap_dest_error');
        }

        $res = wp_insert_term($name, TAP_Destinations::TAX, ['parent' => $parent]);
        if (is_wp_error($res)) {
            self::redirect('tap_dest_error');
        }
        $tid = (int) $res['term_id'];
        update_term_meta($tid, 'tap_dest_level', TAP_Destinations::term_depth($tid));
        if ($order) {
            update_term_meta($tid, 'tap_dest_order', $order);
        }

        self::redirect('tap_dest_added');
    }
}
