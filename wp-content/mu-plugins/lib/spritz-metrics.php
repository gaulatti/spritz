<?php

/**
 * Minimal multi-process Prometheus registry for Spritz.
 *
 * PHP-FPM, WP-CLI workers, and cron share an ephemeral lock-protected file.
 * Metric names and every label value are validated against this closed schema.
 */

function spritz_metrics_schema(): array {
    static $schema = null;
    if ($schema !== null) return $schema;

    $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'OTHER'];
    $routes = ['metrics', 'now_playing', 'wordpress_rest', 'admin', 'auth', 'frontend', 'other'];
    $status_classes = ['1xx', '2xx', '3xx', '4xx', '5xx', 'unknown'];
    $dependencies = ['cronkite', 'gemini', 's3'];
    $operations = ['rerender', 'social', 'translate', 'put'];

    $schema = [
        'spritz_service_info' => ['type' => 'gauge', 'help' => 'Spritz service and runtime identity.', 'labels' => ['service' => ['spritz'], 'runtime' => ['php']]],
        'spritz_process_start_time_seconds' => ['type' => 'gauge', 'help' => 'Unix time when the shared Spritz metrics registry started.', 'labels' => []],
        'spritz_php_memory_usage_bytes' => ['type' => 'gauge', 'help' => 'Memory used by the PHP process serving the scrape.', 'labels' => []],
        'spritz_http_requests_total' => ['type' => 'counter', 'help' => 'HTTP requests by bounded method, route class, and status class.', 'labels' => ['method' => $methods, 'route' => $routes, 'status_class' => $status_classes]],
        'spritz_http_request_duration_seconds' => ['type' => 'histogram', 'help' => 'HTTP request duration by bounded method and route class.', 'labels' => ['method' => $methods, 'route' => $routes]],
        'spritz_dependency_operations_total' => ['type' => 'counter', 'help' => 'Dependency operations by bounded dependency, operation, and result.', 'labels' => ['dependency' => $dependencies, 'operation' => $operations, 'result' => ['success', 'failure', 'unavailable']]],
        'spritz_dependency_duration_seconds' => ['type' => 'histogram', 'help' => 'Dependency operation duration by bounded dependency and operation.', 'labels' => ['dependency' => $dependencies, 'operation' => $operations]],
        'spritz_translation_jobs_total' => ['type' => 'counter', 'help' => 'Translation intake operations by bounded result.', 'labels' => ['operation' => ['enqueue', 'manual_retry'], 'result' => ['created', 'deduplicated', 'accepted', 'rejected']]],
        'spritz_translation_job_transitions_total' => ['type' => 'counter', 'help' => 'Translation job transitions by bounded lifecycle status.', 'labels' => ['status' => ['queued', 'leased', 'completed', 'failed', 'manual', 'superseded']]],
        'spritz_translation_queue_jobs' => ['type' => 'gauge', 'help' => 'Translation jobs by bounded lifecycle status.', 'labels' => ['status' => ['queued', 'leased', 'completed', 'failed', 'manual', 'superseded']]],
        'spritz_worker_cycles_total' => ['type' => 'counter', 'help' => 'Background worker cycles by bounded worker and result.', 'labels' => ['worker' => ['translation'], 'result' => ['processed', 'idle', 'error']]],
        'spritz_worker_retries_total' => ['type' => 'counter', 'help' => 'Background retries by bounded worker and result.', 'labels' => ['worker' => ['translation'], 'result' => ['scheduled', 'exhausted', 'manual']]],
        'spritz_backup_jobs_total' => ['type' => 'counter', 'help' => 'Database backup jobs by bounded result.', 'labels' => ['result' => ['success', 'failure']]],
        'spritz_backup_duration_seconds' => ['type' => 'histogram', 'help' => 'Database backup job duration.', 'labels' => []],
        'spritz_now_playing_publications_total' => ['type' => 'counter', 'help' => 'Now-playing static publications by bounded state and result.', 'labels' => ['status' => ['playing', 'paused', 'stopped', 'unknown'], 'result' => ['success', 'failure']]],
    ];
    return $schema;
}

function spritz_metrics_histogram_buckets(): array {
    return [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120];
}

function spritz_metrics_path(): string {
    return $GLOBALS['spritz_metrics_path_override'] ?? '/run/spritz/metrics.json';
}

function spritz_metrics_authorization_status(string $expected, array $server): int {
    if ($expected === '') return 503;
    $authorization = (string) ($server['HTTP_AUTHORIZATION'] ?? '');
    $provided = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) $provided = trim($matches[1]);
    if ($provided === '') $provided = trim((string) ($server['HTTP_X_SPRITZ_METRICS_TOKEN'] ?? ''));
    return $provided !== '' && hash_equals($expected, $provided) ? 200 : 403;
}

function spritz_metrics_empty_state(): array {
    return ['started_at' => time(), 'series' => []];
}

function spritz_metrics_with_state(callable $callback): bool {
    $path = spritz_metrics_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) return false;
    $handle = @fopen($path, 'c+');
    if ($handle === false) return false;
    try {
        if (!flock($handle, LOCK_EX)) return false;
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = $raw ? json_decode($raw, true) : null;
        if (!is_array($state) || !isset($state['series'])) $state = spritz_metrics_empty_state();
        $callback($state);
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0)) return false;
        if (fwrite($handle, $encoded) === false) return false;
        fflush($handle);
        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function spritz_metrics_read_state(): array {
    $state = spritz_metrics_empty_state();
    spritz_metrics_with_state(function (array &$stored) use (&$state): void { $state = $stored; });
    return $state;
}

