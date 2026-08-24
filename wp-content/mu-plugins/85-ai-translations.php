<?php

/**
 * Spritz AI translations.
 *
 * Ports Auburndale's internal translation flow into WordPress:
 * settings -> durable jobs -> Gemini -> translated posts -> existing hooks.
 */

const SPRITZ_AI_TRANSLATION_OPTION = 'spritz_ai_translation_settings';
const SPRITZ_AI_TRANSLATION_TABLE_VERSION = '20260819_2';
const SPRITZ_AI_TRANSLATION_SOURCE_LANGUAGE = 'es';
const SPRITZ_AI_TRANSLATION_RETRY_DELAYS = [60, 300, 900, 3600];

final class SpritzAiTranslationManualEdit extends RuntimeException {}

add_action('init', 'spritz_ai_translation_bootstrap');
add_action('admin_menu', 'spritz_ai_translation_admin_menu');
add_action('add_meta_boxes_post', 'spritz_ai_translation_add_post_metabox');
add_action('add_meta_boxes_page', 'spritz_ai_translation_add_page_metabox');
add_action('save_post_post', 'spritz_ai_translation_save_post_meta', 5, 3);
add_action('save_post_page', 'spritz_ai_translation_save_post_meta', 5, 3);
add_action('save_post_post', 'spritz_ai_translation_enqueue_on_save', 30, 3);
add_action('save_post_page', 'spritz_ai_translation_enqueue_on_save', 30, 3);
add_action('save_post_post', 'spritz_ai_translation_mark_manual_edit', 40, 3);
add_action('save_post_page', 'spritz_ai_translation_mark_manual_edit', 40, 3);
add_action('transition_post_status', 'spritz_ai_translation_unpublish_page_transition', 10, 3);
add_action('before_delete_post', 'spritz_ai_translation_before_delete_page', 10, 2);
add_action('deleted_post', 'spritz_ai_translation_after_delete_page', 10, 2);

function spritz_ai_translation_bootstrap(): void {
    if (function_exists('is_blog_installed') && !is_blog_installed()) return;
    spritz_ai_translation_ensure_table();
}

function spritz_ai_translation_admin_menu(): void {
    add_management_page(
        __('Spritz Translations', 'spritz'),
        __('Spritz Translations', 'spritz'),
        'manage_options',
        'spritz-translations',
        'spritz_ai_translation_render_admin_page'
    );
}

function spritz_ai_translation_add_post_metabox(): void {
    add_meta_box(
        'spritz-ai-translation-meta',
        __('Spritz Translation', 'spritz'),
        'spritz_ai_translation_render_post_metabox',
        'post',
        'side',
        'default'
    );
}

function spritz_ai_translation_add_page_metabox(): void {
    add_meta_box(
        'spritz-ai-translation-meta',
        __('Spritz Translation', 'spritz'),
        'spritz_ai_translation_render_post_metabox',
        'page',
        'side',
        'default'
    );
}

function spritz_ai_translation_render_post_metabox(WP_Post $post): void {
    wp_nonce_field('spritz_ai_translation_post_meta', 'spritz_ai_translation_post_meta_nonce');
    $settings = spritz_ai_translation_settings();
    $language = function_exists('spritz_get_post_language') ? spritz_get_post_language($post->ID) : 'es';
    $languages = array_values(array_unique(array_merge(['es'], $settings['target_languages'], [$language])));
    $original_post_id = (int) get_post_meta($post->ID, '_spritz_original_post_id', true);
    ?>
    <p>
        <label for="spritz_language"><?php esc_html_e('Language', 'spritz'); ?></label>
        <select id="spritz_language" name="spritz_language" class="widefat">
            <?php foreach ($languages as $lang) : ?>
                <option value="<?php echo esc_attr($lang); ?>" <?php selected($language, $lang); ?>><?php echo esc_html($lang); ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="spritz_original_post_id"><?php esc_html_e('Original post ID', 'spritz'); ?></label>
        <input type="number" id="spritz_original_post_id" name="spritz_original_post_id" value="<?php echo esc_attr($original_post_id ? (string) $original_post_id : ''); ?>" class="widefat" min="1" />
    </p>
    <?php
}

function spritz_ai_translation_save_post_meta($post_id, $post, $update): void {
    if (!empty($GLOBALS['spritz_ai_translation_upserting'])) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (!isset($_POST['spritz_ai_translation_post_meta_nonce']) || !wp_verify_nonce((string) $_POST['spritz_ai_translation_post_meta_nonce'], 'spritz_ai_translation_post_meta')) return;
    if (!current_user_can('edit_post', (int) $post_id)) return;

    $language = sanitize_key((string) wp_unslash($_POST['spritz_language'] ?? ''));
    if ($language !== '') {
        update_post_meta((int) $post_id, '_spritz_language', $language);
        if (function_exists('pll_set_post_language')) {
            pll_set_post_language((int) $post_id, $language);
        }
    }

    $original_post_id = absint($_POST['spritz_original_post_id'] ?? 0);
    if ($original_post_id > 0 && $original_post_id !== (int) $post_id) {
        update_post_meta((int) $post_id, '_spritz_original_post_id', $original_post_id);
    } else {
        delete_post_meta((int) $post_id, '_spritz_original_post_id');
    }
}

