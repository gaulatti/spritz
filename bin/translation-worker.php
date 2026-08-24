<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Spritz translation worker must run through WP-CLI.\n");
    exit(1);
}

$idle_sleep = max(1, (int) (getenv('TRANSLATION_WORKER_IDLE_SECONDS') ?: 5));

if (getenv('TRANSLATION_WORKER_DISABLED') === '1') {
    fwrite(STDOUT, "Spritz translation worker disabled\n");
    while (true) sleep(3600);
}

fwrite(STDOUT, "Spritz translation worker ready\n");
while (true) {
    try {
        $processed = spritz_ai_translation_process_jobs(1);
        if ($processed === 0) {
            if (function_exists('spritz_metric_increment')) spritz_metric_increment('spritz_worker_cycles_total', ['worker' => 'translation', 'result' => 'idle']);
            sleep($idle_sleep);
        } elseif (function_exists('spritz_metric_increment')) {
            spritz_metric_increment('spritz_worker_cycles_total', ['worker' => 'translation', 'result' => 'processed']);
        }
    } catch (Throwable $error) {
        if (function_exists('spritz_metric_increment')) spritz_metric_increment('spritz_worker_cycles_total', ['worker' => 'translation', 'result' => 'error']);
        // Never include payloads or provider responses in the process log.
        fwrite(STDERR, 'Translation worker error: ' . get_class($error) . "\n");
        sleep($idle_sleep);
    }
}
