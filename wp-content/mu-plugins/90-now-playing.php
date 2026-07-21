<?php

/**
 * Spritz Now Playing API.
 *
 * Alcantara posts the current playback state here. Spritz stores the latest
 * state in WordPress and publishes it to the static content bucket.
 */

add_action('init', 'spritz_ensure_now_playing_json');

add_action('rest_api_init', function () {
    register_rest_route('spritz/v1', '/now-playing', [
        [
            'methods' => 'GET',
            'callback' => 'spritz_get_now_playing_response',
            'permission_callback' => '__return_true',
        ],
        [
            'methods' => 'POST',
            'callback' => 'spritz_update_now_playing',
            'permission_callback' => 'spritz_now_playing_can_update',
        ],
    ]);
});

function spritz_ensure_now_playing_json(): void {
    if (!function_exists('spritz_s3_bucket') || !spritz_s3_bucket()) {
        return;
    }

    if (get_transient('spritz_now_playing_json_exists')) {
        return;
    }

    if (spritz_now_playing_json_exists()) {
        set_transient('spritz_now_playing_json_exists', '1', MINUTE_IN_SECONDS);
        return;
    }

    $now_playing = spritz_get_now_playing();
    update_option('spritz_now_playing', $now_playing, false);

    if (spritz_publish_now_playing_json($now_playing)) {
        set_transient('spritz_now_playing_json_exists', '1', MINUTE_IN_SECONDS);
    }
}

function spritz_get_now_playing_response(WP_REST_Request $request) {
    spritz_ensure_now_playing_json();

    return rest_ensure_response(spritz_get_now_playing());
}

function spritz_update_now_playing(WP_REST_Request $request) {
    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        return new WP_REST_Response(['error' => 'Expected a JSON object.'], 400);
    }

    $now_playing = spritz_normalize_now_playing_payload($payload);
    update_option('spritz_now_playing', $now_playing, false);

    $published = spritz_publish_now_playing_json($now_playing);
    if (!$published) {
        return new WP_REST_Response([
            'ok' => false,
            'nowPlaying' => $now_playing,
            'error' => 'Now playing was stored, but static JSON could not be published.',
        ], 500);
    }

    return rest_ensure_response([
        'ok' => true,
        'nowPlaying' => $now_playing,
        'url' => spritz_now_playing_static_url(),
    ]);
}

function spritz_now_playing_can_update(WP_REST_Request $request) {
    $expected = defined('NOW_PLAYING_TOKEN') ? NOW_PLAYING_TOKEN : getenv('NOW_PLAYING_TOKEN');
    if (!$expected) {
        return new WP_Error(
            'spritz_now_playing_disabled',
            'Now playing updates are disabled because NOW_PLAYING_TOKEN is not configured.',
            ['status' => 503]
        );
    }

    $provided = spritz_now_playing_request_token($request);
    if (!$provided || !hash_equals((string) $expected, (string) $provided)) {
        return new WP_Error(
            'spritz_now_playing_forbidden',
            'Invalid now playing token.',
            ['status' => 403]
        );
    }

    return true;
}

function spritz_now_playing_request_token(WP_REST_Request $request): string {
    $authorization = (string) $request->get_header('authorization');
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim($matches[1]);
    }

    return trim((string) $request->get_header('x-spritz-now-playing-token'));
}

function spritz_get_now_playing(): array {
    $current = get_option('spritz_now_playing');
    if (is_array($current)) {
        return spritz_complete_now_playing($current);
    }

    return spritz_now_playing_placeholder();
}

function spritz_normalize_now_playing_payload(array $payload): array {
    $now = function_exists('spritz_iso_datetime') ? spritz_iso_datetime() : gmdate('Y-m-d\TH:i:s\Z');
    $status = spritz_now_playing_status($payload['status'] ?? null, $payload);
    $normalized = [
        'status' => $status,
        'station' => sanitize_key((string) ($payload['station'] ?? 'modoitaliano')) ?: 'modoitaliano',
        'source' => sanitize_key((string) ($payload['source'] ?? 'alcantara')) ?: 'alcantara',
        'updatedAt' => $now,
        'isPlaceholder' => false,
    ];

    foreach (['title', 'artist', 'album'] as $field) {
        if (array_key_exists($field, $payload)) {
            $value = sanitize_text_field((string) $payload[$field]);
            if ($value !== '') {
                $normalized[$field] = $value;
            }
        }
    }

    foreach (['artworkUrl', 'externalUrl'] as $field) {
        if (array_key_exists($field, $payload)) {
            $value = esc_url_raw((string) $payload[$field]);
            if ($value !== '') {
                $normalized[$field] = $value;
            }
        }
    }

    foreach (['startedAt', 'endedAt'] as $field) {
        if (array_key_exists($field, $payload)) {
            $value = spritz_now_playing_iso_datetime((string) $payload[$field]);
            if ($value !== '') {
                $normalized[$field] = $value;
            }
        }
    }

    if (array_key_exists('durationSeconds', $payload)) {
        $duration = (int) $payload['durationSeconds'];
        if ($duration > 0) {
            $normalized['durationSeconds'] = $duration;
        }
    }

    return spritz_complete_now_playing($normalized);
}

