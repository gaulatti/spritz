<?php

/**
 * Spritz rerender admin.
 *
 * Adds an admin-only tool for asking Cronkite to rerender generated pages after
 * layout/template changes.
 */

add_action('admin_menu', function () {
    add_management_page(
        __('Spritz Rerender', 'spritz'),
        __('Spritz Rerender', 'spritz'),
        'publish_posts',
        'spritz-rerender',
        'spritz_render_rerender_admin_page'
    );
});

function spritz_render_rerender_admin_page(): void {
    if (!current_user_can('publish_posts')) {
        wp_die(__('You do not have permission to rerender Spritz pages.', 'spritz'));
    }

    $result = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['spritz_rerender_nonce'])) {
        check_admin_referer('spritz_rerender', 'spritz_rerender_nonce');
        $result = spritz_handle_rerender_request();
    }

    $languages = spritz_rerender_supported_languages();
    $categories = get_categories(['hide_empty' => false]);
    $posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Spritz Rerender', 'spritz'); ?></h1>

        <?php if (is_array($result)) : ?>
            <div class="notice notice-<?php echo $result['ok'] ? 'success' : 'error'; ?> is-dismissible">
                <p><?php echo esc_html($result['message']); ?></p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('spritz_rerender', 'spritz_rerender_nonce'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Action', 'spritz'); ?></th>
                    <td>
                        <fieldset>
                            <label><input type="radio" name="spritz_rerender_action" value="all" checked> <?php esc_html_e('All pages from inventory', 'spritz'); ?></label><br>
                            <label><input type="radio" name="spritz_rerender_action" value="homepages"> <?php esc_html_e('Homepages only', 'spritz'); ?></label><br>
                            <label><input type="radio" name="spritz_rerender_action" value="categories"> <?php esc_html_e('Selected category fronts', 'spritz'); ?></label><br>
                            <label><input type="radio" name="spritz_rerender_action" value="articles"> <?php esc_html_e('Selected articles', 'spritz'); ?></label>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Languages', 'spritz'); ?></th>
                    <td>
                        <?php foreach ($languages as $language) : ?>
                            <label style="margin-right: 1em;">
                                <input type="checkbox" name="spritz_languages[]" value="<?php echo esc_attr($language); ?>" checked>
                                <?php echo esc_html($language); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Categories', 'spritz'); ?></th>
                    <td>
                        <select name="spritz_category_slugs[]" multiple size="8" style="min-width: 280px;">
                            <?php foreach ($categories as $category) : ?>
                                <?php if ($category->slug === 'uncategorized') continue; ?>
                                <option value="<?php echo esc_attr($category->slug); ?>"><?php echo esc_html($category->name . ' (' . $category->slug . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Used only for selected category fronts.', 'spritz'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Articles', 'spritz'); ?></th>
                    <td>
                        <select name="spritz_post_ids[]" multiple size="10" style="min-width: 520px;">
                            <?php foreach ($posts as $post) : ?>
                                <option value="<?php echo esc_attr((string) $post->ID); ?>"><?php echo esc_html(get_the_title($post) . ' — ' . spritz_rerender_article_slug($post)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Used only for selected articles.', 'spritz'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Trigger rerender', 'spritz')); ?>
        </form>
    </div>
    <?php
}

function spritz_handle_rerender_request(): array {
    $action = sanitize_key((string) ($_POST['spritz_rerender_action'] ?? 'all'));
    $languages = spritz_rerender_sanitize_list($_POST['spritz_languages'] ?? []);

    if (empty($languages)) {
        $languages = spritz_rerender_supported_languages();
    }

    spritz_rerender_refresh_static_json();

    if ($action === 'homepages') {
        return spritz_cronkite_post('/pipeline/rerender-homepages', ['languages' => $languages], __('Homepage rerender requested.', 'spritz'));
    }

    if ($action === 'categories') {
        $category_slugs = spritz_rerender_sanitize_list($_POST['spritz_category_slugs'] ?? []);
        if (empty($category_slugs)) {
            return ['ok' => false, 'message' => __('Choose at least one category.', 'spritz')];
        }

        return spritz_cronkite_post('/pipeline/rerender-pages', [
            'slugs' => $category_slugs,
            'languages' => $languages,
        ], __('Category rerender requested.', 'spritz'));
    }

    if ($action === 'articles') {
        $post_ids = array_map('intval', (array) ($_POST['spritz_post_ids'] ?? []));
        $count = 0;
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') continue;
            if (!function_exists('spritz_build_canonical_article')) continue;

            $result = spritz_cronkite_post('/pipeline/rerender', [
                'article' => spritz_build_canonical_article($post),
            ], '');

            if (!$result['ok']) {
                return $result;
            }

            $count++;
        }

        if ($count === 0) {
            return ['ok' => false, 'message' => __('Choose at least one published article.', 'spritz')];
        }

        return ['ok' => true, 'message' => sprintf(_n('%d article rerender requested.', '%d article rerenders requested.', $count, 'spritz'), $count)];
    }

    return spritz_cronkite_post('/pipeline/rerender-pages', [
        'languages' => $languages,
    ], __('Full inventory rerender requested.', 'spritz'));
}

function spritz_cronkite_post(string $path, array $payload, string $success_message): array {
    $cronkite_url = defined('CRONKITE_URL') ? CRONKITE_URL : getenv('CRONKITE_URL');
    $tenant_slug = defined('CRONKITE_TENANT_SLUG') ? CRONKITE_TENANT_SLUG : getenv('CRONKITE_TENANT_SLUG');
    $pipeline_token = defined('PIPELINE_TOKEN') ? PIPELINE_TOKEN : getenv('PIPELINE_TOKEN');

    if (!$cronkite_url || !$tenant_slug || !$pipeline_token) {
        return ['ok' => false, 'message' => __('Cronkite URL, tenant slug, or pipeline token is missing.', 'spritz')];
    }

    $response = wp_remote_post(rtrim($cronkite_url, '/') . $path, [
        'body' => wp_json_encode($payload),
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $pipeline_token,
            'x-tenant-id' => $tenant_slug,
        ],
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        return ['ok' => false, 'message' => $response->get_error_message()];
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'message' => sprintf('Cronkite returned HTTP %d: %s', $status, wp_remote_retrieve_body($response)),
        ];
    }

    return ['ok' => true, 'message' => $success_message];
}

function spritz_rerender_refresh_static_json(): void {
    if (!function_exists('spritz_static_json_refresh')) return;

    $latest = get_posts([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ]);

    if (!empty($latest)) {
        spritz_static_json_refresh($latest[0]->ID, $latest[0], true);
    }
}

function spritz_rerender_article_slug(WP_Post $post): string {
    $categories = function_exists('spritz_get_categories') ? spritz_get_categories($post->ID) : [];
    $category_slug = !empty($categories) ? $categories[0]['slug'] : 'news';
    $slug = '/' . ltrim(get_post_field('post_name', $post), '/');

    return strpos($slug, '/' . $category_slug) === 0 ? $slug : '/' . $category_slug . $slug;
}

function spritz_rerender_supported_languages(): array {
    if (function_exists('spritz_static_json_languages')) {
        return spritz_static_json_languages();
    }

    return ['en', 'es', 'it'];
}

function spritz_rerender_sanitize_list($values): array {
    $sanitized = [];
    foreach ((array) $values as $value) {
        $value = sanitize_title((string) wp_unslash($value));
        if ($value !== '') {
            $sanitized[] = $value;
        }
    }

    return array_values(array_unique($sanitized));
}
