<?php

$GLOBALS['spritz_metrics_path_override'] = sys_get_temp_dir() . '/spritz-metrics-' . getmypid() . '.json';
require_once __DIR__ . '/../wp-content/mu-plugins/lib/spritz-metrics.php';
spritz_metrics_reset_for_test();

function assert_true($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

assert_true(spritz_metrics_authorization_status('', []) === 503, 'missing server token did not fail closed');
assert_true(spritz_metrics_authorization_status('expected', []) === 403, 'missing caller token was accepted');
assert_true(spritz_metrics_authorization_status('expected', ['HTTP_AUTHORIZATION' => 'Bearer wrong']) === 403, 'incorrect bearer token was accepted');
assert_true(spritz_metrics_authorization_status('expected', ['HTTP_AUTHORIZATION' => 'Bearer expected']) === 200, 'valid bearer token was rejected');
assert_true(spritz_metrics_authorization_status('expected', ['HTTP_X_SPRITZ_METRICS_TOKEN' => 'expected']) === 200, 'valid scraper header was rejected');

spritz_metric_increment('spritz_http_requests_total', ['method' => 'GET', 'route' => 'now_playing', 'status_class' => '2xx']);
spritz_metric_increment('spritz_http_requests_total', ['method' => 'POST', 'route' => 'now_playing', 'status_class' => '5xx']);
spritz_metric_observe('spritz_http_request_duration_seconds', ['method' => 'GET', 'route' => 'now_playing'], 0.125);
spritz_metric_increment('spritz_dependency_operations_total', ['dependency' => 's3', 'operation' => 'put', 'result' => 'failure']);
spritz_metric_observe('spritz_dependency_duration_seconds', ['dependency' => 's3', 'operation' => 'put'], 0.25);
spritz_metric_set('spritz_translation_queue_jobs', ['status' => 'queued'], 3);

$rejected = false;
try {
    spritz_metric_increment('spritz_http_requests_total', ['method' => 'GET', 'route' => '/posts/private-slug', 'status_class' => '2xx']);
} catch (InvalidArgumentException $error) {
    $rejected = true;
}
assert_true($rejected, 'unbounded route label was accepted');

$output = spritz_metrics_render();
assert_true(str_ends_with($output, "\n"), 'exposition does not end in a line feed');
assert_true(str_contains($output, '# TYPE spritz_http_request_duration_seconds histogram'), 'histogram metadata missing');
assert_true(str_contains($output, 'le="+Inf"'), 'histogram infinity bucket missing');
foreach (['private-slug', 'token-value', 'article body', 'https://'] as $forbidden) {
    assert_true(!str_contains($output, $forbidden), 'sensitive or unbounded value entered exposition');
}

$samples = 0;
foreach (explode("\n", trim($output)) as $line) {
    if ($line === '' || str_starts_with($line, '#')) continue;
    assert_true((bool) preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*(?:\{[^}]*\})? (?:[-+]?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][-+]?\d+)?|[-+]Inf|NaN)$/', $line), 'invalid Prometheus sample: ' . $line);
    $samples++;
}
assert_true($samples > 10, 'real collector output contained too few samples');

spritz_metrics_reset_for_test();
fwrite(STDOUT, "metrics tests passed\n");
