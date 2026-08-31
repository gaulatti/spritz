<?php

require_once '/var/www/html/vendor/autoload.php';

use Aws\SecretsManager\SecretsManagerClient;

$secretArn = getenv('APP_SECRET_ARN');
if (!$secretArn) {
    exit(0);
}

$region = getenv('AWS_REGION') ?: 'us-east-1';
$secretKey = getenv('APP_SECRET_KEY') ?: null;

try {
    $client = new SecretsManagerClient([
        'version' => 'latest',
        'region' => $region,
        'http' => [
            'connect_timeout' => 2,
            'timeout' => 4,
        ],
    ]);

    $response = $client->getSecretValue(['SecretId' => $secretArn]);
    $raw = json_decode($response['SecretString'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, "Failed to load application config from Secrets Manager.\n");
    exit(1);
}

if ($secretKey) {
    $raw = $raw[$secretKey] ?? null;
    if (is_string($raw)) {
        try {
            $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            fwrite(STDERR, "Selected application config is not valid JSON.\n");
            exit(1);
        }
    }
    if (!is_array($raw)) {
        fwrite(STDERR, "Selected application config was not found or is not an object.\n");
        exit(1);
    }
}

$allowedKeys = [
    'ADMIN_EMAIL',
    'ADMIN_PASSWORD',
    'ADMIN_USER',
    'AUTH_KEY',
    'AUTH_SALT',
    'CLOUDFRONT_MEDIA_DOMAIN',
    'CRONKITE_TENANT_SLUG',
    'CRONKITE_URL',
    'DB_BACKUP_RESTORE_ROLE_ARN',
    'DB_BACKUP_WRITER_ROLE_ARN',
    'DB_SECRET_ARN',
    'DEFAULT_LANGUAGE',
    'GEMINI_API_KEY',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'LOGGED_IN_KEY',
    'LOGGED_IN_SALT',
    'METRICS_TOKEN',
    'NESTJS_WEBHOOK_SECRET',
    'NESTJS_WEBHOOK_URL',
    'NONCE_KEY',
    'NONCE_SALT',
    'NOW_PLAYING_TOKEN',
    'PIPELINE_TOKEN',
    'PUBLIC_SITE_URL',
    'S3_UPLOADS_BUCKET',
    'S3_UPLOADS_PREFIX',
    'SECURE_AUTH_KEY',
    'SECURE_AUTH_SALT',
    'WORDPRESS_CONFIG_EXTRA',
    'WP_DEBUG',
    'WP_HOME',
    'WP_PUBLIC_SITE_URL',
    'WP_SITEURL',
    'WP_TITLE',
];

$config = [];
foreach ($allowedKeys as $key) {
    if (!array_key_exists($key, $raw)) continue;
    $value = $raw[$key];
    if (!is_scalar($value) && $value !== null) {
        fwrite(STDERR, sprintf("Application config field %s must be scalar.\n", $key));
        exit(1);
    }
    $config[$key] = $value;
}

$databaseSecretArn = $config['DB_SECRET_ARN'] ?? null;
if (is_string($databaseSecretArn) && $databaseSecretArn !== '') {
    try {
        $databaseResponse = $client->getSecretValue(['SecretId' => $databaseSecretArn]);
        $database = json_decode($databaseResponse['SecretString'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        fwrite(STDERR, "Failed to load database config from Secrets Manager.\n");
        exit(1);
    }

    $databaseFields = [
        'host' => 'DB_HOST',
        'dbname' => 'DB_NAME',
        'username' => 'DB_USER',
        'password' => 'DB_PASSWORD',
    ];
    foreach ($databaseFields as $source => $target) {
        if (!isset($database[$source]) || !is_scalar($database[$source]) || (string) $database[$source] === '') {
            fwrite(STDERR, sprintf("Database config field %s is missing or invalid.\n", $source));
            exit(1);
        }
        $config[$target] = (string) $database[$source];
    }
}

foreach ($config as $key => $value) {

    $existing = getenv($key);
    if ($existing !== false && $existing !== '') {
        continue;
    }

    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    } elseif (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_SLASHES);
    } elseif ($value === null) {
        $value = '';
    } else {
        $value = (string) $value;
    }

    printf("export %s=%s\n", $key, escapeshellarg($value));
}