function spritz_ai_translation_render_admin_page(): void {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to manage Spritz translations.', 'spritz'));
    }

    $notice = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['spritz_ai_translation_nonce'])) {
        check_admin_referer('spritz_ai_translation', 'spritz_ai_translation_nonce');
        $action = sanitize_key((string) ($_POST['spritz_ai_translation_action'] ?? 'save'));

        if ($action === 'save') {
            spritz_ai_translation_save_settings($_POST);
            $notice = ['type' => 'success', 'message' => __('Translation settings saved.', 'spritz')];
        } elseif ($action === 'retry') {
            $job_id = absint($_POST['spritz_translation_job_id'] ?? 0);
            $notice = spritz_ai_translation_retry_job($job_id)
                ? ['type' => 'success', 'message' => __('Translation job queued for retry.', 'spritz')]
                : ['type' => 'error', 'message' => __('Translation job is not retryable.', 'spritz')];
        } elseif ($action === 'enqueue_post') {
            $post_id = absint($_POST['spritz_translation_post_id'] ?? 0);
            $count = $post_id ? spritz_ai_translation_enqueue_post($post_id) : 0;
            $notice = [
                'type' => $count > 0 ? 'success' : 'warning',
                'message' => sprintf(__('Queued %d translation job(s).', 'spritz'), $count),
            ];
        }
    }

    $settings = spritz_ai_translation_settings();
    $jobs = spritz_ai_translation_recent_jobs();
    $stats = spritz_ai_translation_job_stats();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Spritz Translations', 'spritz'); ?></h1>

        <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                <p><?php echo esc_html($notice['message']); ?></p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('spritz_ai_translation', 'spritz_ai_translation_nonce'); ?>
            <input type="hidden" name="spritz_ai_translation_action" value="save" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable AI translation', 'spritz'); ?></th>
                    <td><label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?> /> <?php esc_html_e('Queue translations when eligible posts or standalone Pages are published.', 'spritz'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Auto-publish translations', 'spritz'); ?></th>
                    <td><label><input type="checkbox" name="auto_publish" value="1" <?php checked($settings['auto_publish']); ?> /> <?php esc_html_e('Create translated posts as published. Otherwise they are drafts.', 'spritz'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Gemini API key', 'spritz'); ?></th>
                    <td>
                        <strong><?php echo getenv('GEMINI_API_KEY') ? esc_html__('Configured by deployment', 'spritz') : esc_html__('Not configured', 'spritz'); ?></strong>
                        <p class="description"><?php esc_html_e('The credential is loaded from the deployment secret and is never stored in WordPress.', 'spritz'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gemini_model"><?php esc_html_e('Gemini model', 'spritz'); ?></label></th>
                    <td><input type="text" id="gemini_model" name="gemini_model" value="<?php echo esc_attr($settings['gemini_model']); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="target_languages"><?php esc_html_e('Target languages', 'spritz'); ?></label></th>
                    <td>
                        <input type="text" id="target_languages" name="target_languages" value="<?php echo esc_attr(implode(',', $settings['target_languages'])); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Comma-separated ISO codes. Initial targets are English and Italian.', 'spritz'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="request_timeout_ms"><?php esc_html_e('Request timeout', 'spritz'); ?></label></th>
                    <td><input type="number" id="request_timeout_ms" name="request_timeout_ms" value="<?php echo esc_attr((string) $settings['request_timeout_ms']); ?>" min="1000" step="1000" /> ms</td>
                </tr>
                <tr>
                    <th scope="row"><label for="batch_max_items"><?php esc_html_e('Batch max items', 'spritz'); ?></label></th>
                    <td><input type="number" id="batch_max_items" name="batch_max_items" value="<?php echo esc_attr((string) $settings['batch_max_items']); ?>" min="1" max="100" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="batch_max_characters"><?php esc_html_e('Batch max characters', 'spritz'); ?></label></th>
                    <td><input type="number" id="batch_max_characters" name="batch_max_characters" value="<?php echo esc_attr((string) $settings['batch_max_characters']); ?>" min="1000" step="1000" /></td>
                </tr>
            </table>
            <?php submit_button(__('Save translation settings', 'spritz')); ?>
        </form>

        <hr />

        <form method="post" style="display:inline-block;">
            <?php wp_nonce_field('spritz_ai_translation', 'spritz_ai_translation_nonce'); ?>
            <input type="hidden" name="spritz_ai_translation_action" value="enqueue_post" />
            <input type="number" name="spritz_translation_post_id" min="1" placeholder="<?php esc_attr_e('Post or Page ID', 'spritz'); ?>" />
            <?php submit_button(__('Queue content translations', 'spritz'), 'secondary', 'submit', false); ?>
        </form>

        <h2><?php esc_html_e('Jobs', 'spritz'); ?></h2>
        <p>
            <?php foreach ($stats as $status => $count) : ?>
                <strong><?php echo esc_html($status); ?>:</strong> <?php echo esc_html((string) $count); ?>&nbsp;
            <?php endforeach; ?>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'spritz'); ?></th>
                    <th><?php esc_html_e('Post', 'spritz'); ?></th>
                    <th><?php esc_html_e('Language', 'spritz'); ?></th>
                    <th><?php esc_html_e('Status', 'spritz'); ?></th>
                    <th><?php esc_html_e('Attempts', 'spritz'); ?></th>
                    <th><?php esc_html_e('Error', 'spritz'); ?></th>
                    <th><?php esc_html_e('Updated', 'spritz'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $job['id']); ?></td>
                        <td><?php echo esc_html((string) $job['source_post_id']); ?></td>
                        <td><?php echo esc_html($job['source_language'] . ' -> ' . $job['target_language']); ?></td>
                        <td><?php echo esc_html($job['status']); ?></td>
                        <td><?php echo esc_html((string) $job['attempts']); ?></td>
                        <td><?php echo esc_html(wp_trim_words((string) ($job['error'] ?? ''), 18)); ?></td>
                        <td><?php echo esc_html((string) $job['updated_at']); ?></td>
                        <td>
                            <?php if (in_array($job['status'], ['failed', 'manual'], true)) : ?>
                                <form method="post">
                                    <?php wp_nonce_field('spritz_ai_translation', 'spritz_ai_translation_nonce'); ?>
                                    <input type="hidden" name="spritz_ai_translation_action" value="retry" />
                                    <input type="hidden" name="spritz_translation_job_id" value="<?php echo esc_attr((string) $job['id']); ?>" />
                                    <?php submit_button($job['status'] === 'manual' ? __('Resume machine translation', 'spritz') : __('Retry', 'spritz'), 'small', 'submit', false); ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function spritz_ai_translation_default_settings(): array {
    return [
        'enabled' => false,
        'auto_publish' => false,
        'gemini_model' => 'gemini-3.6-flash',
        'target_languages' => ['en', 'it'],
        'request_timeout_ms' => 120000,
        'batch_max_items' => 75,
        'batch_max_characters' => 40000,
    ];
}

function spritz_ai_translation_settings(): array {
    $stored = get_option(SPRITZ_AI_TRANSLATION_OPTION, []);
    $settings = array_merge(spritz_ai_translation_default_settings(), is_array($stored) ? $stored : []);
    $settings['target_languages'] = spritz_ai_translation_normalize_languages($settings['target_languages']);
    $settings['enabled'] = !empty($settings['enabled']);
    $settings['auto_publish'] = !empty($settings['auto_publish']);
    $settings['request_timeout_ms'] = max(1000, (int) $settings['request_timeout_ms']);
    $settings['batch_max_items'] = max(1, min(100, (int) $settings['batch_max_items']));
    $settings['batch_max_characters'] = max(1000, (int) $settings['batch_max_characters']);
    $settings['gemini_model'] = sanitize_text_field((string) $settings['gemini_model']);
    return $settings;
}

function spritz_ai_translation_save_settings(array $input): void {
    $existing = spritz_ai_translation_settings();
    $settings = [
        'enabled' => !empty($input['enabled']),
        'auto_publish' => !empty($input['auto_publish']),
        'gemini_model' => sanitize_text_field((string) wp_unslash($input['gemini_model'] ?? $existing['gemini_model'])),
        'target_languages' => spritz_ai_translation_normalize_languages((string) wp_unslash($input['target_languages'] ?? '')),
        'request_timeout_ms' => max(1000, (int) ($input['request_timeout_ms'] ?? $existing['request_timeout_ms'])),
        'batch_max_items' => max(1, min(100, (int) ($input['batch_max_items'] ?? $existing['batch_max_items']))),
        'batch_max_characters' => max(1000, (int) ($input['batch_max_characters'] ?? $existing['batch_max_characters'])),
    ];
    update_option(SPRITZ_AI_TRANSLATION_OPTION, $settings, false);
}

function spritz_ai_translation_normalize_languages($value): array {
    $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
    $languages = [];
    foreach ($items ?: [] as $item) {
        $language = strtolower(sanitize_key((string) $item));
        if (!in_array($language, ['en', 'it'], true) || in_array($language, $languages, true)) continue;
        $languages[] = $language;
    }
    return $languages;
}

function spritz_ai_translation_enqueue_on_save($post_id, $post, $update): void {
    if (!empty($GLOBALS['spritz_ai_translation_upserting'])) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (!$post || $post->post_status !== 'publish') return;
    if (get_post_meta((int) $post_id, '_spritz_original_post_id', true)) return;

    spritz_ai_translation_enqueue_post((int) $post_id);
}

function spritz_ai_translation_enqueue_post(int $post_id): int {
    $settings = spritz_ai_translation_settings();
    if (!$settings['enabled']) return 0;

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return 0;
    if ($post->post_type !== 'post' && !spritz_ai_translation_is_eligible_page($post)) return 0;
    if (get_post_meta($post_id, '_spritz_original_post_id', true)) return 0;

    $source_language = function_exists('spritz_get_post_language') ? spritz_get_post_language($post_id) : 'es';
    if ($source_language !== SPRITZ_AI_TRANSLATION_SOURCE_LANGUAGE) return 0;
    $source_revision = spritz_ai_translation_source_revision($post);
    $request_body = spritz_ai_translation_build_request_body($post, $source_language);
    $queued = 0;

    foreach ($settings['target_languages'] as $target_language) {
        if ($target_language === $source_language) continue;
        if (spritz_ai_translation_enqueue_job($post_id, $source_language, $target_language, $source_revision, $request_body, $settings)) {
            $queued++;
        }
    }

    return $queued;
}

function spritz_ai_translation_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . 'spritz_translation_jobs';
}

function spritz_ai_translation_ensure_table(): void {
    if (get_option('spritz_ai_translation_table_version') === SPRITZ_AI_TRANSLATION_TABLE_VERSION) return;

    global $wpdb;
    $table = spritz_ai_translation_table_name();
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("
        CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            dedupe_key varchar(191) NOT NULL,
            source_post_id bigint(20) unsigned NOT NULL,
            source_language varchar(16) NOT NULL,
            target_language varchar(16) NOT NULL,
            source_revision varchar(64) NOT NULL,
            request_body longtext NOT NULL,
            provider_config longtext NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'queued',
            attempts int unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            leased_at datetime DEFAULT NULL,
            lease_token varchar(64) DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            sibling_post_id bigint(20) unsigned DEFAULT NULL,
            error text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dedupe_revision (dedupe_key, source_revision),
            KEY status_available (status, available_at, created_at),
            KEY dedupe_created (dedupe_key, created_at),
            KEY source_target (source_post_id, target_language)
        ) {$charset_collate};
    ");

    update_option('spritz_ai_translation_table_version', SPRITZ_AI_TRANSLATION_TABLE_VERSION, false);
}

function spritz_ai_translation_enqueue_job(int $source_post_id, string $source_language, string $target_language, string $source_revision, array $request_body, array $settings): bool {
    global $wpdb;
    spritz_ai_translation_ensure_table();
    $table = spritz_ai_translation_table_name();
    $now = current_time('mysql', true);
    $entity_type = ($request_body['entityType'] ?? 'article') === 'standalone-page' ? 'page' : 'post';
    $dedupe_key = implode(':', [$entity_type, $source_post_id, $target_language]);
    $provider_config = [
        'provider' => 'gemini',
        'gemini_model' => $settings['gemini_model'],
        'request_timeout_ms' => $settings['request_timeout_ms'],
        'batch_max_items' => $settings['batch_max_items'],
        'batch_max_characters' => $settings['batch_max_characters'],
        'auto_publish' => $settings['auto_publish'],
    ];

    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$table}
        (dedupe_key, source_post_id, source_language, target_language, source_revision, request_body, provider_config, status, attempts, available_at, created_at, updated_at)
        VALUES (%s, %d, %s, %s, %s, %s, %s, 'queued', 0, %s, %s, %s)",
        $dedupe_key,
        $source_post_id,
        $source_language,
        $target_language,
        $source_revision,
        wp_json_encode($request_body),
        wp_json_encode($provider_config),
        $now,
        $now,
        $now
    ));

    if ($inserted) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'superseded', lease_token = NULL, updated_at = %s WHERE dedupe_key = %s AND source_revision <> %s AND status = 'queued'",
            $now,
            $dedupe_key,
            $source_revision
        ));
    }

    return (bool) $inserted;
}

