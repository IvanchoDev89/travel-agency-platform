<?php
/**
 * Comments template for blog posts.
 */
if (post_password_required()) {
    return;
}
?>
<div id="comments" class="tap-post-comments">
    <?php if (have_comments()) : ?>
        <h2 class="tap-post-comments-title">
            <?php
            $comment_count = get_comments_number();
            printf(
                /* translators: %s: number of comments */
                esc_html(_n('%s comentario', '%s comentarios', $comment_count, 'travel-agency-platform')),
                number_format_i18n($comment_count)
            );
            ?>
        </h2>

        <ol class="tap-post-comment-list">
            <?php
            wp_list_comments([
                'style'       => 'ol',
                'short_ping'  => true,
                'avatar_size' => 48,
                'callback'    => 'tap_render_comment',
            ]);
            ?>
        </ol>

        <?php if (get_comment_pages_count() > 1) : ?>
            <nav class="tap-post-comment-nav">
                <?php previous_comments_link(__('← Comentarios anteriores', 'travel-agency-platform')); ?>
                <?php next_comments_link(__('Comentarios siguientes →', 'travel-agency-platform')); ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!comments_open()) : ?>
        <p class="tap-post-comments-closed"><?php esc_html_e('Los comentarios están cerrados.', 'travel-agency-platform'); ?></p>
    <?php else : ?>
        <div class="tap-post-comment-form-wrap">
            <h3><?php esc_html_e('Deja un comentario', 'travel-agency-platform'); ?></h3>
            <?php
            comment_form([
                'title_reply'          => '',
                'title_reply_to'       => __('Responder a %s', 'travel-agency-platform'),
                'cancel_reply_link'    => __('Cancelar respuesta', 'travel-agency-platform'),
                'label_submit'         => __('Publicar comentario', 'travel-agency-platform'),
                'comment_field'        => '<div class="tap-comment-field"><label for="comment">' . esc_html__('Comentario', 'travel-agency-platform') . '</label><textarea id="comment" name="comment" rows="5" required></textarea></div>',
                'fields'               => [
                    'author' => '<div class="tap-comment-field"><label for="author">' . esc_html__('Nombre', 'travel-agency-platform') . ' <span class="required">*</span></label><input id="author" name="author" type="text" value="' . esc_attr($commenter['comment_author']) . '" required></div>',
                    'email'  => '<div class="tap-comment-field"><label for="email">' . esc_html__('Email', 'travel-agency-platform') . ' <span class="required">*</span></label><input id="email" name="email" type="email" value="' . esc_attr($commenter['comment_author_email']) . '" required></div>',
                    'url'    => '<div class="tap-comment-field"><label for="url">' . esc_html__('Sitio web', 'travel-agency-platform') . '</label><input id="url" name="url" type="url" value="' . esc_attr($commenter['comment_author_url']) . '"></div>',
                ],
                'class_form'           => 'tap-comment-form',
                'class_submit'         => 'tap-btn tap-btn-primary',
                'submit_button'        => '<button type="submit" class="tap-btn tap-btn-primary">%s</button>',
                'comment_notes_before' => '',
                'comment_notes_after'  => '',
            ]);
            ?>
        </div>
    <?php endif; ?>
</div>

<?php
// Custom comment callback.
if (!function_exists('tap_render_comment')) {
    function tap_render_comment($comment, $args, $depth) {
        $GLOBALS['comment'] = $comment;
        ?>
        <li <?php comment_class('tap-post-comment'); ?> id="comment-<?php comment_ID(); ?>">
            <div class="tap-comment-avatar"><?php echo get_avatar($comment, 48); ?></div>
            <div class="tap-comment-body">
                <div class="tap-comment-meta">
                    <strong class="tap-comment-author"><?php echo esc_html(get_comment_author()); ?></strong>
                    <?php if ($comment->user_id) : ?>
                        <span class="tap-comment-badge"><?php esc_html_e('Autor', 'travel-agency-platform'); ?></span>
                    <?php endif; ?>
                    <time class="tap-comment-date" datetime="<?php echo esc_attr(get_comment_date('c')); ?>"><?php echo esc_html(get_comment_date()); ?></time>
                </div>
                <div class="tap-comment-content">
                    <?php comment_text(); ?>
                </div>
                <div class="tap-comment-actions">
                    <?php comment_reply_link(array_merge($args, ['depth' => $depth, 'max_depth' => $args['max_depth'], 'before' => '', 'after' => ''])); ?>
                </div>
            </div>
        </li>
        <?php
    }
}
?>