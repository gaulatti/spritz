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
        'description' => __('Insert an Instagram post URL for Cronkite rendering.', 'spritz'),
        'categories'  => ['spritz-embeds'],
        'content'     => <<<HTML
<!-- wp:embed {"url":"https://www.instagram.com/p/POST_ID/","type":"rich","providerNameSlug":"instagram","responsive":true,"className":"wp-embed-aspect-1-1 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-rich is-provider-instagram wp-block-embed-instagram wp-embed-aspect-1-1 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
https://www.instagram.com/p/POST_ID/
</div></figure>
<!-- /wp:embed -->
HTML,
    ]);
});
