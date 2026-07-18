<?php

define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_NAME', getenv('DB_NAME') ?: 'wordpress');
define('DB_USER', getenv('DB_USER') ?: 'wordpress');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

if (!defined('MYSQL_CLIENT_FLAGS')) {
    $mysql_client_flags = 0;
    if (defined('MYSQLI_CLIENT_SSL')) {
        $mysql_client_flags |= MYSQLI_CLIENT_SSL;
    }
    if (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')) {
        $mysql_client_flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
    }
    define('MYSQL_CLIENT_FLAGS', $mysql_client_flags);
}

define('WP_HOME', getenv('WP_HOME') ?: 'http://localhost:8080');
define('WP_SITEURL', getenv('WP_SITEURL') ?: 'http://localhost:8080');
define('FS_METHOD', 'direct');
define('WP_DEBUG', filter_var(getenv('WP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN));

$salt_keys = [
    'AUTH_KEY',
    'SECURE_AUTH_KEY',
    'LOGGED_IN_KEY',
    'NONCE_KEY',
    'AUTH_SALT',
    'SECURE_AUTH_SALT',
    'LOGGED_IN_SALT',
    'NONCE_SALT',
];

foreach ($salt_keys as $key) {
    $value = getenv($key);
    if ($value !== false && !defined($key)) {
        define($key, $value);
    }
}

$table_prefix = 'wp_';

if (getenv('WORDPRESS_CONFIG_EXTRA')) {
    eval(getenv('WORDPRESS_CONFIG_EXTRA'));
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
