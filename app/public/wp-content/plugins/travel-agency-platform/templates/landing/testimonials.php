<?php
// Testimonials section.
$settings = $sec['settings'] ?? [];
$items = $settings['items'] ?? [];
$title = $settings['title'] ?? __('Lo que dicen nuestros viajeros', 'travel-agency-platform');
?>
<?php if (!empty($items)): ?>
<section class="tap-landing-testimonials">
    <div class="tap-container">
        <h2 class="tap-section-title"><?php echo esc_html($title); ?></h2>
        <div class="tap-testimonials-grid">
            <?php foreach ($items as $item): ?>
                <div class="tap-testimonial-card">
                    <?php if (!empty($item['avatar'])): ?>
                        <img src="<?php echo esc_url($item['avatar']); ?>" alt="" class="tap-testimonial-avatar">
                    <?php endif; ?>
                    <div class="tap-testimonial-stars"><?php echo str_repeat('★', (int) ($item['rating'] ?? 5)); ?></div>
                    <p class="tap-testimonial-text">"<?php echo esc_html($item['text'] ?? ''); ?>"</p>
                    <strong class="tap-testimonial-name"><?php echo esc_html($item['name'] ?? ''); ?></strong>
                    <?php if (!empty($item['role'])): ?>
                        <span class="tap-testimonial-role"><?php echo esc_html($item['role']); ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>