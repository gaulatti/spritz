<?php

require_once '/var/www/html/wordpress/wp-load.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$expected = (string) (defined('METRICS_TOKEN') ? METRICS_TOKEN : getenv('METRICS_TOKEN'));
$authorization_status = spritz_metrics_authorization_status($expected, $_SERVER);
if ($authorization_status !== 200) {
    http_response_code($authorization_status);
    exit;
}

spritz_metrics_refresh_translation_queue();
header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
header('Cache-Control: no-store');
echo spritz_metrics_render();