function spritz_ai_translation_build_request_body(WP_Post $post, string $source_language): array {
    $entity_type = $post->post_type === 'page' ? 'standalone-page' : 'article';
    return [
        'entityType' => $entity_type,
        'articleId' => $post->ID,
        'originalArticleId' => $post->ID,
        'article' => [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'slug' => $post->post_name,
            'excerpt' => spritz_get_manual_excerpt($post),
            'content' => $post->post_content,
            'language' => $source_language,
            'updatedAt' => spritz_iso_datetime(strtotime($post->post_modified_gmt)),
        ],
    ];
}

function spritz_ai_translation_source_revision(WP_Post $post): string {
    return hash('sha256', implode("\n", [
        (string) $post->post_modified_gmt,
        (string) $post->post_type,
        (string) $post->post_name,
        (string) $post->post_title,
        (string) $post->post_excerpt,
        (string) $post->post_content,
    ]));
}

function spritz_ai_translation_process_jobs(int $limit = 3): int {
    $settings = spritz_ai_translation_settings();
    if (!$settings['enabled']) return 0;

    $processed = 0;
    for ($i = 0; $i < $limit; $i++) {
        $job = spritz_ai_translation_lease_job();
        if (!$job) break;
        spritz_ai_translation_process_job($job);
        $processed++;
    }
    return $processed;
}

