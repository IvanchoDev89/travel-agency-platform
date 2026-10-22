<?php
defined('ABSPATH') || exit;

/**
 * Runtime requirements of the plugin, with a precise check for the PHP
 * versions affected by the critical remote code execution advisories of
 * October 2026.
 *
 * The two advisories (CVE-2026-109618, "RCE via OOM corruption", and
 * CVE-2026-109619, "RCE via stale global state") affect the PHP core
 * itself, not this plugin. They were published on 2026-10-21 and an
 * unauthenticated exploit has been demonstrated against PHP-FPM.
 *
 * Every maintained branch has its own patched release, so a single
 * "minimum version" is not enough: 8.3.30 satisfies ">= 8.2.35" but is
 * still vulnerable. The check below compares the running version against
 * the patched release of its own branch.
 */
class TAP_Requirements {

    /**
     * First patched release of every branch still supported upstream.
     *
     * @var array<string,string>
     */
    private static $patched = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /** Advisories behind the requirement. */
    const ADVISORIES = 'CVE-2026-109618, CVE-2026-109619';

    /**
     * Split a version into its comparable parts.
     *
     * @param string $version Version such as "8.5.12".
     * @return array{0:int,1:int,2:int}
     */
    private static function parts($version) {
        $clean = preg_replace('/[^0-9.].*$/', '', (string) $version);
        $bits  = array_map('intval', explode('.', $clean));
        return [
            isset($bits[0]) ? $bits[0] : 0,
            isset($bits[1]) ? $bits[1] : 0,
            isset($bits[2]) ? $bits[2] : 0,
        ];
    }

    /**
     * Compare two versions.
     *
     * @param string $left  First version.
     * @param string $right Second version.
     * @return int -1, 0 or 1.
     */
    private static function compare($left, $right) {
        $a = self::parts($left);
        $b = self::parts($right);
        for ($i = 0; $i < 3; $i++) {
            if ($a[$i] === $b[$i]) {
                continue;
            }
            return $a[$i] < $b[$i] ? -1 : 1;
        }
        return 0;
    }

    /**
     * Whether the given PHP version carries the security fixes.
     *
     * A version is considered patched when it belongs to a known branch and
     * is at least that branch's patched release, or when it belongs to a
     * branch newer than the newest known one (those were created from a
     * source tree that already carries the fix). Older branches have no
     * published fix and stay rejected, so the requirement is never silently
     * downgraded.
     *
     * @param string|null $version Version to check, defaults to the running one.
     * @return bool
     */
    public static function is_php_patched($version = null) {
        $version = null === $version ? PHP_VERSION : $version;
        $parts   = self::parts($version);
        $branch  = $parts[0] . '.' . $parts[1];

        if (isset(self::$patched[$branch])) {
            return self::compare($version, self::$patched[$branch]) >= 0;
        }

        // A branch released after the newest one we know about was created
        // from a source tree that already carries the fix, so it is safe.
        // Anything older has no published fix and stays rejected.
        $newest = self::parts(self::$patched['8.5']);
        if ($parts[0] > $newest[0]) {
            return true;
        }
        if ($parts[0] === $newest[0] && $parts[1] > $newest[1]) {
            return true;
        }
        return false;
    }

    /**
     * The patched release for the running branch, when it is known.
     *
     * @param string|null $version Version to inspect.
     * @return string Empty string when the branch has no published fix.
     */
    public static function patched_for($version = null) {
        $version = null === $version ? PHP_VERSION : $version;
        $parts   = self::parts($version);
        $branch  = $parts[0] . '.' . $parts[1];
        return isset(self::$patched[$branch]) ? self::$patched[$branch] : '';
    }

    /**
     * Human readable list of the patched releases, for the admin notice.
     *
     * @return string
     */
    public static function patched_list() {
        return implode(', ', array_values(self::$patched));
    }

    /**
     * Show an admin notice when the site runs on a vulnerable PHP.
     *
     * The plugin keeps working: the flaw is in the core, so the fix is to
     * update PHP, not to disable the platform. The notice only shows in the
     * administration screens, where the hosting can be acted upon.
     */
    public static function admin_notice() {
        if (self::is_php_patched() || !current_user_can('manage_options')) {
            return;
        }
        $patched = self::patched_for();
        if ('' === $patched) {
            $patched = self::patched_list();
        }
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p><p>%s</p><p><code>%s</code></p></div>',
            esc_html__('Este sitio usa una versión de PHP con vulnerabilidades críticas.', 'travel-agency-platform'),
            esc_html(sprintf(
                /* translators: 1: PHP version, 2: advisory identifiers */
                __('La versión %1$s está afectada por %2$s, que permiten ejecución remota de código sin autenticación en PHP-FPM. Actualiza PHP antes de publicar el sitio.', 'travel-agency-platform'),
                PHP_VERSION,
                self::ADVISORIES
            )),
            esc_html(__('Versiones corregidas: ', 'travel-agency-platform')),
            esc_html($patched)
        );
    }

    /**
     * Refuse to activate the plugin on a vulnerable PHP.
     *
     * Activation is the moment where the hosting can still be changed
     * without touching production.
     */
    public static function block_activation() {
        if (self::is_php_patched()) {
            return;
        }
        $patched = self::patched_for();
        if ('' === $patched) {
            $patched = self::patched_list();
        }
        wp_die(
            esc_html(sprintf(
                /* translators: 1: PHP version, 2: patched version */
                __('Travel Agency Platform necesita una versión de PHP con las correcciones de seguridad. La versión %1$s es vulnerable; actualiza a %2$s o posterior en tu hosting.', 'travel-agency-platform'),
                PHP_VERSION,
                $patched
            )),
            esc_html__('Versión de PHP no compatible', 'travel-agency-platform'),
            array('back_link' => true)
        );
    }
}
