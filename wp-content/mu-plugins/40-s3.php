<?php

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