function spritz_ai_translation_lease_job(): ?array {
    global $wpdb;
    spritz_ai_translation_ensure_table();
    $table = spritz_ai_translation_table_name();
    $now = current_time('mysql', true);
    $stale = gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS);
    $lease_token = bin2hex(random_bytes(16));
    $locking_clause = spritz_ai_translation_supports_skip_locked()
        ? 'FOR UPDATE SKIP LOCKED'
        : 'FOR UPDATE';

    $wpdb->query('START TRANSACTION');
    try {
        $job_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
            WHERE ((status = 'queued' AND available_at <= %s) OR (status = 'leased' AND leased_at < %s))
            ORDER BY available_at ASC, created_at ASC
            LIMIT 1 {$locking_clause}",
            $now,
            $stale
        ));
        if (!$job_id) {
            $wpdb->query('COMMIT');
            return null;
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
            SET status = 'leased', attempts = attempts + 1, leased_at = %s, lease_token = %s, updated_at = %s, error = NULL
            WHERE id = %d",
            $now,
            $lease_token,
            $now,
            (int) $job_id
        ));
        if ($updated !== 1) {
            throw new RuntimeException('Unable to claim translation job');
        }
        $wpdb->query('COMMIT');
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        throw $error;
    }

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d AND lease_token = %s",
        (int) $job_id,
        $lease_token
    ), ARRAY_A) ?: null;
}

