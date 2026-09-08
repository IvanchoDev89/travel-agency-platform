<?php
/**
 * Hierarchical destinations (país → provincia → cantón → distrito → lugar).
 *
 * Fase 1: the generic `tap_location` taxonomy is adapted so terms carry a
 * `tap_dest_level` (0..4) and an optional `tap_dest_order`, and Costa Rica is
 * seeded once (guarded by the `tap_cr_seed` option) with its 7 provinces, 82
 * cantones and the main tourist places. Helpers here drive the cascade
 * selectors used by the search form, the admin metabox and the agency
 * manage-listing editor.
 */
class TAP_Destinations {

    const TAX = 'tap_location';
    const SEED_OPTION = 'tap_cr_seed';

    /**
     * Level labels. Keys 0..4 follow: país, provincia, cantón, distrito, lugar.
     *
     * @return array<int,string> translated (msgids) level names.
     */
    public static function levels() {
        return [
            0 => __('País', 'travel-agency-platform'),
            1 => __('Provincia', 'travel-agency-platform'),
            2 => __('Cantón', 'travel-agency-platform'),
            3 => __('Distrito', 'travel-agency-platform'),
            4 => __('Lugar', 'travel-agency-platform'),
        ];
    }

    public static function level_label($level) {
        $levels = self::levels();
        return isset($levels[$level]) ? $levels[$level] : '';
    }

    public static function term_level($term_id) {
        $level = get_term_meta($term_id, 'tap_dest_level', true);
        return $level !== '' ? (int) $level : null;
    }

    public static function set_level($term_id, $level, $order = 0) {
        if ($level !== '' && $level !== null) {
            update_term_meta($term_id, 'tap_dest_level', (int) $level);
        }
        if ($order) {
            update_term_meta($term_id, 'tap_dest_order', (int) $order);
        }
    }

    /**
     * Seed Costa Rica once. Idempotent: never duplicates terms.
     */
    public static function seed_cr() {
        if (get_option(self::SEED_OPTION)) {
            return;
        }

        $cr = self::cr_tree();

        // País (level 0).
        $country_id = self::insert_term('Costa Rica', 0, 0);
        if (is_wp_error($country_id)) {
            update_option(self::SEED_OPTION, 1);
            return;
        }

        $p_order = 0;
        foreach ($cr as $province => $cantones) {
            $p_order++;
            $province_id = self::insert_term($province, 1, $p_order, $country_id);
            if (is_wp_error($province_id) || $province_id === null) {
                continue;
            }
            $c_order = 0;
            foreach ($cantones as $key => $value) {
                $c_order++;
                if (is_array($value)) {
                    $canton = $key;
                    $places = $value;
                } else {
                    $canton = $value;
                    $places = [];
                }
                $canton_id = self::insert_term($canton, 2, $c_order, $province_id);
                if (is_wp_error($canton_id) || $canton_id === null) {
                    continue;
                }
                // Tourist places: level 4 (lugar under the canton/district).
                $l_order = 0;
                foreach ($places as $place) {
                    $l_order++;
                    self::insert_term($place, 4, $l_order, $canton_id);
                }
            }
        }

        update_option(self::SEED_OPTION, 1);
    }

    private static function insert_term($name, $level, $order = 0, $parent = 0) {
        $existing = term_exists($name, self::TAX, $parent);
        if ($existing && !is_wp_error($existing)) {
            $term_id = (int) (is_array($existing) ? $existing['term_id'] : $existing);
            self::set_level($term_id, $level, $order);
            return $term_id;
        }
        $res = wp_insert_term($name, self::TAX, ['parent' => (int) $parent, 'slug' => sanitize_title($name)]);
        if (is_wp_error($res)) {
            return $res;
        }
        self::set_level((int) $res['term_id'], $level, $order);
        return (int) $res['term_id'];
    }

    /**
     * Ordered children of a destination term (respecting `tap_dest_order` then name).
     *
     * @param int $parent
     * @return array<int,object>|WP_Error
     */
    public static function children($parent) {
        $terms = get_terms([
            'taxonomy'   => self::TAX,
            'parent'     => (int) $parent,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms) || empty($terms)) {
            return is_wp_error($terms) ? $terms : [];
        }
        usort($terms, function ($a, $b) {
            $ao = (int) get_term_meta($a->term_id, 'tap_dest_order', true);
            $bo = (int) get_term_meta($b->term_id, 'tap_dest_order', true);
            if ($ao !== $bo) {
                return $ao - $bo;
            }
            return strnatcasecmp($a->name, $b->name);
        });
        return $terms;
    }

    /**
     * Flattened ordered destination chain for a term (from highest ancestor down).
     *
     * @param int $term_id
     * @return array<int,object>
     */
    public static function chain($term_id) {
        $chain   = [];
        $current = intval($term_id);
        $guard   = 0;
        while ($current && $guard < 8) {
            $term = get_term($current, self::TAX);
            if (!$term || is_wp_error($term)) {
                break;
            }
            array_unshift($chain, $term);
            $current = $term->parent ? (int) $term->parent : 0;
            $guard++;
        }
        return $chain;
    }

