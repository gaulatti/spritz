<?php

if (PHP_SAPI === 'cli' || defined('DOING_CRON') || defined('XMLRPC_REQUEST')) {
    return;
}

$allowed = [
    '/wp-admin',
    '/wp-login.php',
    '/wp-cron.php',
];

$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

foreach ($allowed as $prefix) {
    if (strpos($request_path, $prefix) === 0) {
        return;
    }
}

if (strpos($request_path, '/index.php') === 0) {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    if (strpos($query, 'rest_route=') !== false) {
        return;
    }
}

wp_die('Forbidden', 403, ['response' => 403]);
