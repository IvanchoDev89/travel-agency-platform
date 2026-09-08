<?php
/**
 * Shared service reviews block (load + submit via REST).
 * Renders from the current global post; works for any tap_* service type.
 */
defined('ABSPATH') || exit;

$rev_type = get_post_type();
$rev_id   = get_the_ID();
?>

<div class="tap-acc-section tap-reviews-section" data-service-type="<?php echo esc_attr($rev_type); ?>" data-service-id="<?php echo esc_attr($rev_id); ?>">
  <h2><?php _e('Reviews', 'travel-agency-platform'); ?> <span class="tap-reviews-count"></span></h2>
  <div class="tap-reviews-summary">
    <div class="tap-reviews-average">
      <span class="tap-reviews-avg-score">0.0</span>
      <div class="tap-reviews-avg-stars"></div>
      <span class="tap-reviews-avg-total"><?php echo esc_html__('0 reseñas', 'travel-agency-platform'); ?></span>
    </div>
  </div>
  <div class="tap-reviews-list"></div>

  <?php if (is_user_logged_in()):
        $tap_can_review = class_exists('TAP_Reviews') && TAP_Reviews::can_review(get_current_user_id(), $rev_type, $rev_id);
    ?>
  <?php if ($tap_can_review): ?>
  <div class="tap-review-form-wrap">
    <h3><?php _e('Write a Review', 'travel-agency-platform'); ?></h3>
    <form class="tap-review-form">
      <input type="hidden" name="service_type" value="<?php echo esc_attr($rev_type); ?>">
      <input type="hidden" name="service_id" value="<?php echo esc_attr($rev_id); ?>">
      <div class="tap-review-stars">
        <label><?php _e('Your rating', 'travel-agency-platform'); ?></label>
        <div class="tap-star-input">
          <?php for ($i = 5; $i >= 1; $i--): ?>
          <input type="radio" name="rating" value="<?php echo $i; ?>" id="tap-star-<?php echo $i; ?>" <?php echo $i === 5 ? 'checked' : ''; ?>>
          <label for="tap-star-<?php echo $i; ?>" title="<?php echo esc_attr($i); ?> <?php echo esc_attr__('estrellas', 'travel-agency-platform'); ?>">&#9733;</label>
          <?php endfor; ?>
        </div>
      </div>
      <div class="tap-review-field">
        <label for="tap-review-title"><?php _e('Review title', 'travel-agency-platform'); ?></label>
        <input type="text" id="tap-review-title" name="title" class="tap-input" placeholder="<?php esc_attr_e('Summarize your experience', 'travel-agency-platform'); ?>">
      </div>
      <div class="tap-review-field">
        <label for="tap-review-content"><?php _e('Your review', 'travel-agency-platform'); ?></label>
        <textarea id="tap-review-content" name="content" class="tap-input" rows="4" required placeholder="<?php esc_attr_e('Tell others about your experience...', 'travel-agency-platform'); ?>"></textarea>
      </div>
      <button type="submit" class="tap-btn tap-btn-primary"><?php _e('Submit Review', 'travel-agency-platform'); ?></button>
      <span class="tap-review-msg"></span>
    </form>
  </div>
  <?php else: ?>
  <p class="tap-review-login"><?php echo esc_html__('Solo puedes dejar reseñas después de una reserva confirmada o completada.', 'travel-agency-platform'); ?></p>
  <?php endif; ?>
  <?php else: ?>
  <p class="tap-review-login"><?php printf(__('<a href="%s">Log in</a> to leave a review.', 'travel-agency-platform'), wp_login_url(get_permalink())); ?></p>
  <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
  var tapI18n = window.tapI18n || {};
  var $sec = $('.tap-reviews-section');
  if (!$sec.length) return;

  var serviceId = $sec.data('service-id');
  var serviceType = $sec.data('service-type');
  var restBase = '<?php echo esc_url_raw(rest_url('tap/v1')); ?>';
  var $list = $sec.find('.tap-reviews-list');
  var $avgScore = $sec.find('.tap-reviews-avg-score');
  var $avgStars = $sec.find('.tap-reviews-avg-stars');
  var $avgTotal = $sec.find('.tap-reviews-avg-total');
  var $count = $sec.find('.tap-reviews-count');
  var $form = $sec.find('.tap-review-form');
  var $msg = $sec.find('.tap-review-msg');

  function starHtml(score) {
    var s = '';
    for (var i = 1; i <= 5; i++) {
      s += (i <= Math.round(score || 0)) ? '★' : '☆';
    }
    return s;
  }

  $.getJSON(restBase + '/reviews/' + serviceType + '/' + serviceId, function(reviews) {
    $list.empty();
    if (!reviews.length) {
      $list.html('<p class="tap-reviews-empty">' + (tapI18n.reviewsEmptyShare || 'Aún no hay reseñas. Sé el primero en compartir tu experiencia.') + '</p>');
    }
    var sum = 0;
    $.each(reviews, function(i, r) {
      sum += parseFloat(r.rating);
      var avatar = $('<div class="tap-review-avatar">').text((r.user_name || 'U').charAt(0).toUpperCase());
      var stars = $('<div class="tap-review-stars-read">').html(starHtml(r.rating));
      var body = $('<div class="tap-review-body">');
      if (r.title) body.append($('<div class="tap-review-title">').text(r.title));
      body.append($('<p class="tap-review-text">').text(r.content));
      var meta = $('<div class="tap-review-meta">').text(r.user_name + ' · ' + new Date(r.created_at).toLocaleDateString());
      if (parseInt(r.is_verified, 10) === 1) meta.append('<span class="tap-review-verified">' + (tapI18n.reviewVerified || 'Reseña verificada') + '</span>');
      body.append(meta);
      $('<div class="tap-review-item">').append(avatar, $('<div class="tap-review-main">').append(stars, body)).appendTo($list);
      if (r.reply) {
        var replyWrap = $('<div class="tap-review-reply">');
        replyWrap.append($('<strong class="tap-review-reply-author">').text(r.reply_author + ' · ' + new Date(r.reply_at).toLocaleDateString()));
        replyWrap.append($('<p>').text(r.reply));
        $list.find('.tap-review-item').last().find('.tap-review-main').append(replyWrap);
      }
    });
    var avg = reviews.length ? (sum / reviews.length) : 0;
    $avgScore.text(avg.toFixed(1));
    $avgStars.html(starHtml(avg));
    $avgTotal.text(reviews.length + ' ' + (reviews.length === 1 ? (tapI18n.reviewOne || 'reseña') : (tapI18n.reviewMany || 'reseñas')));
    $count.text('(' + reviews.length + ')');
  });

  if ($form.length) {
    $form.on('submit', function(e) {
      e.preventDefault();
      var btn = $form.find('button[type=submit]');
      btn.prop('disabled', true);
      var rating = $form.find('input[name=rating]:checked').val();
      var data = {
        service_type: $form.find('input[name=service_type]').val(),
        service_id: $form.find('input[name=service_id]').val(),
        rating: rating,
        title: $form.find('input[name=title]').val(),
        content: $form.find('textarea[name=content]').val(),
      };
      $.ajax({
        url: restBase + '/review',
        method: 'POST',
        data: JSON.stringify(data),
        contentType: 'application/json',
        dataType: 'json',
        headers: { 'X-WP-Nonce': tap_ajax.rest_nonce },
      }).done(function(res) {
        $msg.addClass('tap-error').removeClass('tap-success').hide();
        $msg.removeClass('tap-error').addClass('tap-success').text(res.message || (tapI18n.reviewSubmitted || 'Review submitted')).show();
        $form.find('textarea[name=content]').val('');
        $form.find('input[name=title]').val('');
      }).fail(function(x) {
        var msg = x.responseJSON && x.responseJSON.message ? x.responseJSON.message : (tapI18n.reviewError2 || 'Error al enviar la reseña');
        $msg.removeClass('tap-success').addClass('tap-error').text(msg).show();
      }).always(function() { btn.prop('disabled', false); });
    });
  }

  var params = new URLSearchParams(window.location.search);
  var ref = params.get('tap_review');
  if (ref) {
    $sec.find('.tap-review-form-wrap').css('border-color', '#f59e0b').css('background', '#fffbeb');
    $sec.prepend('<p class="tap-review-thanks">' + (tapI18n.reviewThanks || 'Gracias por tu estancia (%s). ¡Cuéntanos cómo fue tu experiencia!').replace('%s', ref) + '</p>');
    setTimeout(function() { $sec[0].scrollIntoView({ behavior: 'smooth' }); }, 400);
  }
});
</script>