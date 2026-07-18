<?php

/**
 * Spritz Static JSON — serves aggregation feeds that Cronkite consumes
 * for homepage, category, article, and inventory JSON generation.
 *
 * Cronkite fetches from:
 *   {cdnUrl}{contentPath}/homepage-current-{lang}.json
 *   {cdnUrl}{contentPath}/{category}-current-{lang}.json
 *   {cdnUrl}{contentPath}/json/articles/{slug}.json
 *   {cdnUrl}{contentPath}/cronkite-inventory.json
 */

add_action('rest_api_init', function () {

    // ── Article JSON ──────────────────────────────────────────────
    register_rest_route('spritz/v1', '/json/articles/(?P<slug>[a-zA-Z0-9_/\-]+)\.json', [
        'methods' => 'GET',
        'callback' => 'spritz_get_article_json',
        'permission_callback' => '__return_true',
    ]);

    // ── Homepage JSON (all languages) ─────────────────────────────
    register_rest_route('spritz/v1', '/homepage-current-(?P<lang>[a-z]{2})\.json', [
        'methods' => 'GET',
        'callback' => 'spritz_get_homepage_json',
        'permission_callback' => '__return_true',
    ]);

    // ── Category JSON ─────────────────────────────────────────────
    register_rest_route('spritz/v1', '/(?P<category>[a-zA-Z0-9_\-]+)-current-(?P<lang>[a-z]{2})\.json', [
        'methods' => 'GET',
        'callback' => 'spritz_get_category_json',
        'permission_callback' => '__return_true',
    ]);

    // ── Inventory JSON ────────────────────────────────────────────
    register_rest_route('spritz/v1', '/cronkite-inventory\.json', [
        'methods' => 'GET',
        'callback' => 'spritz_get_inventory_json',
        'permission_callback' => '__return_true',
    ]);

    // ── Hero JSON ─────────────────────────────────────────────────
    register_rest_route('spritz/v1', '/hero-(?P<id>\d+)-current-(?P<lang>[a-z]{2})\.json', [
        'methods' => 'GET',
        'callback' => 'spritz_get_hero_json',
        'permission_callback' => '__return_true',
    ]);
});

add_action('save_post', 'spritz_publish_static_json_to_s3', 15, 3);

function spritz_publish_static_json_to_s3($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (wp_is_post_autosave($post_id)) return;
    if (!$post || $post->post_status !== 'publish') return;
    if ($post->post_type === 'revision') return;
    if ($post->post_type === 'attachment') return;

    if (!function_exists('spritz_s3_put_body')) {
        error_log('Spritz static JSON skipped: S3 publisher is not available.');
        return;
    }

    $languages = spritz_static_json_languages();
    $categories = spritz_get_all_categories_slugs();

    error_log(sprintf(
        'Spritz static JSON hook start: post=%s languages=%s categories=%s',
        (string) $post_id,
        implode(',', $languages),
        implode(',', $categories)
    ));

    foreach ($languages as $lang) {
        $homepage = spritz_static_json_response_data('spritz_get_homepage_json', ['lang' => $lang]);
        if ($homepage !== null) {
            $timestamp = (int) floor(microtime(true) * 1000);
            spritz_write_static_json('', 'homepage-current-' . $lang . '.json', $homepage);
            spritz_write_static_json('', 'homepage-' . $timestamp . '-' . $lang . '.json', $homepage);
            error_log(sprintf(
                'Homepage JSON generated: language=%s articles=%d categories=%d files=homepage-current-%s.json,homepage-%s-%s.json',
                $lang,
                isset($homepage['articles']) && is_array($homepage['articles']) ? count($homepage['articles']) : 0,
                isset($homepage['categories']) && is_array($homepage['categories']) ? count($homepage['categories']) : 0,
                $lang,
                (string) $timestamp,
                $lang
            ));
        }

        foreach ($categories as $category_slug) {
            $category = spritz_static_json_response_data('spritz_get_category_json', [
                'category' => $category_slug,
                'lang' => $lang,
            ]);

            if ($category === null) continue;

            spritz_write_static_json('', $category_slug . '-current-' . $lang . '.json', $category);
            error_log(sprintf(
                'Category JSON generated: category=%s language=%s articles=%d',
                $category_slug,
                $lang,
                isset($category['articles']) && is_array($category['articles']) ? count($category['articles']) : 0
            ));
        }
    }

    $article_payload = spritz_build_article_payload($post);
    $article_slug = '/' . ltrim((string) ($article_payload['slug'] ?? ''), '/');
    if ($article_slug !== '/') {
        spritz_write_static_json('json/articles', ltrim($article_slug, '/') . '.json', $article_payload);
    }

    $inventory = spritz_static_json_response_data('spritz_get_inventory_json', []);
    if ($inventory !== null) {
        spritz_write_static_json('', 'cronkite-inventory.json', $inventory);
    }

    error_log(sprintf('Spritz static JSON hook complete: post=%s', (string) $post_id));
}