    /**
     * Breadcrumb label for a destination term, e.g. "Costa Rica / Alajuela / San Carlos / La Fortuna".
     */
    public static function breadcrumb($term_id) {
        return implode(' / ', array_map(function ($t) {
            return $t->name;
        }, self::chain($term_id)));
    }

    /**
     * All destination terms grouped by parent (0 = roots), for the cascade JS.
     *
     * @return array<int,array<int,array{id:int,name:string,level:int}>>
     */
    public static function term_map() {
        $terms = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false]);
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }
        $map = [];
        foreach ($terms as $term) {
            $parent = (int) $term->parent;
            if (!isset($map[$parent])) {
                $map[$parent] = [];
            }
            $map[$parent][] = [
                'id'    => (int) $term->term_id,
                'name'  => $term->name,
                'level' => self::term_level($term->term_id) ?? (self::term_depth($term->term_id)),
                'order' => (int) get_term_meta($term->term_id, 'tap_dest_order', true),
            ];
        }
        foreach ($map as &$children) {
            usort($children, function ($a, $b) {
                if ($a['order'] !== $b['order']) {
                    return $a['order'] - $b['order'];
                }
                return strnatcasecmp($a['name'], $b['name']);
            });
        }
        unset($children);
        return $map;
    }

    /**
     * Depth inferred from the ancestor chain when term-meta is missing.
     */
    public static function term_depth($term_id) {
        $depth = 0;
        $parent = (int) get_term($term_id, self::TAX)->parent;
        $guard = 0;
        while ($parent) {
            $depth++;
            $term = get_term($parent, self::TAX);
            if (!$term || is_wp_error($term)) {
                break;
            }
            $parent = (int) $term->parent;
            if (++$guard > 8) {
                break;
            }
        }
        return $depth;
    }

    /**
     * Render a 5-level cascading destination picker (país → provincia → cantón → distrito → lugar).
     *
     * @param int    $selected Deepest selected term id (to restore state).
     * @param string $name     Form field name for the hidden submission value.
     */
    public static function render_picker($selected, $name = 'location') {
        $selected = intval($selected) ? intval($selected) : 0;
        $chain    = $selected ? self::chain($selected) : [];
        $levels   = self::levels();
        $map      = self::term_map();
        $uid      = 'tap-dest-' . wp_rand(1000, 9999);

        // Per-level options based on the current chain (stop at first empty level).
        $level_options = [];
        $parent = 0;
        $render_levels = [];
        foreach ($levels as $level => $label) {
            $options = isset($map[$parent]) ? $map[$parent] : [];
            $sel = $chain[$level] ?? null;
            $level_options[$level] = $options;
            $render_levels[] = $level;
            if (!$sel) {
                break;
            }
            $parent = (int) $sel->term_id;
        }

        ob_start();
        ?>
        <div class="tap-dest-picker" id="<?php echo esc_attr($uid); ?>">
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($selected); ?>">
            <?php foreach ($render_levels as $level): ?>
                <?php
                $options = $level_options[$level];
                $sel = $chain[$level] ?? null;
                $label = self::level_label($level);
                ?>
                <label class="tap-dest-level">
                    <span><?php echo esc_html($label); ?></span>
                    <select class="tap-dest-select" data-level="<?php echo esc_attr($level); ?>">
                        <option value="0"><?php echo esc_html('— ' . $label . ' —'); ?></option>
                        <?php foreach ($options as $opt): ?>
                            <?php
                            $active = ($sel && (int) $sel->term_id === (int) $opt['id']) ? ' selected' : '';
                            $selected_attr = $active ? 'selected="selected"' : '';
                            ?>
                            <option value="<?php echo esc_attr($opt['id']); ?>" <?php echo $selected_attr; ?>><?php echo esc_html($opt['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (!$sel): ?>
                    <?php break; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <script>
            (function ($) {
                var $root = $('#<?php echo esc_js($uid); ?>');
                if (!$root.length || !$root[0].dataset || !$root.data('booted')) {
                    $root.data('booted', 1);
                }
            })(jQuery);
        </script>
        <?php
        $html = ob_get_clean();

        return self::picker_script($html, $map);
    }

    /**
     * Injects the term map + cascade behaviour into the picker markup.
     */
    private static function picker_script($html, array $map) {
        $json = wp_json_encode($map);
        $script = '<script>
            (function ($) {
                var MAP = ' . $json . ';
                $(".tap-dest-picker").each(function () {
                    var $root = $(this);
                    if ($root.data("destBooted")) return;
                    $root.data("destBooted", 1);
                    var $hidden = $root.find("input[type=hidden]");
                    var $selects = $root.find("select.tap-dest-select");
                    function optionsFor(parent) {
                        return (MAP[parent] || []).slice();
                    }
                    function renderLevel($select) {
                        var level = parseInt($select.data("level"), 10);
                        // Rebuild options from the parent selected at level-1.
                        var parentId = 0;
                        if (level > 0) {
                            var $prev = $selects.filter("[data-level=" + (level - 1) + "]");
                            parentId = parseInt($prev.val() || "0", 10);
                        }
                        var keep = parseInt($select.val() || "0", 10);
                        $select.find("option").each(function () {
                            var v = parseInt(this.value, 10);
                            if (v === 0) return;
                            // Level 0 has no parent: its options are the roots, always valid.
                            if (level === 0) {
                                $(this).css("display", "");
                                return;
                            }
                            if (parentId === 0) {
                                $(this).css("display", "none");
                            } else if ((MAP[parentId] || []).some(function (o) { return o.id === v; })) {
                                $(this).css("display", "");
                            } else {
                                $(this).css("display", "none");
                            }
                        });
                        // If the current value no longer belongs to the parent, reset to 0.
                        if (level > 0 && parentId !== 0 && keep !== 0 && !(MAP[parentId] || []).some(function (o) { return o.id === keep; })) {
                            $select.val("0");
                            $select.trigger("change");
                        }
                    }
                    $selects.on("change", function () {
                        var $cur = $(this);
                        var curLevel = parseInt($cur.data("level"), 10);
                        // Reset and re-render every deeper level.
                        $selects.filter(function () {
                            return parseInt($(this).data("level"), 10) > curLevel;
                        }).each(function () {
                            var level = parseInt($(this).data("level"), 10);
                            var $sel = $(this);
                            // prune options not valid under new parent
                            renderLevel($sel);
                            var v = parseInt($sel.val() || "0", 10);
                            if ($sel.find("option:visible[value=" + v + "]").length === 0) {
                                $sel.val("0");
                            }
                        });
                        renderLevel($cur);
                        syncHidden();
                    });
                    function syncHidden() {
                        var deepest = 0;
                        $selects.each(function () {
                            var v = parseInt($(this).val() || "0", 10);
                            if (v !== 0) deepest = v;
                        });
                        $hidden.val(deepest);
                    }
                    // Initial state: show options belonging to the correct parents.
                    $selects.each(function () { renderLevel($(this)); });
                    syncHidden();
                });
            })(jQuery);
        </script>';
        return $html . $script;
    }

    /**
     * The full Costa Rican administrative tree: provincia → cantón → lugares turísticos.
     *
     * Levels: provincias = 1, cantones = 2, lugares = 4 (distritos 3 no se siembran).
     */
    public static function cr_tree() {
        return [
            'San José' => [
                'San José', 'Escazú', 'Desamparados', 'Puriscal', 'Tarrazú', 'Aserrí',
                'Mora', 'Goicoechea', 'Santa Ana', 'Alajuelita', 'Vázquez de Coronado',
                'Acosta', 'Tibás', 'Moravia', 'Montes de Oca', 'Turrubares', 'Dota',
                'Curridabat', 'Pérez Zeledón', 'León Cortés Castro',
            ],
            'Alajuela' => [
                'Alajuela', 'San Ramón', 'Grecia', 'San Mateo', 'Atenas', 'Naranjo',
                'Palmares', 'Poás', 'Orotina', 'San Carlos' => ['La Fortuna', 'Venecia', 'Boca Tapada'],
                'Zarcero', 'Sarchí', 'Upala', 'Los Chiles', 'Guatuso' => ['Río Celeste', 'Bijagua'],
            ],
            'Cartago' => [
                'Cartago', 'Paraíso', 'La Unión', 'Jiménez', 'Turrialba' => ['Guayabo'],
                'Alvarado', 'Oreamuno', 'El Guarco',
            ],
            'Heredia' => [
                'Heredia', 'Barva', 'Santo Domingo', 'Santa Bárbara', 'San Rafael',
                'San Isidro', 'Belén', 'Flores', 'San Pablo', 'Sarapiquí' => ['La Virgen'],
            ],
            'Guanacaste' => [
                'Liberia' => ['Rincón de la Vieja'],
                'Nicoya' => ['Sámara', 'Nosara'],
                'Santa Cruz' => ['Tamarindo'],
                'Bagaces' => ['Miravalles'],
                'Carrillo' => ['Playa Hermosa', 'Playa del Coco'],
                'Cañas', 'Abangares', 'Tilarán', 'Nandayure', 'La Cruz' => ['Bahía Salinas', 'Península de Santa Elena'], 'Hojancha',
            ],
            'Puntarenas' => [
                'Puntarenas' => ['Paquera', 'Montezuma'],
                'Esparza', 'Buenos Aires', 'Montes de Oro', 'Osa' => ['Puerto Jiménez', 'Drake Bay'],
                'Aguirre' => ['Quepos', 'Manuel Antonio'],
                'Golfito', 'Coto Brus', 'Parrita', 'Corredores', 'Garabito' => ['Jacó', 'Herradura'],
                'Monteverde' => ['Santa Elena', 'Monteverde Cloud Forest'],
            ],
            'Limón' => [
                'Limón', 'Pococí' => ['Tortuguero'], 'Siquirres',
                'Talamanca' => ['Puerto Viejo', 'Cahuita'], 'Matina', 'Guácimo',
            ],
        ];
    }

    /**
     * CR province names (level-1) as a convenience for tests / labels.
     */
    public static function provinces() {
        return array_keys(self::cr_tree());
    }
}
