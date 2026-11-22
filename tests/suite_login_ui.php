<?php
/**
 * suite_login_ui.php — branded login page guards (theme side).
 * FUNCTIONAL: real filters, real render callbacks, real theme mods.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_login_ui.php
 */
require_once __DIR__ . '/bootstrap.php';

$theme = get_template_directory();

/* --- 1) branded login is active ---------------------------------------------- */
tap_t_assert(function_exists('tap_login_is_branded'), 'theme login module is loaded');
tap_t_assert(true === tap_login_is_branded(), 'branded login is enabled by default');
tap_t_assert(is_array(tap_login_media()) && array_key_exists('url', tap_login_media()) && array_key_exists('poster', tap_login_media()), 'login media resolver returns a shaped array');

/* --- 2) friendly Spanish credentials error ----------------------------------- */
$friendly = apply_filters('login_errors', 'Incorrect username or password.');
tap_t_assert(false !== strpos($friendly, 'No pudimos iniciar sesión.'), 'credential error is friendly Spanish');
tap_t_assert(false !== strpos($friendly, 'Restablecer contraseña'), 'credential error links to password reset');
tap_t_assert(false !== strpos($friendly, wp_lostpassword_url()), 'reset link points to the real lost-password URL');

/* --- 3) unrelated errors are left untouched ---------------------------------- */
$other = 'The link you followed has expired.';
tap_t_assert($other === apply_filters('login_errors', $other), 'unrelated login errors are not rewritten');

/* --- 4) extra CTA links render when pages exist ------------------------------- */
ob_start();
tap_login_form_footer();
$extra = ob_get_clean();
$register = get_page_by_path('register');
$support  = get_page_by_path('centro-de-soporte');
if ($register) {
    tap_t_assert(false !== strpos($extra, 'Regístrate aquí'), 'agency signup CTA rendered');
    tap_t_assert(false !== strpos($extra, esc_url(get_permalink($register))), 'signup CTA points to the real page');
}
if ($support) {
    tap_t_assert(false !== strpos($extra, 'Centro de soporte'), 'help center link rendered');
}
if (!$register && !$support) {
    tap_t_assert('' === $extra, 'no CTA markup when neither page exists');
}

/* --- 5) header/body branding filters ----------------------------------------- */
tap_t_assert(home_url('/') === apply_filters('login_headerurl', 'https://wordpress.org/'), 'login logo links to the site home');
$classes = apply_filters('login_body_class', []);
tap_t_assert(in_array('tap-login-branded', $classes, true), 'login body gets the branded class');

/* --- 6) JS/CSS guards for loading state and focus ----------------------------- */
$js  = (string) file_get_contents($theme . '/assets/js/login.js');
$css = (string) file_get_contents($theme . '/assets/css/login.css');
tap_t_assert(false !== strpos($js, 'user.focus()'), 'username field is focused on load');
tap_t_assert(false !== strpos($js, 'submit.disabled = true'), 'submit is disabled while logging in');
tap_t_assert(false !== strpos($css, '.tap-login-extra'), 'extra CTA has dedicated styling');
tap_t_assert(false !== strpos($css, ':focus-visible'), 'keyboard focus is visible');

tap_t_finish();
