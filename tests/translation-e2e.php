<?php

function spritz_test_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function spritz_test_jobs(): array {
    global $wpdb;
    return $wpdb->get_results('SELECT * FROM ' . spritz_ai_translation_table_name() . ' ORDER BY id', ARRAY_A) ?: [];
}

function spritz_test_set_settings(bool $enabled = true): void {
    update_option(SPRITZ_AI_TRANSLATION_OPTION, [
        'enabled' => $enabled,
        'auto_publish' => true,
        'gemini_model' => 'test-model',
        'target_languages' => ['en', 'it'],
        'request_timeout_ms' => 1000,
        'batch_max_items' => 75,
        'batch_max_characters' => 40000,
    ], false);
}

global $wpdb;
spritz_ai_translation_ensure_table();
$wpdb->query('DELETE FROM ' . spritz_ai_translation_table_name());
spritz_test_set_settings();

spritz_test_assert(!spritz_ai_translation_supports_skip_locked('5.7.44', 'MySQL Community Server'), 'MySQL 5.7 must use the blocking transactional lease fallback');
spritz_test_assert(spritz_ai_translation_supports_skip_locked('8.0.1', 'MySQL Community Server'), 'MySQL 8.0.1 must support SKIP LOCKED');
spritz_test_assert(!spritz_ai_translation_supports_skip_locked('10.5.28', '10.5.28-MariaDB'), 'MariaDB 10.5 must use the blocking transactional lease fallback');
spritz_test_assert(spritz_ai_translation_supports_skip_locked('10.6.0', '10.6.0-MariaDB'), 'MariaDB 10.6 must support SKIP LOCKED');

$http_calls = 0;
add_filter('pre_http_request', function ($preempt, $args, $url) use (&$http_calls) {
    if (!str_contains((string) $url, 'generativelanguage.googleapis.com')) return $preempt;
    $http_calls++;
    $request = json_decode((string) ($args['body'] ?? ''), true);
    $prompt = (string) ($request['contents'][0]['parts'][0]['text'] ?? '');
    preg_match('/Required translated_strings length: (\d+)/', $prompt, $matches);
    $count = (int) ($matches[1] ?? 0);
    $translations = [];
    for ($index = 0; $index < $count; $index++) {
        $translations[] = $index === 1 ? 'translated-story' : 'Translated ' . $index;
    }
    return [
        'headers' => [],
        'body' => wp_json_encode(['candidates' => [['content' => ['parts' => [['text' => wp_json_encode(['translated_strings' => $translations])]]]]]]),
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies' => [],
        'filename' => null,
    ];
}, 10, 3);

$source_id = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'draft',
    'post_title' => 'Historia fuente',
    'post_name' => 'historia-fuente',
    'post_excerpt' => 'Resumen fuente',
    'post_content' => '<!-- wp:paragraph --><p>Contenido fuente.</p><!-- /wp:paragraph -->',
], true);
spritz_test_assert(!is_wp_error($source_id), 'source post creation failed');
update_post_meta((int) $source_id, '_spritz_language', 'es');
wp_update_post(['ID' => $source_id, 'post_status' => 'publish']);

$jobs = spritz_test_jobs();
spritz_test_assert(count($jobs) === 2, 'source publication must enqueue exactly two jobs');
spritz_test_assert(array_column($jobs, 'target_language') === ['en', 'it'], 'targets must be English and Italian');
spritz_test_assert(spritz_ai_translation_enqueue_post((int) $source_id) === 0, 'replayed publication must deduplicate jobs');

$first = spritz_ai_translation_lease_job();
$second = spritz_ai_translation_lease_job();
$third = spritz_ai_translation_lease_job();
spritz_test_assert($first && $second && !$third, 'atomic claims must lease each eligible job once');
spritz_test_assert($first['lease_token'] !== $second['lease_token'], 'claims must have unique lease tokens');
$wpdb->query('UPDATE ' . spritz_ai_translation_table_name() . " SET status='queued', attempts=0, leased_at=NULL, lease_token=NULL");

spritz_test_assert(spritz_ai_translation_process_jobs(2) === 2, 'worker must process both language jobs independently');
$siblings = get_posts([
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'meta_key' => '_spritz_original_post_id',
    'meta_value' => (string) $source_id,
]);
spritz_test_assert(count($siblings) === 2, 'worker must publish exactly two translated siblings');
$sibling_ids = [];
foreach ($siblings as $sibling) {
    $language = get_post_meta($sibling->ID, '_spritz_language', true);
    $sibling_ids[$language] = $sibling->ID;
    spritz_test_assert(get_post_meta($sibling->ID, '_spritz_translation_machine_owned', true) === '1', 'new sibling must be machine-owned');
}
spritz_test_assert(isset($sibling_ids['en'], $sibling_ids['it']), 'published siblings must retain canonical language metadata');

wp_update_post(['ID' => $source_id, 'post_content' => '<p>Nueva revisión.</p>']);
$queued = array_values(array_filter(spritz_test_jobs(), fn ($job) => $job['status'] === 'queued'));
spritz_test_assert(count($queued) === 2, 'published source update must enqueue a new revision for each target');
spritz_ai_translation_process_jobs(2);
spritz_test_assert(spritz_ai_translation_find_existing_post((int) $source_id, 'en') === $sibling_ids['en'], 'refresh must reuse the English sibling');
spritz_test_assert(spritz_ai_translation_find_existing_post((int) $source_id, 'it') === $sibling_ids['it'], 'refresh must reuse the Italian sibling');

