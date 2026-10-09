<?php
/**
 * PHPStan bootstrap: defines constants that only exist at runtime.
 *
 * PHPStan analyses the plugin files in isolation, so constants provided by
 * WordPress (DB_NAME, COOKIEPATH) or by the plugin entry point (TAP_*) are
 * declared here with harmless placeholders.
 */

defined('DB_NAME') || define('DB_NAME', 'wordpress');
defined('COOKIEPATH') || define('COOKIEPATH', '/');
defined('TAP_VERSION') || define('TAP_VERSION', '0.0.0');
defined('TAP_PLUGIN_DIR') || define('TAP_PLUGIN_DIR', __DIR__ . '/');
defined('TAP_PLUGIN_URL') || define('TAP_PLUGIN_URL', '');