function spritz_now_playing_status($status, array $payload): string {
    $status = sanitize_key((string) $status);
    $allowed = ['playing', 'paused', 'stopped', 'unknown'];
    if (in_array($status, $allowed, true)) {
        return $status;
    }

    if (!empty($payload['title']) || !empty($payload['artist'])) {
        return 'playing';
    }

    return 'unknown';
}

function spritz_complete_now_playing(array $now_playing): array {
    $has_track = trim((string) ($now_playing['title'] ?? '')) !== '' || trim((string) ($now_playing['artist'] ?? '')) !== '';
    $status = sanitize_key((string) ($now_playing['status'] ?? 'unknown'));
    $is_placeholder = !$has_track || in_array($status, ['stopped', 'unknown'], true);

    if ($is_placeholder) {
        return array_merge(spritz_now_playing_placeholder(), [
            'station' => sanitize_key((string) ($now_playing['station'] ?? 'modoitaliano')) ?: 'modoitaliano',
            'source' => sanitize_key((string) ($now_playing['source'] ?? 'alcantara')) ?: 'alcantara',
            'status' => $status && in_array($status, ['paused', 'stopped', 'unknown'], true) ? $status : 'unknown',
            'updatedAt' => (string) ($now_playing['updatedAt'] ?? spritz_now_playing_now()),
        ]);
    }

    return array_merge([
        'status' => 'playing',
        'station' => 'modoitaliano',
        'source' => 'alcantara',
        'title' => '',
        'artist' => '',
        'album' => '',
        'artworkUrl' => '',
        'externalUrl' => spritz_now_playing_site_url(),
        'isPlaceholder' => false,
        'updatedAt' => spritz_now_playing_now(),
    ], $now_playing, [
        'isPlaceholder' => false,
    ]);
}

function spritz_now_playing_placeholder(): array {
    return [
        'status' => 'unknown',
        'station' => 'modoitaliano',
        'source' => 'alcantara',
        'title' => 'ModoItaliano',
        'artist' => 'In diretta',
        'album' => 'Radio',
        'artworkUrl' => '',
        'externalUrl' => spritz_now_playing_site_url(),
        'isPlaceholder' => true,
        'updatedAt' => spritz_now_playing_now(),
    ];
}

function spritz_now_playing_now(): string {
    return function_exists('spritz_iso_datetime') ? spritz_iso_datetime() : gmdate('Y-m-d\TH:i:s\Z');
}

function spritz_now_playing_site_url(): string {
    $base = getenv('PUBLIC_SITE_URL') ?: getenv('WP_PUBLIC_SITE_URL') ?: 'https://modoitaliano.fm';
    return rtrim($base, '/') . '/';
}

function spritz_now_playing_iso_datetime(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if (!$timestamp) {
        return '';
    }

    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
}

function spritz_publish_now_playing_json(array $now_playing): bool {
    if (!function_exists('spritz_s3_put_body') || !function_exists('spritz_s3_bucket') || !spritz_s3_bucket()) {
        return false;
    }

    return spritz_s3_put_body(
        'content/now-playing.json',
        wp_json_encode($now_playing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'application/json',
        'no-store'
    );
}

function spritz_now_playing_json_exists(): bool {
    if (!function_exists('spritz_s3_bucket') || !spritz_s3_bucket()) {
        return false;
    }

    $autoload = '/var/www/html/vendor/autoload.php';
    if (is_readable($autoload)) {
        require_once $autoload;
    }

    if (!class_exists(\Aws\S3\S3Client::class)) {
        return false;
    }

    $client_config = [
        'version' => 'latest',
        'region' => getenv('AWS_REGION') ?: 'us-east-1',
    ];

    $credentials_file = getenv('AWS_SHARED_CREDENTIALS_FILE') ?: '/var/www/.aws/credentials';
    if (is_readable($credentials_file)) {
        putenv('AWS_SHARED_CREDENTIALS_FILE=' . $credentials_file);
        $client_config['profile'] = getenv('AWS_PROFILE') ?: 'default';
    }

    putenv('AWS_EC2_METADATA_DISABLED=true');

    try {
        $client = new \Aws\S3\S3Client($client_config);
        $client->headObject([
            'Bucket' => spritz_s3_bucket(),
            'Key' => 'content/now-playing.json',
        ]);
        return true;
    } catch (Throwable $exception) {
        return false;
    }
}

function spritz_now_playing_static_url(): string {
    if (function_exists('spritz_s3_cloudfront_domain') && spritz_s3_cloudfront_domain()) {
        return 'https://' . spritz_s3_cloudfront_domain() . '/content/now-playing.json';
    }

    return get_site_url() . '/wp-json/spritz/v1/now-playing';
}
