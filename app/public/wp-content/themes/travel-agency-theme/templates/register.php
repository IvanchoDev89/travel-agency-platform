<?php
/**
 * Template Name: Registro (viajero + agencia)
 *
 * Rediseñado: encabezado con gradiente de marca, dos tarjetas (viajero y
 * agencia) y formulario con las clases del sistema tap- (inputs, botones,
 * grid), alineado con el look and feel de la landing y el login branded.
 */
get_header();
?>

<section class="tap-reg-hero">
    <div class="tap-container tap-reg-hero-inner">
        <p class="tap-reg-eyebrow"><?php esc_html_e('Únete a la comunidad', 'travel-agency-platform'); ?></p>
        <h1 class="tap-reg-title"><?php the_title(); ?></h1>
        <p class="tap-reg-subtitle"><?php esc_html_e('Creá tu cuenta y empezá a descubrir o publicar experiencias en Costa Rica.', 'travel-agency-platform'); ?></p>
    </div>
</section>

<div class="tap-container tap-reg-wrap">
    <div class="tap-reg-grid">

        <?php if (get_option('users_can_register')) : ?>
        <article class="tap-reg-card">
            <header class="tap-reg-card-head">
                <span class="tap-reg-badge"><?php esc_html_e('Viajero', 'travel-agency-platform'); ?></span>
                <h2><?php esc_html_e('Registrate como viajero', 'travel-agency-platform'); ?></h2>
                <p><?php esc_html_e('Gestioná tus reservas, favoritos y perfil desde un solo lugar.', 'travel-agency-platform'); ?></p>
            </header>

            <form name="registerform" id="registerform" class="tap-form" action="<?php echo esc_url(site_url('wp-login.php?action=register', 'login_post')); ?>" method="post">
                <div class="tap-input-group">
                    <label for="user_login"><?php esc_html_e('Nombre de usuario o correo electrónico', 'travel-agency-platform'); ?></label>
                    <input type="text" name="user_login" id="user_login" class="tap-input" autocomplete="username" required>
                </div>
                <div class="tap-input-group">
                    <label for="user_email"><?php esc_html_e('Correo electrónico', 'travel-agency-platform'); ?></label>
                    <input type="email" name="user_email" id="user_email" class="tap-input" autocomplete="email" required>
                </div>
                <p class="tap-reg-submit">
                    <input type="submit" name="wp-submit" id="wp-submit" class="tap-btn tap-btn-primary tap-btn-lg tap-btn-block" value="<?php esc_attr_e('Crear cuenta', 'travel-agency-platform'); ?>">
                </p>
                <p class="tap-reg-login-link">
                    <?php
                    /* translators: %s: URL de login */
                    printf(esc_html__('¿Ya tenés cuenta? %s', 'travel-agency-platform'), '<a href="' . esc_url(wp_login_url()) . '">' . esc_html__('Iniciá sesión', 'travel-agency-platform') . '</a>');
                    ?>
                </p>
                <?php do_action('register_form'); ?>
            </form>
        </article>
        <?php endif; ?>

        <article class="tap-reg-card">
            <header class="tap-reg-card-head">
                <span class="tap-reg-badge tap-reg-badge-secondary"><?php esc_html_e('Agencia', 'travel-agency-platform'); ?></span>
                <h2><?php esc_html_e('Registra tu agencia', 'travel-agency-platform'); ?></h2>
                <p><?php esc_html_e('Publicá tus alojamientos y tours, y recibí reservas de miles de viajeros.', 'travel-agency-platform'); ?></p>
            </header>
            <?php echo do_shortcode('[tap_agency_register]'); ?>
        </article>

    </div>
</div>

<?php get_footer();