function spritz_ai_translation_supports_skip_locked(?string $version = null, ?string $server_info = null): bool {
    global $wpdb;
    $version ??= (string) $wpdb->db_version();
    if ($server_info === null) {
        $server_info = method_exists($wpdb, 'db_server_info')
            ? (string) $wpdb->db_server_info()
            : '';
    }
    $is_mariadb = stripos($server_info, 'mariadb') !== false;
    return version_compare($version, $is_mariadb ? '10.6.0' : '8.0.1', '>=');
}

function spritz_ai_translation_process_job(array $job): void {
    try {
        if (spritz_ai_translation_is_superseded($job) || !spritz_ai_translation_owns_lease($job)) {
            spritz_ai_translation_mark_job((int) $job['id'], 'superseded', '', null, (string) $job['lease_token']);
            return;
        }

        $request_body = json_decode((string) $job['request_body'], true);
        $provider_config = json_decode((string) $job['provider_config'], true);
        if (!is_array($request_body) || !is_array($provider_config)) {
            throw new RuntimeException('Translation job payload is invalid JSON');
        }
        $existing_id = spritz_ai_translation_find_existing_post((int) $job['source_post_id'], (string) $job['target_language']);
        if ($existing_id && get_post_meta($existing_id, '_spritz_translation_machine_owned', true) !== '1') {
            throw new SpritzAiTranslationManualEdit('Translated sibling was manually edited and cannot be overwritten');
        }
        if (!spritz_ai_translation_settings()['enabled']) {
            throw new RuntimeException('Translation engine disabled before provider call');
        }
        $provider_config['gemini_api_key'] = (string) getenv('GEMINI_API_KEY');

        $prepared = spritz_ai_translation_prepare($request_body, (string) $job['target_language']);
        $translated_strings = spritz_ai_translation_gemini_translate($prepared, $provider_config);
        $translated_payload = spritz_ai_translation_hydrate($prepared, $translated_strings);
        if (!spritz_ai_translation_settings()['enabled'] || spritz_ai_translation_is_superseded($job) || !spritz_ai_translation_owns_lease($job)) {
            throw new RuntimeException('Translation engine disabled or job superseded before persistence');
        }
        $translated_id = spritz_ai_translation_upsert_post($translated_payload, $provider_config, $job);
        spritz_ai_translation_mark_job((int) $job['id'], 'completed', '', null, (string) $job['lease_token'], $translated_id);
    } catch (Throwable $error) {
        spritz_ai_translation_fail_job(
            $job,
            $error->getMessage(),
            $error instanceof SpritzAiTranslationManualEdit
        );
    }
}

function spritz_ai_translation_owns_lease(array $job): bool {
    global $wpdb;
    $table = spritz_ai_translation_table_name();
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE id = %d AND status = 'leased' AND lease_token = %s",
        (int) $job['id'],
        (string) ($job['lease_token'] ?? '')
    )) === 1;
}

function spritz_ai_translation_is_superseded(array $job): bool {
    global $wpdb;
    $table = spritz_ai_translation_table_name();
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE dedupe_key = %s AND created_at > %s",
        (string) $job['dedupe_key'],
        (string) $job['created_at']
    ));
    return $count > 0;
}

function spritz_ai_translation_fail_job(array $job, string $message, bool $manual = false): void {
    $attempts = (int) ($job['attempts'] ?? 0);
    $retry_index = $attempts - 1;
    $retry = !$manual && array_key_exists($retry_index, SPRITZ_AI_TRANSLATION_RETRY_DELAYS);
    $delay = $retry ? SPRITZ_AI_TRANSLATION_RETRY_DELAYS[$retry_index] : 0;
    spritz_ai_translation_mark_job(
        (int) $job['id'],
        $manual ? 'manual' : ($retry ? 'queued' : 'failed'),
        $message,
        $retry ? gmdate('Y-m-d H:i:s', time() + $delay) : null,
        (string) ($job['lease_token'] ?? '')
    );
}

function spritz_ai_translation_mark_job(int $id, string $status, string $error = '', ?string $available_at = null, string $lease_token = '', ?int $sibling_post_id = null): void {
    global $wpdb;
    $table = spritz_ai_translation_table_name();
    $now = current_time('mysql', true);
    $sibling_sql = $sibling_post_id === null ? '' : ', sibling_post_id = %d';
    $sql = "UPDATE {$table} SET status = %s, error = %s, available_at = %s, completed_at = %s,
        lease_token = NULL, leased_at = NULL, updated_at = %s{$sibling_sql}
        WHERE id = %d";
    $args = [$status, $error !== '' ? $error : null, $available_at ?: $now, $status === 'completed' ? $now : null, $now];
    if ($sibling_post_id !== null) $args[] = $sibling_post_id;
    $args[] = $id;
    if ($lease_token !== '') {
        $sql .= ' AND lease_token = %s';
        $args[] = $lease_token;
    }
    $wpdb->query($wpdb->prepare($sql, ...$args));
}

