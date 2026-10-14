<?php
/**
 * Template Name: Registro (viajero + agencia)
 */
get_header();
?>

<div class="tap-page-header">
    <div class="tap-container">
        <h1><?php the_title(); ?></h1>
    </div>
</div>

<div class="entry-content">
    <?php if (get_option('users_can_register')): ?>
    <section class="tap-register-traveler" style="max-width:32rem;margin:0 auto 2.5rem;">
        <h2><?php esc_html_e('Regístrate como viajero', 'travel-agency-platform'); ?></h2>
        <p><?php esc_html_e('Gestiona tus reservas, favoritos y perfil desde un solo lugar.', 'travel-agency-platform'); ?></p>
        <form name="registerform" id="registerform" action="<?php echo esc_url(site_url('wp-login.php?action=register', 'login_post')); ?>" method="post">
            <p>
                <label for="user_login"><?php esc_html_e('Nombre de usuario o correo electrónico', 'travel-agency-platform'); ?></label>
                <input type="text" name="user_login" id="user_login" class="input" size="20" value="" autocomplete="username" required>
            </p>
            <p>
                <label for="user_email"><?php esc_html_e('Correo electrónico', 'travel-agency-platform'); ?></label>
                <input type="email" name="user_email" id="user_email" class="input" size="20" value="" autocomplete="email" required>
            </p>
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e('Crear cuenta', 'travel-agency-platform'); ?>">
            </p>
            <?php do_action('register_form'); ?>
        </form>
    </section>
    <?php endif; ?>
    <section class="tap-register-agency" style="max-width:32rem;margin:0 auto;">
        <h2><?php esc_html_e('Registra tu agencia', 'travel-agency-platform'); ?></h2>
        <?php echo do_shortcode('[tap_agency_register]'); ?>
    </section>
</div>

<?php get_footer(); ?>
