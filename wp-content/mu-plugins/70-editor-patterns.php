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
