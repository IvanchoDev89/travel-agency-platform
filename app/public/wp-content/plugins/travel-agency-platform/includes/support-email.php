<?php
/** Single source of truth for the public support address. */
defined('ABSPATH') || exit;

if (!function_exists('tap_support_email')) {
    function tap_support_email() {
        $email = (string) get_option('tap_support_email', 'soporte@visitnuevoarenal.com');
        return is_email($email) ? $email : 'soporte@visitnuevoarenal.com';
    }
}
