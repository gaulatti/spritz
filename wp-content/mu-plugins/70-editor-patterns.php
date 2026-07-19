<?php

/**
 * Spritz editor patterns.
 *
 * These are authoring shortcuts for block shapes the static pipeline knows how
 * to translate into canonical article blocks.
 */

add_action('init', function () {
    if (!function_exists('register_block_pattern')) return;

    if (function_exists('register_block_pattern_category')) {
        register_block_pattern_category('spritz-embeds', [
            'label' => __('Spritz Embeds', 'spritz'),
        ]);
    }

    register_block_pattern('spritz/instagram-embed', [
        'title'       => __('Instagram Embed', 'spritz'),
        'description' => __('Insert an Instagram post URL for Cronkite rendering without relying on WordPress oEmbed preview.', 'spritz'),
        'categories'  => ['spritz-embeds'],
        'content'     => <<<HTML
<!-- wp:paragraph -->
<p>[spritz-instagram url="https://www.instagram.com/p/POST_ID/"]</p>
<!-- /wp:paragraph -->
HTML,
    ]);
});

add_action('after_setup_theme', function () {
    add_theme_support('post-thumbnails', ['post']);
});

add_action('add_meta_boxes_post', function () {
    add_meta_box(
        'spritz-post-slug',
        __('Spritz Slug', 'spritz'),
        'spritz_render_post_slug_metabox',
        'post',
        'side',
        'high'
    );
});

function spritz_render_post_slug_metabox(WP_Post $post): void {
    wp_nonce_field('spritz_save_post_slug', 'spritz_post_slug_nonce');
    ?>
    <p>
        <label for="spritz_post_slug"><?php esc_html_e('URL slug', 'spritz'); ?></label>
        <input
            type="text"
            id="spritz_post_slug"
            name="spritz_post_slug"
            value="<?php echo esc_attr($post->post_name); ?>"
            class="widefat"
        />
    </p>
    <?php
}

add_filter('wp_insert_post_data', function ($data, $postarr) {
    if (($data['post_type'] ?? '') !== 'post') return $data;
    if (!isset($_POST['spritz_post_slug'])) return $data;
    if (!isset($_POST['spritz_post_slug_nonce']) || !wp_verify_nonce((string) $_POST['spritz_post_slug_nonce'], 'spritz_save_post_slug')) return $data;
    if (!current_user_can('edit_post', (int) ($postarr['ID'] ?? 0))) return $data;

    $slug = sanitize_title((string) wp_unslash($_POST['spritz_post_slug']));
    if ($slug !== '') {
        $data['post_name'] = $slug;
    }

    return $data;
}, 10, 2);
