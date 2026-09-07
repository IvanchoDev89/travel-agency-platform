<?php
/**
 * Template Name: Agency Profile
 */
get_header();
?>

<div class="entry-content">
    <?php
    $agency_id = intval($_GET['id'] ?? 0);
    if ($agency_id) {
        echo do_shortcode('[tap_agency_detail id="' . $agency_id . '"]');
    } else {
        echo '<p>' . __('Agency not specified.', 'travel-agency-platform') . '</p>';
    }
    ?>
</div>

<?php get_footer(); ?>
