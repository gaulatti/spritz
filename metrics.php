<?php

require_once '/var/www/html/wordpress/wp-content/mu-plugins/lib/spritz-metrics.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$request_server = $_SERVER;
if (function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0) {
            $request_server['HTTP_AUTHORIZATION'] = (string) $value;
        }
        if (strcasecmp((string) $name, 'X-Spritz-Metrics-Token') === 0) {
            $request_server['HTTP_X_SPRITZ_METRICS_TOKEN'] = (string) $value;
        }
    }
}

$expected = (string) getenv('METRICS_TOKEN');
$authorization_status = spritz_metrics_authorization_status($expected, $request_server);
if ($authorization_status !== 200) {
    $credential_present = trim((string) ($request_server['HTTP_AUTHORIZATION'] ?? '')) !== ''
        || trim((string) ($request_server['SPRITZ_METRICS_AUTHORIZATION'] ?? '')) !== ''
        || trim((string) ($request_server['HTTP_X_SPRITZ_METRICS_TOKEN'] ?? '')) !== ''
        || trim((string) ($request_server['SPRITZ_METRICS_HEADER_TOKEN'] ?? '')) !== '';
    header('X-Spritz-Metrics-Credential: ' . ($credential_present ? 'present' : 'missing'));
    http_response_code($authorization_status);
    exit;
}

require_once '/var/www/html/wordpress/wp-load.php';
spritz_metrics_refresh_translation_queue();
header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
header('Cache-Control: no-store');
echo spritz_metrics_render();