function spritz_ai_translation_retry_job(int $id): bool {
    if (!$id) return false;
    global $wpdb;
    $table = spritz_ai_translation_table_name();
    $now = current_time('mysql', true);
    $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND status IN ('failed', 'manual')", $id), ARRAY_A);
    if (!$job) return false;
    if ($job['status'] === 'manual') {
        $sibling_id = spritz_ai_translation_find_existing_post((int) $job['source_post_id'], (string) $job['target_language']);
        if (!$sibling_id) return false;
        update_post_meta($sibling_id, '_spritz_translation_machine_owned', '1');
    }
    return (bool) $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET status = 'queued', attempts = 0, available_at = %s,
        leased_at = NULL, lease_token = NULL, completed_at = NULL, error = NULL, updated_at = %s
        WHERE id = %d AND status IN ('failed', 'manual')",
        $now,
        $now,
        $id
    ));
}

function spritz_ai_translation_prepare(array $payload, string $target_language): array {
    $article = is_array($payload['article'] ?? null) ? $payload['article'] : $payload;
    $paths = [];
    $strings = [];
    $push = function (array $path, $value, string $kind = 'text') use (&$paths, &$strings): void {
        if (!is_string($value) || trim($value) === '') return;
        $paths[] = $path;
        $strings[] = ['index' => count($strings), 'kind' => $kind, 'text' => $value];
    };

    $push(['article', 'title'], $article['title'] ?? null);
    $push(['article', 'slug'], $article['slug'] ?? null, 'slug');
    $push(['article', 'excerpt'], $article['excerpt'] ?? null);
    $push(['article', 'content'], $article['content'] ?? null, 'wordpress_block_markup');

    return [
        'entityType' => (string) ($payload['entityType'] ?? 'article'),
        'targetLanguage' => $target_language,
        'originalPayload' => $payload,
        'strings' => $strings,
        'paths' => $paths,
    ];
}

function spritz_ai_translation_hydrate(array $prepared, array $translated_strings): array {
    if (count($translated_strings) !== count($prepared['paths'])) {
        throw new RuntimeException(sprintf('Translation length mismatch: expected %d, received %d', count($prepared['paths']), count($translated_strings)));
    }

    $payload = $prepared['originalPayload'];
    foreach ($prepared['paths'] as $index => $path) {
        spritz_ai_translation_set_deep_value($payload, $path, (string) $translated_strings[$index]);
    }
    $payload['targetLanguage'] = $prepared['targetLanguage'];
    $payload['language'] = $prepared['targetLanguage'];
    if (isset($payload['article']) && is_array($payload['article'])) {
        $payload['article']['language'] = $prepared['targetLanguage'];
    }
    return $payload;
}

function spritz_ai_translation_set_deep_value(array &$target, array $path, string $value): void {
    $current =& $target;
    foreach ($path as $index => $segment) {
        if ($index === count($path) - 1) {
            $current[$segment] = $value;
            return;
        }
        if (!isset($current[$segment]) || !is_array($current[$segment])) {
            $current[$segment] = [];
        }
        $current =& $current[$segment];
    }
}

function spritz_ai_translation_gemini_translate(array $prepared, array $config): array {
    $api_key = trim((string) ($config['gemini_api_key'] ?? ''));
    if ($api_key === '') throw new RuntimeException('Gemini API key is not configured');

    $translations = [];
    foreach (spritz_ai_translation_batches($prepared['strings'], $config) as $batch) {
        $translations = array_merge($translations, spritz_ai_translation_gemini_translate_batch($prepared, $batch, $config));
    }
    return $translations;
}

function spritz_ai_translation_batches(array $items, array $config): array {
    $max_items = max(1, min(100, (int) ($config['batch_max_items'] ?? 75)));
    $max_characters = max(1000, (int) ($config['batch_max_characters'] ?? 40000));
    $batches = [];
    $current = [];
    $characters = 0;

    foreach ($items as $item) {
        $length = strlen((string) ($item['text'] ?? ''));
        if ($current && (count($current) >= $max_items || $characters + $length > $max_characters)) {
            $batches[] = $current;
            $current = [];
            $characters = 0;
        }
        $current[] = $item;
        $characters += $length;
    }
    if ($current) $batches[] = $current;
    return $batches;
}