wp_update_post(['ID' => $source_id, 'post_content' => '<p>Obsolete queued revision.</p>']);
$obsolete_jobs = array_values(array_filter(spritz_test_jobs(), fn ($job) => $job['status'] === 'queued'));
wp_update_post(['ID' => $source_id, 'post_content' => '<p>Newest queued revision.</p>']);
foreach ($obsolete_jobs as $obsolete_job) {
    $obsolete_status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . spritz_ai_translation_table_name() . ' WHERE id = %d', $obsolete_job['id']));
    spritz_test_assert($obsolete_status === 'superseded', 'newer source revision must supersede obsolete queued work');
}
spritz_ai_translation_process_jobs(2);

wp_update_post(['ID' => $source_id, 'post_content' => '<p>Kill-switch revision.</p>']);
$provider_calls_before_kill = $http_calls;
$kill_job = spritz_ai_translation_lease_job();
spritz_test_set_settings(false);
spritz_ai_translation_process_job($kill_job);
spritz_test_assert($http_calls === $provider_calls_before_kill, 'disabled translation must not call Gemini');
$kill_status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . spritz_ai_translation_table_name() . ' WHERE id = %d', $kill_job['id']));
spritz_test_assert($kill_status === 'queued', 'kill-switched work must remain durable and retryable');
spritz_test_set_settings(true);
$wpdb->query($wpdb->prepare(
    "UPDATE " . spritz_ai_translation_table_name() . " SET status='queued', attempts=0, available_at=%s, leased_at=NULL, lease_token=NULL WHERE id=%d",
    current_time('mysql', true),
    $kill_job['id']
));
spritz_ai_translation_process_jobs(2);

wp_update_post(['ID' => $sibling_ids['en'], 'post_title' => 'Human edited title']);
spritz_test_assert(get_post_meta($sibling_ids['en'], '_spritz_translation_machine_owned', true) === '0', 'human edit must revoke machine ownership');
wp_update_post(['ID' => $source_id, 'post_content' => '<p>Manual-ownership revision.</p>']);
spritz_ai_translation_process_jobs(2);
$latest = array_slice(spritz_test_jobs(), -2);
$latest_by_language = array_column($latest, null, 'target_language');
spritz_test_assert($latest_by_language['en']['status'] === 'manual', 'manual sibling must be skipped visibly');
spritz_test_assert($latest_by_language['it']['status'] === 'completed', 'one language failure must not block another');
spritz_test_assert(get_the_title($sibling_ids['en']) === 'Human edited title', 'machine refresh must not overwrite a human edit');

$manual_job = $latest_by_language['en'];
spritz_test_assert(spritz_ai_translation_retry_job((int) $manual_job['id']), 'terminal/manual jobs must be manually retryable');
spritz_test_assert(get_post_meta($sibling_ids['en'], '_spritz_translation_machine_owned', true) === '1', 'explicit manual retry must resume machine ownership');
$leased = spritz_ai_translation_lease_job();
spritz_test_assert((int) $leased['id'] === (int) $manual_job['id'], 'manual retry must return to the durable queue');
$wpdb->update(spritz_ai_translation_table_name(), ['leased_at' => gmdate('Y-m-d H:i:s', time() - 700)], ['id' => (int) $leased['id']]);
$recovered = spritz_ai_translation_lease_job();
spritz_test_assert((int) $recovered['id'] === (int) $leased['id'], 'stale worker lease must be recoverable');
spritz_test_assert($recovered['lease_token'] !== $leased['lease_token'], 'stale recovery must invalidate the old lease token');

$wpdb->update(spritz_ai_translation_table_name(), ['attempts' => 1], ['id' => (int) $recovered['id']]);
$recovered['attempts'] = 1;
spritz_ai_translation_fail_job($recovered, 'provider response mentioned manually edited content');
$retry_job = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . spritz_ai_translation_table_name() . ' WHERE id = %d', $recovered['id']), ARRAY_A);
spritz_test_assert($retry_job['status'] === 'queued', 'message text alone must not classify a provider failure as a manual edit');
$delay = strtotime($retry_job['available_at'] . ' UTC') - time();
spritz_test_assert($delay >= 58 && $delay <= 62, 'first retry delay must be one minute');
spritz_test_assert(SPRITZ_AI_TRANSLATION_RETRY_DELAYS === [60, 300, 900, 3600], 'retry schedule must be 1m/5m/15m/1h');

$wpdb->update(spritz_ai_translation_table_name(), ['status' => 'leased', 'attempts' => 5, 'lease_token' => 'terminal-test'], ['id' => (int) $recovered['id']]);
$retry_job['attempts'] = 5;
$retry_job['lease_token'] = 'terminal-test';
spritz_ai_translation_fail_job($retry_job, 'provider failure');
$terminal_status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . spritz_ai_translation_table_name() . ' WHERE id = %d', $recovered['id']));
spritz_test_assert($terminal_status === 'failed', 'job must become visibly terminal after the 1m/5m/15m/1h retries');

$stored_settings = get_option(SPRITZ_AI_TRANSLATION_OPTION);
spritz_test_assert(!array_key_exists('gemini_api_key', is_array($stored_settings) ? $stored_settings : []), 'Gemini credential must never be stored in WordPress options');
spritz_test_assert($http_calls >= 5, 'provider stub must be called for successful localized translations');

fwrite(STDOUT, "translation lifecycle e2e: ok\n");
