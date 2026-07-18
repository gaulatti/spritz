<?php

use Aws\S3\S3Client;

$s3_bucket = defined('S3_UPLOADS_BUCKET') ? S3_UPLOADS_BUCKET : getenv('S3_UPLOADS_BUCKET');
$s3_prefix = defined('S3_UPLOADS_PREFIX') ? S3_UPLOADS_PREFIX : getenv('S3_UPLOADS_PREFIX');
$cloudfront = defined('CLOUDFRONT_MEDIA_DOMAIN') ? CLOUDFRONT_MEDIA_DOMAIN : getenv('CLOUDFRONT_MEDIA_DOMAIN');
$region = getenv('AWS_REGION') ?: 'us-east-1';

if ($s3_bucket && !defined('S3_UPLOADS_BUCKET')) {
    define('S3_UPLOADS_BUCKET', $s3_bucket);
}

if ($s3_prefix && !defined('S3_UPLOADS_PREFIX')) {
    define('S3_UPLOADS_PREFIX', $s3_prefix);
}

if ($s3_bucket && !defined('AS3CF_SETTINGS')) {
    define('AS3CF_SETTINGS', serialize([
        'provider' => 'aws',
        'use-server-roles' => true,
        'bucket' => $s3_bucket,
        'region' => $region,
        'copy-to-s3' => true,
        'serve-from-s3' => true,
        'enable-object-prefix' => !empty($s3_prefix),
        'object-prefix' => $s3_prefix ?: '',
        'use-yearmonth-folders' => true,
        'object-versioning' => true,
        'remove-local-file' => false,
        'delivery-provider' => $cloudfront ? 'aws' : 'storage',
        'delivery-provider-service-name' => $cloudfront ? 'cloudfront' : 'bucket',
        'enable-delivery-domain' => !empty($cloudfront),
        'delivery-domain' => $cloudfront ?: '',
        'force-https' => true,
    ]));
}

add_filter('wp_update_attachment_metadata', 'spritz_offload_attachment_to_s3', 120, 2);
add_filter('wp_get_attachment_url', 'spritz_rewrite_attachment_url_to_cdn', 20, 2);

function spritz_offload_attachment_to_s3($metadata, $attachment_id) {
    $bucket = spritz_s3_bucket();
    if (!$bucket) {
        return $metadata;
    }

    $upload_dir = wp_get_upload_dir();
    $relative_file = get_post_meta($attachment_id, '_wp_attached_file', true);

    if (!$relative_file) {
        return $metadata;
    }

    $files = [$relative_file];
    $base_dir = trailingslashit(dirname($relative_file));

    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size) {
            if (!empty($size['file'])) {
                $files[] = $base_dir . $size['file'];
            }
        }
    }

    foreach (array_unique($files) as $relative_path) {
        $local_path = trailingslashit($upload_dir['basedir']) . $relative_path;

        if (!is_readable($local_path)) {
            continue;
        }

        spritz_s3_put_file($local_path, spritz_s3_object_key($relative_path));
    }

    update_post_meta($attachment_id, '_spritz_s3_offloaded', gmdate('c'));

    return $metadata;
}

function spritz_rewrite_attachment_url_to_cdn($url, $attachment_id) {
    $cloudfront = spritz_s3_cloudfront_domain();
    if (!$cloudfront) {
        return $url;
    }

    $relative_file = get_post_meta($attachment_id, '_wp_attached_file', true);
    if (!$relative_file) {
        return $url;
    }

    return 'https://' . $cloudfront . '/' . spritz_s3_object_key($relative_file);
}

function spritz_s3_put_file($local_path, $key) {
    return spritz_s3_put_object([
        'Key' => $key,
        'SourceFile' => $local_path,
        'ContentType' => spritz_s3_content_type($local_path),
    ], $key);
}

function spritz_s3_put_body($key, $body, $content_type = 'application/octet-stream', $cache_control = null) {
    $object = [
        'Key' => $key,
        'Body' => $body,
        'ContentType' => $content_type,
    ];

    if ($cache_control) {
        $object['CacheControl'] = $cache_control;
    }

    return spritz_s3_put_object($object, $key);
}

function spritz_s3_put_object(array $object, $log_key) {
    static $client = null;

    if ($client === null) {
        $autoload = '/var/www/html/vendor/autoload.php';
        if (is_readable($autoload)) {
            require_once $autoload;
        }

        if (!class_exists(S3Client::class)) {
            error_log('Spritz S3 offload skipped: AWS SDK is not available.');
            return false;
        }

        $client_config = [
            'version' => 'latest',
            'region' => getenv('AWS_REGION') ?: 'us-east-1',
        ];

        $credentials_file = getenv('AWS_SHARED_CREDENTIALS_FILE') ?: '/var/www/.aws/credentials';
        if (is_readable($credentials_file)) {
            $client_config['profile'] = getenv('AWS_PROFILE') ?: 'default';
            putenv('AWS_SHARED_CREDENTIALS_FILE=' . $credentials_file);
        }

        $client = new S3Client($client_config);
    }

    try {
        $client->putObject(array_merge([
            'Bucket' => spritz_s3_bucket(),
        ], $object));
        return true;
    } catch (Throwable $exception) {
        error_log(sprintf('Spritz S3 offload failed for %s: %s', $log_key, $exception->getMessage()));
        return false;
    }
}

function spritz_s3_object_key($relative_path) {
    $prefix = trim((string) spritz_s3_prefix(), '/');
    $relative_path = ltrim((string) $relative_path, '/');

    return $prefix ? $prefix . '/' . $relative_path : $relative_path;
}

function spritz_s3_bucket() {
    if (defined('S3_UPLOADS_BUCKET')) {
        return S3_UPLOADS_BUCKET;
    }

    return getenv('S3_UPLOADS_BUCKET') ?: '';
}

function spritz_s3_prefix() {
    if (defined('S3_UPLOADS_PREFIX')) {
        return S3_UPLOADS_PREFIX;
    }

    return getenv('S3_UPLOADS_PREFIX') ?: '';
}

function spritz_s3_cloudfront_domain() {
    if (defined('CLOUDFRONT_MEDIA_DOMAIN')) {
        return CLOUDFRONT_MEDIA_DOMAIN;
    }

    return getenv('CLOUDFRONT_MEDIA_DOMAIN') ?: '';
}

function spritz_s3_content_type($path) {
    if (function_exists('mime_content_type')) {
        $type = mime_content_type($path);
        if ($type) {
            return $type;
        }
    }

    $type = wp_check_filetype($path);
    return $type['type'] ?: 'application/octet-stream';
}