function spritz_static_json_languages(): array {
    return apply_filters('spritz_static_json_languages', ['en', 'es', 'it']);
}

function spritz_static_json_response_data($callback, array $params): ?array {
    $request = new WP_REST_Request('GET');
    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    $response = call_user_func($callback, $request);
    $response = rest_ensure_response($response);

    if ($response->get_status() >= 400) {
        return null;
    }

    $data = $response->get_data();
    return is_array($data) ? $data : null;
}

function spritz_write_static_json($collection_slug, $filename, $payload): void {
    $collection_slug = trim((string) $collection_slug, '/');
    $filename = ltrim((string) $filename, '/');
    $key = 'content/' . ($collection_slug ? $collection_slug . '/' : '') . $filename;
    $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $uploaded = spritz_s3_put_body(
        $key,
        $body,
        'application/json',
        'public, max-age=60, stale-while-revalidate=300'
    );

    if ($uploaded) {
        error_log(sprintf('Static JSON uploaded: s3://%s/%s', spritz_s3_bucket(), $key));
    }
}

function spritz_static_content_url($path): string {
    $path = ltrim((string) $path, '/');

    if (function_exists('spritz_s3_cloudfront_domain') && spritz_s3_cloudfront_domain()) {
        return 'https://' . spritz_s3_cloudfront_domain() . '/content/' . $path;
    }

    return get_site_url() . '/wp-json/spritz/v1/' . $path;
}

// ── Article JSON ──────────────────────────────────────────────────
function spritz_get_article_json(WP_REST_Request $request) {
    $slug = $request->get_param('slug');
    $posts = get_posts([
        'name'      => basename($slug),
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 1,
    ]);

    if (empty($posts)) {
        return new WP_REST_Response(['error' => 'Not found'], 404);
    }

    return rest_ensure_response(spritz_build_article_payload($posts[0]));
}

// ── Homepage JSON ─────────────────────────────────────────────────
function spritz_get_homepage_json(WP_REST_Request $request) {
    $lang = $request->get_param('lang') ?: 'en';

    $posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'lang'           => $lang,
    ]);

    $articles = [];
    foreach ($posts as $post) {
        $articles[] = spritz_build_article_reference($post, $lang);
    }

    $categories = spritz_get_all_categories_for_json();

    $homepage_id = spritz_get_homepage_post_id();
    $homepage_post = $homepage_id ? get_post($homepage_id) : null;

    $hero = spritz_get_active_hero($lang);

    $payload = [
        'generatedAt' => gmdate('c'),
        'page' => $homepage_post ? [
            'title'     => $homepage_post->post_title,
            'excerpt'   => $homepage_post->post_excerpt,
            'updatedAt' => gmdate('c', strtotime($homepage_post->post_modified_gmt)),
            'meta'      => ['description' => $homepage_post->post_excerpt],
        ] : null,
        'categories' => $categories,
        'articles'   => $articles,
        'hero'       => $hero['hero'] ?? null,
        'heroSlides' => $hero['heroSlides'] ?? null,
        'heroTitle'  => $hero['heroTitle'] ?? null,
        'heroSlug'   => $hero['heroSlug'] ?? null,
        'heroExcerpt' => $hero['heroExcerpt'] ?? null,
        'heroRelated' => $hero['heroRelated'] ?? null,
        'heroLayout'  => $hero['heroLayout'] ?? null,
        'heroCategories' => $hero['heroCategories'] ?? null,
        'breakingNews' => null,
        'mourningMode' => false,
    ];

    return rest_ensure_response($payload);
}

