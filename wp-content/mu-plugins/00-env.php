<?php

$env_keys = [
    'NESTJS_WEBHOOK_URL',
    'NESTJS_WEBHOOK_SECRET',
    'S3_UPLOADS_BUCKET',
    'S3_UPLOADS_PREFIX',
    'CLOUDFRONT_MEDIA_DOMAIN',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'GOOGLE_ALLOWED_DOMAIN',
    'CRONKITE_URL',
    'CRONKITE_TENANT_SLUG',
    'PIPELINE_TOKEN',
    'DEFAULT_LANGUAGE',
];

foreach ($env_keys as $key) {
    $value = getenv($key);
    if ($value !== false && !defined($key)) {
        define($key, $value);
    }
}
