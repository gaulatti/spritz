<?php

function spritz_page_translation_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

putenv('CRONKITE_URL=https://cronkite.test');
putenv('CRONKITE_TENANT_SLUG=modoitaliano');
putenv('PIPELINE_TOKEN=test-only-token');
putenv('PUBLIC_SITE_URL=https://modoitaliano.fm');

remove_action('save_post', 'spritz_publish_static_json_to_s3', 15);

global $wpdb;
spritz_ai_translation_ensure_table();
$wpdb->query('DELETE FROM ' . spritz_ai_translation_table_name());
update_option(SPRITZ_AI_TRANSLATION_OPTION, [
    'enabled' => true,
    'auto_publish' => true,
    'gemini_model' => 'test-model',
    'target_languages' => ['en', 'it'],
    'request_timeout_ms' => 1000,
    'batch_max_items' => 75,
    'batch_max_characters' => 40000,
], false);

$requests = [];
add_filter('pre_http_request', function ($preempt, $args, $url) use (&$requests) {
    if (str_contains((string) $url, 'generativelanguage.googleapis.com')) {
        $request = json_decode((string) ($args['body'] ?? ''), true);
        $prompt = (string) ($request['contents'][0]['parts'][0]['text'] ?? '');
        preg_match('/Required translated_strings length: (\d+)/', $prompt, $count_matches);
        preg_match('/Target language ISO code: ([a-z]+)/', $prompt, $language_matches);
        $count = (int) ($count_matches[1] ?? 0);
        $language = (string) ($language_matches[1] ?? 'xx');
        $translations = [];
        for ($index = 0; $index < $count; $index++) {
            $translations[] = $index === 1 ? $language . '-privacy' : 'Translated ' . $language . ' ' . $index;
        }
        return [
            'headers' => [],
            'body' => wp_json_encode(['candidates' => [['content' => ['parts' => [['text' => wp_json_encode(['translated_strings' => $translations])]]]]]]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    if (!str_starts_with((string) $url, 'https://cronkite.test/')) return $preempt;
    $requests[] = ['url' => $url, 'body' => json_decode((string) ($args['body'] ?? ''), true)];
    return [
        'headers' => [],
        'body' => '',
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies' => [],
        'filename' => null,
    ];
}, 10, 3);

$draft_id = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'draft',
    'post_title' => 'Draft Page',
    'post_name' => 'draft-page',
], true);
spritz_page_translation_assert(spritz_ai_translation_enqueue_post((int) $draft_id) === 0, 'draft Pages must not enqueue translations');

$front_id = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'draft',
    'post_title' => 'Front Page',
    'post_name' => 'front-page',
], true);
update_option('page_on_front', (int) $front_id);
wp_update_post(['ID' => $front_id, 'post_status' => 'publish']);
spritz_page_translation_assert(spritz_ai_translation_enqueue_post((int) $front_id) === 0, 'configured front Page must remain excluded');

$homepage_id = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'draft',
    'post_title' => 'Homepage Template',
    'post_name' => 'homepage-template',
], true);
update_post_meta((int) $homepage_id, '_wp_page_template', 'template-homepage.php');
wp_update_post(['ID' => $homepage_id, 'post_status' => 'publish']);
spritz_page_translation_assert(spritz_ai_translation_enqueue_post((int) $homepage_id) === 0, 'homepage template must remain excluded');

$source_id = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'draft',
    'post_title' => 'Privacidad',
    'post_name' => 'privacidad',
    'post_excerpt' => 'Política de privacidad',
    'post_content' => '<!-- wp:paragraph --><p>Contenido legal.</p><!-- /wp:paragraph -->',
], true);
spritz_page_translation_assert(!is_wp_error($source_id), 'source Page creation failed');
update_post_meta((int) $source_id, '_spritz_language', 'es');
wp_update_post(['ID' => $source_id, 'post_status' => 'publish']);