// ── Category JSON ─────────────────────────────────────────────────
function spritz_get_category_json(WP_REST_Request $request) {
    $category_slug = $request->get_param('category');
    $lang = $request->get_param('lang') ?: 'en';

    $cat = get_category_by_slug($category_slug);
    if (!$cat) {
        return new WP_REST_Response(['error' => 'Category not found'], 404);
    }

    $posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'category'       => $cat->term_id,
        'lang'           => $lang,
    ]);

    $articles = [];
    foreach ($posts as $post) {
        $articles[] = spritz_build_article_reference($post, $lang);
    }

    $payload = [
        'generatedAt' => gmdate('c'),
        'category' => [
            'name'        => $cat->name,
            'slug'        => $cat->slug,
            'description' => $cat->description,
            'updatedAt'   => gmdate('c'),
        ],
        'categories' => spritz_get_all_categories_for_json(),
        'articles'   => $articles,
    ];

    return rest_ensure_response($payload);
}

// ── Inventory JSON ────────────────────────────────────────────────
function spritz_get_inventory_json(WP_REST_Request $request) {
    $posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $documents = [];
    foreach ($posts as $post) {
        $lang = spritz_get_post_language($post->ID);
        $slug = '/' . ltrim(get_post_field('post_name', $post), '/');
        $cats = wp_get_post_categories($post->ID, ['fields' => 'slugs']);
        $cat_prefix = !empty($cats) ? '/' . $cats[0] : '';
        $url = spritz_static_content_url('json/articles' . $cat_prefix . $slug . '.json');

        $documents[] = [
            'type'     => 'article',
            'locale'   => $lang,
            'language' => $lang,
            'slug'     => $slug,
            'url'      => $url,
        ];
    }

    $categories = spritz_get_all_categories_slugs();

    foreach ($categories as $cat_slug) {
        foreach (['en', 'es', 'it'] as $lang) {
            $documents[] = [
                'type'     => 'category',
                'locale'   => $lang,
                'slug'     => $cat_slug,
                'url'      => spritz_static_content_url($cat_slug . '-current-' . $lang . '.json'),
            ];
        }
    }

    foreach (['en', 'es', 'it'] as $lang) {
        $documents[] = [
            'type'     => 'homepage',
            'locale'   => $lang,
            'url'      => spritz_static_content_url('homepage-current-' . $lang . '.json'),
        ];
    }

    return rest_ensure_response([
        'generatedAt' => gmdate('c'),
        'locales' => ['en', 'es', 'it'],
        'documents' => $documents,
    ]);
}

// ── Hero JSON ─────────────────────────────────────────────────────
function spritz_get_hero_json(WP_REST_Request $request) {
    $hero_id = (int) $request->get_param('id');
    $lang = $request->get_param('lang') ?: 'en';

    $hero = get_post($hero_id);
    if (!$hero || $hero->post_type !== 'post' || !has_term('hero', 'post_tag', $hero->ID)) {
        return new WP_REST_Response(['error' => 'Hero not found'], 404);
    }

    return rest_ensure_response([
        'generatedAt' => gmdate('c'),
        'locale' => $lang,
        'hero' => spritz_build_article_payload($hero),
    ]);
}

// ── Shared Helpers ────────────────────────────────────────────────
function spritz_build_article_payload(WP_Post $post): array {
    $categories = spritz_get_categories($post->ID);
    $featured_image = spritz_get_featured_image($post->ID);
    $now = gmdate('c');
    $lang = spritz_get_post_language($post->ID);
    $slug = '/' . ltrim(get_post_field('post_name', $post), '/');
    $cat_slug = !empty($categories) ? $categories[0]['slug'] : 'news';

    $full_slug = strpos($slug, '/' . $cat_slug) === 0 ? $slug : '/' . $cat_slug . $slug;

    return [
        'id'             => (string) $post->ID,
        'slug'           => $full_slug,
        'url'            => $full_slug,
        'layout'         => 'article-page',
        'canonicalUrl'   => get_site_url() . $full_slug,
        'contentVersion' => $now,
        'publishedAt'    => gmdate('c', strtotime($post->post_date_gmt)),
        'updatedAt'      => gmdate('c', strtotime($post->post_modified_gmt)),
        'status'         => 'published',
        'title'          => get_the_title($post),
        'excerpt'        => get_the_excerpt($post) ?: '',
        'language'       => $lang,
        'featured'       => has_term('featured', 'post_tag', $post->ID),
        'authors'        => spritz_get_authors($post),
        'categories'     => $categories,
        'featuredImage'  => $featured_image,
        'body'           => spritz_get_body($post),
        'seo'            => [
            'metaTitle'       => get_the_title($post),
            'metaDescription' => get_the_excerpt($post) ?: '',
            'ogImage'         => $featured_image['url'] ?? '',
        ],
        'navigation' => [
            'categories' => spritz_get_all_categories_for_json(),
        ],
        'articles' => [],
    ];
}