function spritz_ai_translation_gemini_translate_batch(array $prepared, array $items, array $config): array {
    if (empty($items)) return [];

    $model = trim((string) ($config['gemini_model'] ?? 'gemini-3.6-flash'));
    $timeout = max(1, (int) ceil(((int) ($config['request_timeout_ms'] ?? 120000)) / 1000));
    $count = count($items);
    $prompt = implode("\n", [
        'You are a professional translator.',
        'Translate every supplied item into the requested target language.',
        '',
        'Rules:',
        '1. Return one translated string for every input item.',
        '2. Preserve the exact input order; do not omit, merge, split, or add items.',
        '3. Preserve meaning, tone, punctuation, names, URLs, identifiers, placeholders, variables, and formatting.',
        '4. For kind "slug", return lowercase ASCII kebab-case containing only a-z, 0-9, and hyphens.',
        '5. For kind "wordpress_block_markup", preserve WordPress block comments and HTML tags; translate only human-readable text.',
        '6. Return only the requested structured JSON.',
        '',
        'Target language ISO code: ' . $prepared['targetLanguage'],
        'Entity type: ' . $prepared['entityType'],
        'Required translated_strings length: ' . $count,
        '',
        'Items:',
        wp_json_encode(array_values($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    ]);

    $response = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
        [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => (string) $config['gemini_api_key'],
            ],
            'body' => wp_json_encode([
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'maxOutputTokens' => 16384,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'translated_strings' => [
                                'type' => 'ARRAY',
                                'minItems' => $count,
                                'maxItems' => $count,
                                'items' => ['type' => 'STRING'],
                            ],
                        ],
                        'required' => ['translated_strings'],
                    ],
                ],
            ]),
        ]
    );

    if (is_wp_error($response)) {
        throw new RuntimeException($response->get_error_message());
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $envelope = json_decode($body, true);
    if (!is_array($envelope)) {
        throw new RuntimeException('Gemini returned non-JSON response (' . $status . ')');
    }
    if ($status < 200 || $status >= 300) {
        $message = $envelope['error']['message'] ?? wp_remote_retrieve_response_message($response);
        throw new RuntimeException('Gemini request failed (' . $status . '): ' . $message);
    }

    $response_text = $envelope['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!is_string($response_text) || trim($response_text) === '') {
        throw new RuntimeException('Gemini response contained no translation');
    }

    $parsed = json_decode($response_text, true);
    if (!is_array($parsed) || !isset($parsed['translated_strings']) || !is_array($parsed['translated_strings'])) {
        throw new RuntimeException('Gemini response omitted translated_strings');
    }
    if (count($parsed['translated_strings']) !== $count) {
        throw new RuntimeException(sprintf('Gemini returned %d translations; expected %d', count($parsed['translated_strings']), $count));
    }
    foreach ($parsed['translated_strings'] as $translation) {
        if (!is_string($translation)) {
            throw new RuntimeException('Gemini returned a non-string translation');
        }
    }
    return $parsed['translated_strings'];
}

function spritz_ai_translation_upsert_post(array $payload, array $config, array $job): int {
    $article = is_array($payload['article'] ?? null) ? $payload['article'] : $payload;
    $source_post_id = absint($payload['originalArticleId'] ?? $payload['articleId'] ?? 0);
    $target_language = sanitize_key((string) ($payload['targetLanguage'] ?? $payload['language'] ?? $article['language'] ?? ''));
    if (!$source_post_id || $target_language === '') {
        throw new RuntimeException('Translated payload is missing source post or target language');
    }

    $source = get_post($source_post_id);
    if (!$source) throw new RuntimeException('Source post not found');
    $post_type = $source->post_type === 'page' ? 'page' : 'post';
    if ($post_type === 'page' && !spritz_ai_translation_is_eligible_page($source)) {
        throw new RuntimeException('Source Page is not eligible for standalone translation');
    }

    $existing_id = spritz_ai_translation_find_existing_post($source_post_id, $target_language);
    if ($existing_id && get_post_meta($existing_id, '_spritz_translation_machine_owned', true) !== '1') {
        throw new RuntimeException('Translated sibling was manually edited and cannot be overwritten');
    }
    $status = !empty($config['auto_publish']) ? 'publish' : 'draft';
    $slug = sanitize_title((string) ($article['slug'] ?? $source->post_name . '-' . $target_language));
    if ($slug === '') $slug = $source->post_name . '-' . $target_language;
    if ($post_type === 'page') {
        spritz_ai_translation_assert_page_slug_available($slug, $existing_id);
    } else {
        $slug = wp_unique_post_slug($slug, $existing_id, $status, 'post', 0);
    }

    $post_data = [
        'post_type' => $post_type,
        'post_status' => $existing_id ? get_post_status($existing_id) : 'draft',
        'post_title' => sanitize_text_field((string) ($article['title'] ?? $source->post_title)),
        'post_name' => $slug,
        'post_excerpt' => wp_kses_post((string) ($article['excerpt'] ?? $source->post_excerpt)),
        'post_content' => (string) ($article['content'] ?? $source->post_content),
        'post_author' => (int) $source->post_author,
    ];
    if ($post_type === 'page') $post_data['post_parent'] = 0;
    if ($existing_id) $post_data['ID'] = $existing_id;

    $GLOBALS['spritz_ai_translation_upserting'] = true;

    try {
        $translated_id = $existing_id ? wp_update_post($post_data, true) : wp_insert_post($post_data, true);
        if (is_wp_error($translated_id)) {
            throw new RuntimeException($translated_id->get_error_message());
        }

        update_post_meta($translated_id, '_spritz_original_post_id', $source_post_id);
        update_post_meta($translated_id, '_spritz_language', $target_language);
        update_post_meta($translated_id, '_spritz_translation_machine_owned', '1');
        update_post_meta($translated_id, '_spritz_translation_source_revision', (string) $job['source_revision']);

        $source_thumbnail = get_post_thumbnail_id($source_post_id);
        if ($source_thumbnail) set_post_thumbnail($translated_id, $source_thumbnail);
        if ($post_type === 'post') {
            wp_set_post_categories($translated_id, wp_get_post_categories($source_post_id), false);
            wp_set_post_tags($translated_id, wp_get_post_tags($source_post_id, ['fields' => 'names']), false);
        }

        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($translated_id, $target_language);
            $source_language = function_exists('spritz_get_post_language') ? spritz_get_post_language($source_post_id) : '';
            if ($source_language !== '' && function_exists('pll_save_post_translations')) {
                pll_save_post_translations(array_filter([
                    $source_language => $source_post_id,
                    $target_language => $translated_id,
                ]));
            }
        }

        if ($status === 'publish' && get_post_status($translated_id) !== 'publish') {
            $published_id = wp_update_post(['ID' => $translated_id, 'post_status' => 'publish'], true);
            if (is_wp_error($published_id)) {
                throw new RuntimeException($published_id->get_error_message());
            }
        }
    } finally {
        $GLOBALS['spritz_ai_translation_upserting'] = false;
    }

    return (int) $translated_id;
}

