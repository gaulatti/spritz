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
    $now = spritz_iso_datetime();

    $slug = '/' . ltrim(get_post_field('post_name', $post), '/');
    $cat_slug = !empty($categories) ? $categories[0]['slug'] : 'news';
    $full_slug = $slug;

    if (strpos($slug, '/' . $cat_slug) !== 0) {
        $full_slug = '/' . $cat_slug . $slug;
    }

    $site_url = get_site_url();

    $payload = [
        'id'             => (string) $post->ID,
        'slug'           => $full_slug,
        'url'            => $full_slug,
        'layout'         => 'article-page',
        'canonicalUrl'   => $site_url . $full_slug,
        'contentVersion' => $now,
        'publishedAt'    => spritz_iso_datetime(strtotime($post->post_date_gmt)),
        'updatedAt'      => spritz_iso_datetime(strtotime($post->post_modified_gmt)),
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

    if (!$featured_image) {
        unset($payload['featuredImage']);
    }

    return $payload;
}

function spritz_iso_datetime($timestamp = null): string {
    if (!$timestamp) {
        $timestamp = time();
    }

    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
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
    if (!empty(trim($content)) && function_exists('parse_blocks')) {
        foreach (parse_blocks($content) as $wp_block) {
            $canonical = spritz_map_wp_block_to_canonical($wp_block);
            if (!$canonical) continue;

            foreach ($canonical as $block) {
                $blocks[] = $block;
            }
        }
    } elseif (!empty(trim($content))) {
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

function spritz_map_wp_block_to_canonical(array $wp_block): array {
    $name = $wp_block['blockName'] ?? '';
    $attrs = is_array($wp_block['attrs'] ?? null) ? $wp_block['attrs'] : [];

    if (!$name && !empty(trim((string) ($wp_block['innerHTML'] ?? '')))) {
        return spritz_rich_text_blocks(spritz_render_wp_block($wp_block));
    }

    switch ($name) {
        case 'core/paragraph':
        case 'core/freeform':
        case 'core/html':
        case 'core/table':
            return spritz_rich_text_blocks(spritz_render_wp_block($wp_block));

        case 'core/heading':
            $text = spritz_block_text($wp_block);
            if ($text === '') return [];

            $level = isset($attrs['level']) ? (int) $attrs['level'] : 2;
            if (!in_array($level, [2, 3, 4], true)) {
                $level = 2;
            }

            return [[
                'type' => 'heading',
                'text' => $text,
                'level' => $level,
            ]];

        case 'core/image':
            $image = spritz_canonical_image_from_block($wp_block);
            return $image ? [$image] : spritz_rich_text_blocks(spritz_render_wp_block($wp_block));

        case 'core/list':
            $items = spritz_list_items_from_block($wp_block);
            if (empty($items)) {
                return spritz_rich_text_blocks(spritz_render_wp_block($wp_block));
            }

            return [[
                'type' => 'list',
                'ordered' => !empty($attrs['ordered']),
                'items' => $items,
            ]];

        case 'core/separator':
            return [['type' => 'divider']];

        case 'core/quote':
        case 'core/pullquote':
            $text = spritz_block_text($wp_block);
            if ($text === '') return [];

            $block = [
                'type' => 'pullQuote',
                'text' => $text,
            ];

            $citation = trim(wp_strip_all_tags((string) ($attrs['citation'] ?? '')));
            if ($citation !== '') {
                $block['attribution'] = $citation;
            }

            return [$block];

        case 'core/embed':
            $url = spritz_block_url($wp_block);
            if (!$url) {
                return spritz_rich_text_blocks(spritz_render_wp_block($wp_block));
            }

            $provider = strtolower((string) ($attrs['providerNameSlug'] ?? ''));
            if ($provider === 'youtube' || str_contains($url, 'youtube.com') || str_contains($url, 'youtu.be')) {
                $video_id = spritz_youtube_video_id($url);
                return $video_id ? [['type' => 'youtube', 'videoId' => $video_id]] : spritz_rich_text_blocks(spritz_render_wp_block($wp_block));
            }

            if ($provider === 'twitter' || $provider === 'x' || str_contains($url, 'twitter.com') || str_contains($url, 'x.com')) {
                return [['type' => 'x', 'url' => $url]];
            }

            if ($provider === 'instagram' || str_contains($url, 'instagram.com')) {
                return [['type' => 'instagram', 'url' => $url]];
            }

            if ($provider === 'tiktok' || str_contains($url, 'tiktok.com')) {
                return [['type' => 'tiktok', 'url' => $url]];
            }

            if ($provider === 'spotify' || str_contains($url, 'open.spotify.com')) {
                return [['type' => 'spotify', 'url' => $url]];
            }

            return spritz_rich_text_blocks(spritz_render_wp_block($wp_block));

        case 'core-embed/youtube':
            $video_id = spritz_youtube_video_id(spritz_block_url($wp_block) ?: '');
            return $video_id ? [['type' => 'youtube', 'videoId' => $video_id]] : [];

        case 'core-embed/twitter':
            $url = spritz_block_url($wp_block);
            return $url ? [['type' => 'x', 'url' => $url]] : [];

        case 'core-embed/instagram':
            $url = spritz_block_url($wp_block);
            return $url ? [['type' => 'instagram', 'url' => $url]] : [];

        case 'core-embed/spotify':
            $url = spritz_block_url($wp_block);
            return $url ? [['type' => 'spotify', 'url' => $url]] : [];

        case 'core/audio':
            $url = spritz_block_url($wp_block);
            if (!$url && !empty($attrs['id'])) {
                $url = wp_get_attachment_url((int) $attrs['id']) ?: '';
            }

            if (!$url) return spritz_rich_text_blocks(spritz_render_wp_block($wp_block));

            $audio = [
                'type' => 'audio',
                'url' => spritz_rewrite_url_to_media_cdn($url),
            ];
            $caption = trim(wp_strip_all_tags((string) ($attrs['caption'] ?? '')));
            if ($caption !== '') {
                $audio['caption'] = $caption;
            }

            return [$audio];

        default:
            if (!empty($wp_block['innerBlocks']) && is_array($wp_block['innerBlocks'])) {
                $children = [];
                foreach ($wp_block['innerBlocks'] as $inner_block) {
                    if (!is_array($inner_block)) continue;
                    foreach (spritz_map_wp_block_to_canonical($inner_block) as $block) {
                        $children[] = $block;
                    }
                }
                if (!empty($children)) return $children;
            }

            return spritz_rich_text_blocks(spritz_render_wp_block($wp_block));
    }
}

function spritz_rich_text_blocks($html): array {
    $html = trim((string) $html);
    if ($html === '') return [];

    return [['type' => 'richText', 'html' => $html]];
}

function spritz_render_wp_block(array $wp_block): string {
    if (function_exists('render_block')) {
        return trim((string) render_block($wp_block));
    }

    return trim((string) ($wp_block['innerHTML'] ?? ''));
}

function spritz_block_text(array $wp_block): string {
    $html = spritz_render_wp_block($wp_block);
    return trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($html)));
}