function spritz_build_article_reference(WP_Post $post, string $lang): array {
    $cats = spritz_get_categories($post->ID);
    $image = spritz_get_featured_image($post->ID);
    $cat_slug = !empty($cats) ? $cats[0]['slug'] : 'news';
    $slug = '/' . ltrim(get_post_field('post_name', $post), '/');
    $url = '/' . $cat_slug . $slug;

    return [
        'id'      => (string) $post->ID,
        'url'     => $url,
        'title'   => get_the_title($post),
        'excerpt' => get_the_excerpt($post) ?: '',
        'featured' => has_term('featured', 'post_tag', $post->ID),
        'categories' => $cats,
        'featuredImage' => $image,
        'updatedAt'   => gmdate('c', strtotime($post->post_modified_gmt)),
        'publishedAt' => gmdate('c', strtotime($post->post_date_gmt)),
        'time' => '',
    ];
}

function spritz_get_all_categories_for_json(): array {
    $cats = get_categories(['hide_empty' => false]);
    $result = [];
    foreach ($cats as $cat) {
        $result[] = ['name' => $cat->name, 'slug' => $cat->slug];
    }
    return $result;
}

function spritz_get_all_categories_slugs(): array {
    return get_categories(['hide_empty' => false, 'fields' => 'slugs']);
}

function spritz_get_homepage_post_id(): ?int {
    $pages = get_posts([
        'post_type'   => 'page',
        'post_status' => 'publish',
        'meta_key'    => '_wp_page_template',
        'meta_value'  => 'template-homepage.php',
        'posts_per_page' => 1,
    ]);

    if (!empty($pages)) return $pages[0]->ID;

    $front = get_option('page_on_front');
    return $front ? (int) $front : null;
}

function spritz_get_active_hero(string $lang): array {
    $heroes = get_posts([
        'post_type'   => 'post',
        'post_status' => 'publish',
        'tag'         => 'hero',
        'posts_per_page' => 25,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'lang'        => $lang,
    ]);

    if (empty($heroes)) return [];
    $hero = $heroes[0];

    $cats = spritz_get_categories($hero->ID);
    $image = spritz_get_featured_image($hero->ID);
    $slug = '/' . ltrim(get_post_field('post_name', $hero), '/');

    $related = get_posts([
        'post_type'   => 'post',
        'post_status' => 'publish',
        'tag'         => 'hero',
        'posts_per_page' => 4,
        'exclude'     => [$hero->ID],
        'lang'        => $lang,
    ]);

    $related_refs = [];
    foreach ($related as $r) {
        $related_refs[] = spritz_build_article_reference($r, $lang);
    }

    return [
        'hero'           => $image,
        'heroSlides'     => [$image],
        'heroTitle'      => get_the_title($hero),
        'heroSlug'       => $slug,
        'heroExcerpt'    => get_the_excerpt($hero) ?: '',
        'heroCategories' => $cats,
        'heroRelated'    => $related_refs,
        'heroLayout'     => 'spotlight',
    ];
}

// ── Reuse functions from 30-pipeline.php ─────────────────────────
if (!function_exists('spritz_get_categories')) {
    function spritz_get_categories($post_id): array {
        $cats = wp_get_post_categories($post_id, ['fields' => 'all']);
        $result = [];
        foreach ($cats as $cat) {
            $result[] = ['name' => $cat->name, 'slug' => $cat->slug];
        }
        return !empty($result) ? $result : [['name' => 'News', 'slug' => 'news']];
    }
}

if (!function_exists('spritz_get_featured_image')) {
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
}

if (!function_exists('spritz_get_authors')) {
    function spritz_get_authors($post): array {
        $user = get_userdata($post->post_author);
        if (!$user) return [['name' => 'ModoItaliano', 'slug' => 'modoitaliano']];
        return [['name' => $user->display_name, 'slug' => sanitize_title($user->display_name)]];
    }
}

if (!function_exists('spritz_get_body')) {
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
}

if (!function_exists('spritz_get_post_language')) {
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
}
