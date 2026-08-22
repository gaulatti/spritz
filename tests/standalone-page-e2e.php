<?php

function spritz_page_test_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

putenv('CRONKITE_URL=https://cronkite.test');
putenv('CRONKITE_TENANT_SLUG=modoitaliano');
putenv('PIPELINE_TOKEN=test-only-token');
putenv('PUBLIC_SITE_URL=https://modoitaliano.fm');

remove_action('save_post', 'spritz_publish_static_json_to_s3', 15);

$requests = [];
add_filter('pre_http_request', function ($preempt, $args, $url) use (&$requests) {
    if (!str_starts_with((string) $url, 'https://cronkite.test/')) return $preempt;
    $requests[] = ['url' => $url, 'body' => json_decode((string) ($args['body'] ?? ''), true)];
    return [
        'headers' => [],
        'body' => '',
        'response' => ['code' => 202, 'message' => 'Accepted'],
        'cookies' => [],
        'filename' => null,
    ];
}, 10, 3);

$page_id = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'Quienes somos?',
    'post_name' => 'quienes-somos',
    'post_content' => '<!-- wp:paragraph --><p>Somos ModoItaliano.</p><!-- /wp:paragraph -->',
], true);

spritz_page_test_assert(!is_wp_error($page_id), 'standalone page creation failed');
$page = get_post((int) $page_id);
$document = spritz_build_canonical_document($page);

spritz_page_test_assert($document['layout'] === 'standalone-page', 'WordPress Pages must use the standalone-page layout');
spritz_page_test_assert($document['slug'] === '/quienes-somos', 'standalone page route must remain top-level');
spritz_page_test_assert($document['canonicalUrl'] === 'https://modoitaliano.fm/quienes-somos', 'canonical URL must use the public site');
spritz_page_test_assert($document['categories'] === [], 'standalone pages must not receive the News fallback category');

$pipeline_requests = array_values(array_filter($requests, fn ($request) => str_ends_with($request['url'], '/pipeline/rerender')));
$social_requests = array_values(array_filter($requests, fn ($request) => str_ends_with($request['url'], '/pipeline/social-delivery')));
spritz_page_test_assert(count($pipeline_requests) === 1, 'publishing a page must request one Cronkite render');
spritz_page_test_assert(count($social_requests) === 0, 'publishing a page must not trigger social delivery');
spritz_page_test_assert(($pipeline_requests[0]['body']['article']['layout'] ?? '') === 'standalone-page', 'Cronkite payload must preserve the standalone layout');
spritz_page_test_assert(($pipeline_requests[0]['body']['skipAggregations'] ?? false) === true, 'standalone rendering must skip article aggregations');

$rest_request = new WP_REST_Request('GET');
$rest_request->set_param('slug', 'quienes-somos');
$rest_response = rest_ensure_response(spritz_get_page_json($rest_request));
spritz_page_test_assert($rest_response->get_status() === 200, 'standalone canonical JSON endpoint must resolve the page');
spritz_page_test_assert(($rest_response->get_data()['layout'] ?? '') === 'standalone-page', 'page JSON endpoint must return the standalone layout');

$inventory = rest_ensure_response(spritz_get_inventory_json(new WP_REST_Request('GET')))->get_data();
$inventory_matches = array_values(array_filter(
    $inventory['documents'] ?? [],
    fn ($item) => ($item['route'] ?? '') === '/quienes-somos'
));
spritz_page_test_assert(count($inventory_matches) === 1, 'published standalone page must be present exactly once in inventory');
spritz_page_test_assert(($inventory_matches[0]['type'] ?? '') === 'standalone-page', 'inventory must identify standalone pages explicitly');
spritz_page_test_assert(str_contains((string) ($inventory_matches[0]['url'] ?? ''), '/json/pages/quienes-somos.json'), 'inventory must reference standalone page JSON');

fwrite(STDOUT, "standalone page publishing e2e: ok\n");
