<?php
defined('ABSPATH') || exit;

/**
 * Fase 4 — TAP_Moderation
 *
 * Deterministic content-moderation engine for visitor-generated content
 * (reviews and agency leads). Scales each item none|review|block without
 * external services; result is persisted in `mod_status`/`mod_reason`
 * columns on tap_reviews and tap_leads and surfaced in a moderation queue.
 */

class TAP_Moderation {

    const OK     = 'ok';
    const REVIEW = 'review';
    const BLOCK  = 'block';

    /**
     * Assess free text.
     *
     * @param string $text
     * @param string $kind  'review' | 'lead'
     * @return array{status:string,reason:?string}
     */
    public static function assess($text, $kind = 'review') {
        $t = mb_strtolower(trim((string) $text), 'UTF-8');
        if ('' === $t) {
            return ['status' => self::OK, 'reason' => null];
        }
        $t = self::strip_accents($t);

        if (self::matches($t, self::abuse_words())) {
            return ['status' => self::BLOCK, 'reason' => 'abuse'];
        }

        $urls = preg_match_all('/https?:\/\/\S+|www\.[a-z0-9\-.]+\.[a-z]{2,}/i', $t);
        $pii_email = (bool) preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $t);
        $pii_phone = (bool) preg_match('/(?:\+?\d[\d\s().-]{7,}\d)/', $t);
        $spam = self::matches($t, self::spam_words());

        if ('review' === $kind) {
            if ($pii_email || $pii_phone) {
                return ['status' => self::BLOCK, 'reason' => 'pii'];
            }
            if ($spam) {
                return ['status' => self::BLOCK, 'reason' => 'spam'];
            }
            if ($urls >= 3) {
                return ['status' => self::BLOCK, 'reason' => 'spam'];
            }
            if ($urls >= 1) {
                return ['status' => self::REVIEW, 'reason' => 'links'];
            }
        } else {
            if ($spam) {
                return ['status' => self::BLOCK, 'reason' => 'spam'];
            }
            if ($urls >= 3) {
                return ['status' => self::BLOCK, 'reason' => 'spam'];
            }
            if ($urls >= 1 || $pii_email || $pii_phone) {
                return ['status' => self::REVIEW, 'reason' => 'links'];
            }
        }

        if (self::is_gibberish($t)) {
            return ['status' => self::REVIEW, 'reason' => 'gibberish'];
        }

