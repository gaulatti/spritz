<?php

/**
 * Spritz Pipeline — Cronkite integration.
 *
 * On post save, builds a canonical article payload and POSTs it
 * to Cronkite's /pipeline/rerender endpoint, mirroring the
 * Auburndale → Cronkite contract.
 */

add_action('save_post', 'spritz_pipeline_push', 20, 3);

function spritz_pipeline_push($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (wp_is_post_autosave($post_id)) return;
    if ($post->post_status !== 'publish') return;
    if ($post->post_type === 'revision') return;
    if ($post->post_type === 'attachment') return;

    $cronkite_url = defined('CRONKITE_URL') ? CRONKITE_URL : getenv('CRONKITE_URL');
    $tenant_slug  = defined('CRONKITE_TENANT_SLUG') ? CRONKITE_TENANT_SLUG : getenv('CRONKITE_TENANT_SLUG');
    $pipeline_token = defined('PIPELINE_TOKEN') ? PIPELINE_TOKEN : getenv('PIPELINE_TOKEN');

    if (!$cronkite_url || !$tenant_slug || !$pipeline_token) return;

    $payload = spritz_build_canonical_article($post);

    $body = wp_json_encode(['article' => $payload]);
    $headers = [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $pipeline_token,
        'x-tenant-id'   => $tenant_slug,
    ];

    wp_remote_post($cronkite_url . '/pipeline/rerender', [
        'body'    => $body,
        'headers' => $headers,
        'timeout' => 15,
        'blocking' => false,
    ]);

    // ── Social delivery ────────────────────────────────────────
    spritz_trigger_social_delivery($post, $payload, $cronkite_url, $tenant_slug, $pipeline_token);
}

function spritz_trigger_social_delivery($post, $article_payload, $cronkite_url, $tenant_slug, $pipeline_token) {
    $site_url = get_site_url();
    $slug = $article_payload['slug'];
    $urls = [$site_url . $slug];

    $cats = wp_get_post_categories($post->ID, ['fields' => 'all']);
    $cat_entries = [];
    foreach ($cats as $cat) {
        $cat_entries[] = ['slug' => $cat->slug, 'name' => $cat->name];
    }

    $social = [
        'urls' => $urls,
        'article' => [
            'title'            => get_the_title($post),
            'excerpt'          => get_the_excerpt($post) ?: '',
            'imageUrl'         => $article_payload['featuredImage']['url'] ?? '',
            'postToTwitter'    => true,
            'postToInstagram'  => true,
            'categories'       => $cat_entries,
        ],
        'language' => $article_payload['language'],
    ];

    $headers = [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $pipeline_token,
        'x-tenant-id'   => $tenant_slug,
    ];

    wp_remote_post($cronkite_url . '/pipeline/social-delivery', [
        'body'    => wp_json_encode($social),
        'headers' => $headers,
        'timeout' => 10,
        'blocking' => false,
    ]);
}

function spritz_build_canonical_article($post): array {
    $lang = spritz_get_post_language($post->ID);
    $categories = spritz_get_categories($post->ID);
    $featured_image = spritz_get_featured_image($post->ID);
    $body = spritz_get_body($post);
    $authors = spritz_get_authors($post);
    $now = gmdate('c');

    $slug = '/' . ltrim(get_post_field('post_name', $post), '/');
    $cat_slug = !empty($categories) ? $categories[0]['slug'] : 'news';
    $full_slug = $slug;

    if (strpos($slug, '/' . $cat_slug) !== 0) {
        $full_slug = '/' . $cat_slug . $slug;
    }

    $site_url = get_site_url();

    return [
        'id'             => (string) $post->ID,
        'slug'           => $full_slug,
        'url'            => $full_slug,
        'layout'         => 'article-page',
        'canonicalUrl'   => $site_url . $full_slug,
        'contentVersion' => $now,
        'publishedAt'    => gmdate('c', strtotime($post->post_date_gmt)),
        'updatedAt'      => gmdate('c', strtotime($post->post_modified_gmt)),
        'status'         => 'published',
        'title'          => get_the_title($post),
        'excerpt'        => get_the_excerpt($post) ?: '',
        'language'       => $lang,
        'featured'       => has_term('featured', 'post_tag', $post->ID),
        'authors'        => $authors,
        'categories'     => $categories,
        'featuredImage'  => $featured_image,
        'body'           => $body,
        'seo'            => [
            'metaTitle'       => get_the_title($post),
            'metaDescription' => get_the_excerpt($post) ?: '',
            'ogImage'         => $featured_image['url'] ?? '',
        ],
        'navigation' => [
            'categories' => spritz_get_all_categories(),
        ],
        'articles' => [],
    ];
}

function spritz_get_post_language($post_id): string {
    if (function_exists('pll_get_post_language')) {
        $lang = pll_get_post_language($post_id, 'slug');
        return $lang ?: 'en';
    }
    if (function_exists('wpml_get_language_information')) {
        $info = wpml_get_language_information(null, $post_id);
        return $info['language_code'] ?? 'en';
    }
    return defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'en';
}

function spritz_get_categories($post_id): array {
    $cats = wp_get_post_categories($post_id, ['fields' => 'all']);
    $result = [];
    foreach ($cats as $cat) {
        $result[] = [
            'name' => $cat->name,
            'slug' => $cat->slug,
        ];
    }
    return !empty($result) ? $result : [['name' => 'News', 'slug' => 'news']];
}

function spritz_get_all_categories(): array {
    $cats = get_categories(['hide_empty' => false]);
    $result = [];
    foreach ($cats as $cat) {
        $result[] = ['name' => $cat->name, 'slug' => $cat->slug];
    }
    return $result;
}

function spritz_get_featured_image($post_id): ?array {
    $image_id = get_post_thumbnail_id($post_id);
    if (!$image_id) return null;

    $url = wp_get_attachment_url($image_id);
    if (!$url) return null;

    $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);

    if (defined('CLOUDFRONT_MEDIA_DOMAIN') && CLOUDFRONT_MEDIA_DOMAIN) {
        $parsed = parse_url($url);
        $url = 'https://' . CLOUDFRONT_MEDIA_DOMAIN . ($parsed['path'] ?? '');
    }

    return ['url' => $url, 'alt' => $alt ?: get_the_title($post_id), 'caption' => ''];
}

function spritz_get_authors($post): array {
    $user = get_userdata($post->post_author);
    if (!$user) return [['name' => 'ModoItaliano', 'slug' => 'modoitaliano']];
    return [[
        'name' => $user->display_name,
        'slug' => sanitize_title($user->display_name),
    ]];
}

function spritz_get_body($post): array {
    $blocks = [];

    $content = get_post_field('post_content', $post);
    if (!empty(trim($content))) {
        $blocks[] = ['type' => 'richText', 'html' => apply_filters('the_content', $content)];
    }

    if (function_exists('get_fields')) {
        $acf_fields = get_fields($post->ID);
        if (is_array($acf_fields)) {
            foreach ($acf_fields as $key => $value) {
                if ($key === 'hero' || $key === 'featured_image' || $key === 'featuredImage') continue;
                if (is_string($value) && !empty(trim($value))) {
                    $blocks[] = ['type' => 'richText', 'html' => wpautop($value)];
                }
            }
        }
    }

    return !empty($blocks) ? $blocks : [['type' => 'richText', 'html' => '']];
}