$jobs = $wpdb->get_results('SELECT * FROM ' . spritz_ai_translation_table_name() . ' ORDER BY id', ARRAY_A) ?: [];
spritz_page_translation_assert(count($jobs) === 2, 'published standalone Page must queue every configured target exactly once');
foreach ($jobs as $job) {
    $request_body = json_decode((string) $job['request_body'], true);
    spritz_page_translation_assert(($request_body['entityType'] ?? '') === 'standalone-page', 'Page job must preserve standalone entity type');
}
spritz_page_translation_assert(spritz_ai_translation_enqueue_post((int) $source_id) === 0, 'replayed Page publication must be idempotent');
spritz_page_translation_assert(spritz_ai_translation_process_jobs(2) === 2, 'Page target jobs must process independently');

$siblings = get_posts([
    'post_type' => 'page',
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'meta_key' => '_spritz_original_post_id',
    'meta_value' => (string) $source_id,
]);
spritz_page_translation_assert(count($siblings) === 2, 'worker must publish translated siblings as native Pages');
$routes = [];
foreach ($siblings as $sibling) {
    spritz_page_translation_assert((int) $sibling->post_parent === 0, 'translated Pages must remain top-level');
    spritz_page_translation_assert(get_post_meta($sibling->ID, '_spritz_translation_machine_owned', true) === '1', 'translated Page must be machine-owned');
    $routes[] = spritz_build_canonical_page($sibling)['slug'];
}
sort($routes);
spritz_page_translation_assert($routes === ['/en/en-privacy', '/it/it-privacy'], 'translated Pages must use localized top-level routes');

$social_requests = array_filter($requests, fn ($request) => str_ends_with($request['url'], '/pipeline/social-delivery'));
spritz_page_translation_assert(count($social_requests) === 0, 'standalone translations must never enter social delivery');

spritz_ai_translation_assert_page_slug_available('available-page');
$collision_id = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Collision', 'post_name' => 'collision'], true);
try {
    spritz_ai_translation_assert_page_slug_available('collision');
    throw new RuntimeException('duplicate Page slug must fail closed');
} catch (RuntimeException $error) {
    spritz_page_translation_assert($error->getMessage() === 'Translated Page slug collides with an existing Page', 'duplicate route must report the collision');
}
try {
    spritz_ai_translation_assert_page_slug_available('homepage');
    throw new RuntimeException('reserved Page slug must fail closed');
} catch (RuntimeException $error) {
    spritz_page_translation_assert($error->getMessage() === 'Translated Page slug is empty or reserved', 'reserved route must report the rejection');
}

wp_update_post(['ID' => $source_id, 'post_content' => '<p>Revisión nueva.</p>']);
$queued = $wpdb->get_results("SELECT * FROM " . spritz_ai_translation_table_name() . " WHERE status='queued' ORDER BY id", ARRAY_A) ?: [];
spritz_page_translation_assert(count($queued) === 2, 'published Page update must queue a new revision for every target');
wp_update_post(['ID' => $source_id, 'post_content' => '<p>Revisión más nueva.</p>']);
foreach ($queued as $job) {
    $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . spritz_ai_translation_table_name() . ' WHERE id = %d', $job['id']));
    spritz_page_translation_assert($status === 'superseded', 'new Page revision must supersede stale queued work');
}

$requests = [];
wp_update_post(['ID' => $source_id, 'post_status' => 'draft']);
$unpublish = array_values(array_filter($requests, fn ($request) => str_ends_with($request['url'], '/pipeline/unpublish')));
spritz_page_translation_assert(count($unpublish) === 3, 'source unpublish must invalidate source and every localized sibling');
foreach ($unpublish as $request) {
    spritz_page_translation_assert(($request['body']['article']['layout'] ?? '') === 'standalone-page', 'unpublish must send the authoritative standalone layout');
    spritz_page_translation_assert(($request['body']['article']['status'] ?? '') === 'archived', 'unpublish must send a non-published state');
}
foreach ($siblings as $sibling) {
    spritz_page_translation_assert(get_post_status($sibling->ID) === 'draft', 'source unpublish must make localized siblings non-public');
}

fwrite(STDOUT, "standalone Page translation lifecycle e2e: ok\n");