function spritz_block_url(array $wp_block): string {
    $attrs = is_array($wp_block['attrs'] ?? null) ? $wp_block['attrs'] : [];
    $url = trim((string) ($attrs['url'] ?? $attrs['href'] ?? ''));
    if ($url !== '') return $url;

    $html = (string) ($wp_block['innerHTML'] ?? '');
    if (preg_match('/https?:\/\/[^\s"<]+/i', $html, $matches)) {
        return html_entity_decode($matches[0], ENT_QUOTES);
    }

    return '';
}

function spritz_canonical_image_from_block(array $wp_block): ?array {
    $attrs = is_array($wp_block['attrs'] ?? null) ? $wp_block['attrs'] : [];
    $url = trim((string) ($attrs['url'] ?? ''));
    $alt = trim((string) ($attrs['alt'] ?? ''));
    $caption = trim(wp_strip_all_tags((string) ($attrs['caption'] ?? '')));

    if (!$url && !empty($attrs['id'])) {
        $attachment_id = (int) $attrs['id'];
        $url = wp_get_attachment_url($attachment_id) ?: '';
        if ($alt === '') {
            $alt = trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
        }
        if ($caption === '') {
            $caption = trim(wp_strip_all_tags((string) wp_get_attachment_caption($attachment_id)));
        }
    }

    $html = (string) ($wp_block['innerHTML'] ?? '');
    if (!$url && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        $url = html_entity_decode($matches[1], ENT_QUOTES);
    }
    if ($alt === '' && preg_match('/<img[^>]+alt=["\']([^"\']*)["\']/i', $html, $matches)) {
        $alt = html_entity_decode($matches[1], ENT_QUOTES);
    }
    if ($caption === '' && preg_match('/<figcaption[^>]*>(.*?)<\/figcaption>/is', $html, $matches)) {
        $caption = trim(wp_strip_all_tags($matches[1]));
    }

    if (!$url) return null;

    $image = [
        'type' => 'image',
        'url' => spritz_rewrite_url_to_media_cdn($url),
        'alt' => $alt ?: 'Image',
    ];
    if ($caption !== '') {
        $image['caption'] = $caption;
    }

    return $image;
}

function spritz_list_items_from_block(array $wp_block): array {
    $items = [];

    if (!empty($wp_block['innerBlocks']) && is_array($wp_block['innerBlocks'])) {
        foreach ($wp_block['innerBlocks'] as $inner_block) {
            if (!is_array($inner_block)) continue;
            $text = spritz_block_text($inner_block);
            if ($text !== '') $items[] = $text;
        }
    }

    if (!empty($items)) return $items;

    $html = spritz_render_wp_block($wp_block);
    if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $matches)) {
        foreach ($matches[1] as $item_html) {
            $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($item_html)));
            if ($text !== '') $items[] = $text;
        }
    }

    return $items;
}

function spritz_youtube_video_id($url): string {
    $url = trim((string) $url);
    if ($url === '') return '';

    if (preg_match('/youtu\.be\/([A-Za-z0-9_-]{6,})/i', $url, $matches)) {
        return $matches[1];
    }

    if (preg_match('/[?&]v=([A-Za-z0-9_-]{6,})/i', $url, $matches)) {
        return $matches[1];
    }

    if (preg_match('/\/embed\/([A-Za-z0-9_-]{6,})/i', $url, $matches)) {
        return $matches[1];
    }

    return '';
}

function spritz_rewrite_url_to_media_cdn($url): string {
    $url = trim((string) $url);
    if ($url === '') return '';

    if (defined('CLOUDFRONT_MEDIA_DOMAIN') && CLOUDFRONT_MEDIA_DOMAIN) {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $host = strtolower((string) ($parsed['host'] ?? ''));
        $site_host = strtolower((string) (parse_url(get_site_url(), PHP_URL_HOST) ?: ''));
        $cdn_host = strtolower((string) CLOUDFRONT_MEDIA_DOMAIN);

        if ($host === $cdn_host) {
            return $url;
        }

        if ($path && (!$host || $host === $site_host)) {
            $path = preg_replace('#^/wp-content/uploads/#', '/uploads/', $path);
            if (str_starts_with($path, '/uploads/')) {
                return 'https://' . CLOUDFRONT_MEDIA_DOMAIN . $path;
            }
        }
    }

    return $url;
}