        return ['status' => self::OK, 'reason' => null];
    }

    /** Rows awaiting attention (reviews + leads). */
    public static function queue($limit = 100) {
        global $wpdb;
        $p  = $wpdb->prefix;
        $rv = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, 'review' AS kind, r.created_at, r.rating, r.content, r.mod_status, r.mod_reason, u.display_name AS owner
             FROM {$p}tap_reviews r JOIN {$wpdb->users} u ON r.user_id = u.ID
             WHERE r.mod_status <> %s ORDER BY r.id DESC LIMIT %d",
            self::OK, $limit
        )) ?: [];
        $ld = $wpdb->get_results($wpdb->prepare(
            "SELECT id, 'lead' AS kind, created_at, 0 AS rating, message AS content, mod_status, mod_reason, name AS owner
             FROM {$p}tap_leads WHERE mod_status <> %s ORDER BY id DESC LIMIT %d",
            self::OK, $limit
        )) ?: [];
        return array_merge($rv, $ld);
    }

    public static function reason_label($reason) {
        $map = [
            'abuse'     => __('Abusive language', 'travel-agency-platform'),
            'pii'       => __('Personal data', 'travel-agency-platform'),
            'spam'      => __('Spam', 'travel-agency-platform'),
            'links'     => __('Contained links', 'travel-agency-platform'),
            'gibberish' => __('Unreadable text', 'travel-agency-platform'),
            'manual'    => __('Manually blocked', 'travel-agency-platform'),
        ];
        return $map[$reason] ?? __('—', 'travel-agency-platform');
    }

    public static function reason_for($kind, $key) {
        return [
            'kind'  => $kind,
            'id'    => (int) $key,
            'table' => ('review' === $kind) ? self::table_for($kind) : self::table_for($kind),
            'label' => self::reason_label(self::row_reason($kind, $key)),
        ];
    }

    private static function table_for($kind) {
        global $wpdb;
        return ('review' === $kind) ? $wpdb->prefix . 'tap_reviews' : $wpdb->prefix . 'tap_leads';
    }

    private static function row_reason($kind, $key) {
        global $wpdb;
        $table = self::table_for($kind);
        return (string) $wpdb->get_var($wpdb->prepare("SELECT mod_reason FROM {$table} WHERE id = %d", (int) $key));
    }

    /** Action handler for the moderation queue (admin). */
    public static function handle_admin_actions() {
        global $wpdb;
        if (empty($_GET['page']) || 'tap-moderation' !== sanitize_key($_GET['page'])) {
            return;
        }
        $action = sanitize_key($_GET['mod_action'] ?? '');
        $kind   = ('review' === sanitize_key($_GET['kind'] ?? '')) ? 'review' : 'lead';
        $id     = intval($_GET['item_id'] ?? 0);
        $nonce  = $_GET['_wpnonce'] ?? '';

        if (!$action || !$id || !wp_verify_nonce((string) $nonce, "tap_mod_{$action}_{$kind}_{$id}")) {
            return;
        }
        $table = ('review' === $kind) ? $wpdb->prefix . 'tap_reviews' : $wpdb->prefix . 'tap_leads';

        if ('approve' === $action) {
            if ('review' === $kind) {
                $wpdb->update($table, ['is_approved' => 1, 'mod_status' => self::OK, 'mod_reason' => null], ['id' => $id]);
            } else {
                $wpdb->update($table, ['mod_status' => self::OK, 'mod_reason' => null], ['id' => $id]);
            }
        } elseif ('block' === $action) {
            $wpdb->update($table, ['mod_status' => self::BLOCK, 'mod_reason' => 'manual'], ['id' => $id]);
        } elseif ('delete' === $action) {
            $wpdb->delete($table, ['id' => $id]);
        }
        wp_safe_redirect(admin_url('admin.php?page=tap-moderation'));
        exit;
    }

    private static function strip_accents($s) {
        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ñ' => 'n', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i',
            'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'î' => 'i',
            'ô' => 'o', 'û' => 'u', 'ç' => 'c',
        ]);
    }

    private static function matches($text, array $terms) {
        foreach ($terms as $term) {
            if (stripos($text, $term) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function abuse_words() {
        return [
            'idiota', 'imbecil', 'estupid', 'puta', 'puto', 'cabron', 'malparido',
            'hijodeputa', 'hijo de puta', 'joder', 'carajo', 'cojones', 'gilipollas',
            'pendej', 'desgraciad', 'mierda', 'pedazo de mierda', 'idiot', 'fuck',
            'shit', 'bitch', 'asshole', 'dickhead', 'dumbass', 'bastard',
        ];
    }

    private static function spam_words() {
        return [
            'gana dinero', 'ganar dinero', 'dinero facil', 'dinero rápido', 'click aqui',
            'haz clic aqui', 'compra ahora', 'oferta incre', 'trabaja desde casa',
            'sorteo', 'premio', 'casino', 'prestamo', 'prestamos rapidos',
            'win money', 'free money', 'buy now', 'click here', 'limited offer',
            'make money', 'you have won', 'urgent cash',
        ];
    }

    private static function is_gibberish($t) {
        if (preg_match('/(.)\1{7,}/', $t)) {
            return true;
        }
        if (false === strpos($t, ' ') && strlen($t) >= 12) {
            return (bool) preg_match('/^(?=.*[a-z])(?=.*[0-9])[a-z0-9]+$/', $t);
        }
        return false;
    }
}