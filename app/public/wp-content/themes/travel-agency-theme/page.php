<?php get_header(); ?>

<?php while (have_posts()): the_post(); ?>
    <div class="tap-page-header">
        <div class="tap-container">
            <h1><?php the_title(); ?></h1>
        </div>
    </div>
    <div class="entry-content">
        <?php the_content(); ?>
    </div>
<?php endwhile; ?>

<?php get_footer(); ?>