function spritz_ai_translation_mark_manual_edit($post_id, $post, $update): void {
    if (!empty($GLOBALS['spritz_ai_translation_upserting']) || !$update) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (!get_post_meta((int) $post_id, '_spritz_original_post_id', true)) return;
    update_post_meta((int) $post_id, '_spritz_translation_machine_owned', '0');
}

function spritz_ai_translation_find_existing_post(int $source_post_id, string $target_language): int {
    $source = get_post($source_post_id);
    $post_type = $source && $source->post_type === 'page' ? 'page' : 'post';
    $query = new WP_Query([
        'post_type' => $post_type,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => [
            'relation' => 'AND',
            ['key' => '_spritz_original_post_id', 'value' => (string) $source_post_id],
            ['key' => '_spritz_language', 'value' => $target_language],
        ],
    ]);
    return !empty($query->posts) ? (int) $query->posts[0] : 0;
}

function spritz_ai_translation_is_eligible_page($post): bool {
    $post = get_post($post);
    return $post && $post->post_type === 'page'
        && function_exists('spritz_is_standalone_page')
        && spritz_is_standalone_page($post);
}

function spritz_ai_translation_assert_page_slug_available(string $slug, int $existing_id = 0): void {
    $slug = sanitize_title($slug);
    if ($slug === '' || in_array($slug, ['homepage', 'instagram', 'media', 'search'], true)) {
        throw new RuntimeException('Translated Page slug is empty or reserved');
    }
    $collision = get_page_by_path($slug, OBJECT, 'page');
    if ($collision && (int) $collision->ID !== $existing_id) {
        throw new RuntimeException('Translated Page slug collides with an existing Page');
    }
}

function spritz_ai_translation_unpublish_page_transition(string $new_status, string $old_status, $post): void {
    if ($old_status !== 'publish' || $new_status === 'publish' || !spritz_ai_translation_is_eligible_page($post)) return;
    spritz_ai_translation_unpublish_page_family($post);
}

function spritz_ai_translation_before_delete_page(int $post_id, $post): void {
    if (!$post || $post->post_status !== 'publish' || !spritz_ai_translation_is_eligible_page($post)) return;
    spritz_ai_translation_unpublish_page_family($post);
}

function spritz_ai_translation_after_delete_page(int $post_id, $post): void {
    if (!$post || $post->post_type !== 'page') return;
    if (function_exists('spritz_refresh_static_inventory')) spritz_refresh_static_inventory();
}

function spritz_ai_translation_unpublish_page_family($post): void {
    if (!empty($GLOBALS['spritz_ai_translation_invalidating'])) return;
    $post = get_post($post);
    if (!$post) return;

    $is_translation = (int) get_post_meta($post->ID, '_spritz_original_post_id', true) > 0;
    $documents = [$post];
    if (!$is_translation) {
        $siblings = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_key' => '_spritz_original_post_id',
            'meta_value' => (string) $post->ID,
        ]);
        $documents = array_merge($documents, $siblings);
    }

    $GLOBALS['spritz_ai_translation_invalidating'] = true;
    try {
        foreach ($documents as $document) {
            spritz_pipeline_unpublish_document($document);
            if ((int) $document->ID !== (int) $post->ID && get_post_status($document) === 'publish') {
                wp_update_post(['ID' => $document->ID, 'post_status' => 'draft']);
            }
        }
        if (function_exists('spritz_refresh_static_inventory')) spritz_refresh_static_inventory();
    } finally {
        $GLOBALS['spritz_ai_translation_invalidating'] = false;
    }
}

function spritz_ai_translation_recent_jobs(): array {
    global $wpdb;
    spritz_ai_translation_ensure_table();
    $table = spritz_ai_translation_table_name();
    return $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 50", ARRAY_A) ?: [];
}

function spritz_ai_translation_job_stats(): array {
    global $wpdb;
    spritz_ai_translation_ensure_table();
    $table = spritz_ai_translation_table_name();
    $rows = $wpdb->get_results("SELECT status, COUNT(*) AS count FROM {$table} GROUP BY status", ARRAY_A) ?: [];
    $stats = ['queued' => 0, 'leased' => 0, 'completed' => 0, 'failed' => 0, 'manual' => 0, 'superseded' => 0];
    foreach ($rows as $row) {
        $status = (string) $row['status'];
        if (array_key_exists($status, $stats)) {
            $stats[$status] = (int) $row['count'];
        }
    }
    return $stats;
}