function spritz_metrics_validate(string $name, array $labels, string $expected_type): array {
    $definition = spritz_metrics_schema()[$name] ?? null;
    if (!$definition || $definition['type'] !== $expected_type) {
        throw new InvalidArgumentException('Unknown Spritz metric or invalid metric type.');
    }
    ksort($labels);
    $allowed = $definition['labels'];
    ksort($allowed);
    if (array_keys($labels) !== array_keys($allowed)) {
        throw new InvalidArgumentException('Metric labels do not match the bounded schema.');
    }
    foreach ($labels as $key => $value) {
        $value = (string) $value;
        if (!in_array($value, $allowed[$key], true)) {
            throw new InvalidArgumentException('Metric label value is outside the bounded schema.');
        }
        $labels[$key] = $value;
    }
    return $labels;
}

function spritz_metrics_series_key(array $labels): string {
    return hash('sha256', json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function spritz_metric_increment(string $name, array $labels = [], float $amount = 1): bool {
    if ($amount < 0 || !is_finite($amount)) throw new InvalidArgumentException('Counter increments must be finite and non-negative.');
    $labels = spritz_metrics_validate($name, $labels, 'counter');
    return spritz_metrics_with_state(function (array &$state) use ($name, $labels, $amount): void {
        $key = spritz_metrics_series_key($labels);
        $series =& $state['series'][$name][$key];
        if (!is_array($series)) $series = ['labels' => $labels, 'value' => 0];
        $series['value'] += $amount;
    });
}

function spritz_metric_set(string $name, array $labels, float $value): bool {
    if (!is_finite($value)) throw new InvalidArgumentException('Gauge values must be finite.');
    $labels = spritz_metrics_validate($name, $labels, 'gauge');
    return spritz_metrics_with_state(function (array &$state) use ($name, $labels, $value): void {
        $state['series'][$name][spritz_metrics_series_key($labels)] = ['labels' => $labels, 'value' => $value];
    });
}

function spritz_metric_observe(string $name, array $labels, float $value): bool {
    if ($value < 0 || !is_finite($value)) throw new InvalidArgumentException('Histogram observations must be finite and non-negative.');
    $labels = spritz_metrics_validate($name, $labels, 'histogram');
    return spritz_metrics_with_state(function (array &$state) use ($name, $labels, $value): void {
        $key = spritz_metrics_series_key($labels);
        $series =& $state['series'][$name][$key];
        if (!is_array($series)) $series = ['labels' => $labels, 'sum' => 0, 'count' => 0, 'buckets' => []];
        $series['sum'] += $value;
        $series['count']++;
        foreach (spritz_metrics_histogram_buckets() as $bucket) {
            $bucket_key = spritz_metrics_number($bucket);
            if (!isset($series['buckets'][$bucket_key])) $series['buckets'][$bucket_key] = 0;
            if ($value <= $bucket) $series['buckets'][$bucket_key]++;
        }
    });
}

function spritz_metrics_number(float $value): string {
    if (floor($value) === $value) return (string) (int) $value;
    return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
}

function spritz_metrics_escape_label(string $value): string {
    return str_replace(["\\", "\n", '"'], ["\\\\", '\\n', '\\"'], $value);
}

function spritz_metrics_labels(array $labels): string {
    if (!$labels) return '';
    $parts = [];
    foreach ($labels as $key => $value) $parts[] = $key . '="' . spritz_metrics_escape_label($value) . '"';
    return '{' . implode(',', $parts) . '}';
}

function spritz_metrics_render(): string {
    $state = spritz_metrics_read_state();
    $state['series']['spritz_service_info'][spritz_metrics_series_key(['runtime' => 'php', 'service' => 'spritz'])] = ['labels' => ['runtime' => 'php', 'service' => 'spritz'], 'value' => 1];
    $state['series']['spritz_process_start_time_seconds'][spritz_metrics_series_key([])] = ['labels' => [], 'value' => (float) ($state['started_at'] ?? time())];
    $state['series']['spritz_php_memory_usage_bytes'][spritz_metrics_series_key([])] = ['labels' => [], 'value' => (float) memory_get_usage(true)];
    $lines = [];
    foreach (spritz_metrics_schema() as $name => $definition) {
        $lines[] = '# HELP ' . $name . ' ' . str_replace("\n", ' ', $definition['help']);
        $lines[] = '# TYPE ' . $name . ' ' . $definition['type'];
        $series_set = $state['series'][$name] ?? [];
        ksort($series_set);
        foreach ($series_set as $series) {
            $labels = $series['labels'];
            if ($definition['type'] !== 'histogram') {
                $lines[] = $name . spritz_metrics_labels($labels) . ' ' . spritz_metrics_number((float) $series['value']);
                continue;
            }
            foreach (spritz_metrics_histogram_buckets() as $bucket) {
                $bucket_key = spritz_metrics_number($bucket);
                $lines[] = $name . '_bucket' . spritz_metrics_labels(array_merge($labels, ['le' => $bucket_key])) . ' ' . (int) ($series['buckets'][$bucket_key] ?? 0);
            }
            $lines[] = $name . '_bucket' . spritz_metrics_labels(array_merge($labels, ['le' => '+Inf'])) . ' ' . (int) $series['count'];
            $lines[] = $name . '_sum' . spritz_metrics_labels($labels) . ' ' . spritz_metrics_number((float) $series['sum']);
            $lines[] = $name . '_count' . spritz_metrics_labels($labels) . ' ' . (int) $series['count'];
        }
    }
    return implode("\n", $lines) . "\n";
}

function spritz_metrics_reset_for_test(): void {
    $path = spritz_metrics_path();
    if (is_file($path)) unlink($path);
}
