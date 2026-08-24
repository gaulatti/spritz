<?php

require_once __DIR__ . '/lib/spritz-metrics.php';

if (PHP_SAPI !== 'cli') {
    $GLOBALS['spritz_http_request_started_at'] = microtime(true);
    register_shutdown_function('spritz_metrics_record_http_request');
}
function spritz_metrics_record_http_request(): void {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'OTHER'));
    if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) $method = 'OTHER';
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $route = 'other';
    if ($path === '/metrics') $route = 'metrics';
    elseif (str_contains($path, '/spritz/v1/now-playing')) $route = 'now_playing';
    elseif (str_starts_with($path, '/wp-json/')) $route = 'wordpress_rest';
    elseif (str_starts_with($path, '/wp-admin/')) $route = 'admin';
    elseif ($path === '/wp-login.php') $route = 'auth';
    elseif ($path === '/') $route = 'frontend';
    $status = http_response_code();
    $status_class = $status >= 100 && $status <= 599 ? (string) intdiv($status, 100) . 'xx' : 'unknown';
    $duration = max(0, microtime(true) - (float) ($GLOBALS['spritz_http_request_started_at'] ?? microtime(true)));
    spritz_metric_increment('spritz_http_requests_total', ['method' => $method, 'route' => $route, 'status_class' => $status_class]);
    spritz_metric_observe('spritz_http_request_duration_seconds', ['method' => $method, 'route' => $route], $duration);
}

function spritz_metrics_observe_dependency(string $dependency, string $operation, string $result, float $started_at): void {
    spritz_metric_increment('spritz_dependency_operations_total', compact('dependency', 'operation', 'result'));
    spritz_metric_observe('spritz_dependency_duration_seconds', compact('dependency', 'operation'), max(0, microtime(true) - $started_at));
}

function spritz_metrics_refresh_translation_queue(): void {
    if (!function_exists('spritz_ai_translation_job_stats')) return;
    foreach (spritz_ai_translation_job_stats() as $status => $count) {
        spritz_metric_set('spritz_translation_queue_jobs', ['status' => $status], (float) $count);
    }
}
