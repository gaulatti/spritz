<?php

$s3_bucket = defined('S3_UPLOADS_BUCKET') ? S3_UPLOADS_BUCKET : getenv('S3_UPLOADS_BUCKET');
$s3_prefix = defined('S3_UPLOADS_PREFIX') ? S3_UPLOADS_PREFIX : getenv('S3_UPLOADS_PREFIX');
$cloudfront = defined('CLOUDFRONT_MEDIA_DOMAIN') ? CLOUDFRONT_MEDIA_DOMAIN : getenv('CLOUDFRONT_MEDIA_DOMAIN');

if ($s3_bucket && !defined('S3_UPLOADS_BUCKET')) {
    define('S3_UPLOADS_BUCKET', $s3_bucket);
}

if ($s3_prefix && !defined('S3_UPLOADS_PREFIX')) {
    define('S3_UPLOADS_PREFIX', $s3_prefix);
}

if ($cloudfront) {
    if (!defined('S3_UPLOADS_HTTP_OPTS')) {
        define('S3_UPLOADS_HTTP_OPTS', json_encode(['cloudfront' => $cloudfront]));
    }
    if (!defined('WP_CONTENT_URL')) {
        define('WP_CONTENT_URL', 'https://' . $cloudfront);
    }
}
