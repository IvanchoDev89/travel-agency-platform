<?php
/**
 * TAP_Site_Hardening — small, low-risk WordPress hardening filters.
 *
 * All checks are scoped so the platform's own public /tap/v1/* REST routes,
 * the front-end and wp-admin keep working exactly as before.
 */
defined('ABSPATH') || exit;

class TAP_Site_Hardening {

    public static function init() {
        // XML-RPC is unused by the platform (no Jetpack / IXR / remote publishing).
        add_filter('xmlrpc_enabled', '__return_false');

        // Stop anonymous user enumeration without touching /tap/v1/* or admin.
        add_filter('rest_pre_dispatch', [__CLASS__, 'block_user_discovery'], 10, 3);

        // Redirect ?author=N author archives (no author.php in the theme).
        add_action('template_redirect', [__CLASS__, 'redirect_author_archives']);
    }

    /**
     * Block only the anonymous core user-listing routes. Using
     * rest_pre_dispatch (3 args) lets us scope by the real route, unlike
     * rest_authentication_errors which would break every public tap/v1 route.
     */
    public static function block_user_discovery($result, $server, $request) {
        $route = (string) $request->get_route();
        // /wp/v2/users and /wp/v2/users/<id> are the enumeration vectors.
        // The REST route uses the "wp" namespace, not the URL prefix (wp-json).
        if (!preg_match('#^/wp/v2/users(?:/\d+)?$#', $route)) {
            return $result;
        }
        if (is_user_logged_in() && current_user_can('list_users')) {
            return $result; // editors/admins keep their user queries
        }
        return new WP_Error(
            'rest_forbidden',
            __('No autorizado.', 'travel-agency-platform'),
            ['status' => rest_authorization_required_code()]
        );
    }

    /** Send ?author=N requests to the home page instead of an author archive. */
    public static function redirect_author_archives() {
        if (is_admin() || !is_author()) {
            return;
        }
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
}
