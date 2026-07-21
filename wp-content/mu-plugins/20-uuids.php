<?php

/**
 * Stable public UUIDs for canonical Spritz content.
 */

function spritz_post_uuid($post): string {
    $post = get_post($post);
    if (!$post) {
        return spritz_uuid_v4();
    }

    $uuid = (string) get_post_meta($post->ID, '_spritz_uuid', true);
    if (spritz_is_uuid($uuid)) {
        return $uuid;
    }

    $uuid = spritz_uuid_v5('spritz:post:' . $post->ID);
    update_post_meta($post->ID, '_spritz_uuid', $uuid);
    return $uuid;
}

function spritz_term_uuid($term): string {
    $term = is_object($term) ? $term : get_term($term);
    if (!$term || is_wp_error($term)) {
        return spritz_uuid_v4();
    }

    $uuid = (string) get_term_meta($term->term_id, '_spritz_uuid', true);
    if (spritz_is_uuid($uuid)) {
        return $uuid;
    }

    $uuid = spritz_uuid_v5('spritz:term:' . $term->taxonomy . ':' . $term->slug);
    update_term_meta($term->term_id, '_spritz_uuid', $uuid);
    return $uuid;
}

function spritz_homepage_uuid(string $language = 'default'): string {
    $language = sanitize_key($language) ?: 'default';
    $option = 'spritz_homepage_uuid_' . $language;
    $uuid = (string) get_option($option);
    if (spritz_is_uuid($uuid)) {
        return $uuid;
    }

    $uuid = spritz_uuid_v5('spritz:homepage:' . $language);
    update_option($option, $uuid, false);
    return $uuid;
}

function spritz_author_uuid($user): string {
    $user_id = is_object($user) ? (int) $user->ID : (int) $user;
    if ($user_id > 0) {
        $uuid = (string) get_user_meta($user_id, '_spritz_uuid', true);
        if (spritz_is_uuid($uuid)) {
            return $uuid;
        }

        $uuid = spritz_uuid_v5('spritz:user:' . $user_id);
        update_user_meta($user_id, '_spritz_uuid', $uuid);
        return $uuid;
    }

    $uuid = (string) get_option('spritz_default_author_uuid');
    if (spritz_is_uuid($uuid)) {
        return $uuid;
    }

    $uuid = spritz_uuid_v5('spritz:author:modoitaliano');
    update_option('spritz_default_author_uuid', $uuid, false);
    return $uuid;
}

function spritz_default_category_uuid(): string {
    $uuid = (string) get_option('spritz_default_category_uuid');
    if (spritz_is_uuid($uuid)) {
        return $uuid;
    }

    $uuid = spritz_uuid_v5('spritz:category:news');
    update_option('spritz_default_category_uuid', $uuid, false);
    return $uuid;
}

function spritz_is_uuid(string $value): bool {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
}

function spritz_uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function spritz_uuid_v5(string $name): string {
    $namespace = 'b7cf9647-6b8f-4ff0-9fd1-7f3b8e9d3f8f';
    $namespace_bytes = hex2bin(str_replace('-', '', $namespace));
    if ($namespace_bytes === false) {
        return spritz_uuid_v4();
    }

    $hash = sha1($namespace_bytes . $name);
    $time_hi = (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000;
    $clock_seq = (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000;

    return sprintf(
        '%s-%s-%04x-%04x-%s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        $time_hi,
        $clock_seq,
        substr($hash, 20, 12)
    );
}
