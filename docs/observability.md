# Spritz observability

Spritz exposes Prometheus text format 0.0.4 at `GET /metrics`. The endpoint is
for the private managed Prometheus scraper only. Every request must provide the
deployment-owned `METRICS_TOKEN` in the `X-Spritz-Metrics-Token` header; a missing
server token fails closed with `503`, and a missing or incorrect caller token
returns `403`. Production network policy must additionally restrict the route
to the private scraper path. The token belongs in the Spritz Secrets Manager
object selected by `APP_SECRET_ARN`/`APP_SECRET_KEY`, never in source control,
container arguments, scrape labels, or logs.

PHP-FPM, the translation worker, and the backup cron process share an ephemeral
registry at `/run/spritz/metrics.json`. File locking makes updates safe across
processes. The scrape reads that registry without booting WordPress or querying
the database. The registry contains operational counters and gauges only;
deleting the container resets it and does not affect application data.

## Metric contract

| Metric | Meaning | Labels |
| --- | --- | --- |
| `spritz_service_info` | Service/runtime identity | `service`, `runtime` |
| `spritz_process_start_time_seconds` | Shared registry start time | none |
| `spritz_php_memory_usage_bytes` | Scrape-serving PHP process memory | none |
| `spritz_http_requests_total` | Requests by normalized outcome | `method`, `route`, `status_class` |
| `spritz_http_request_duration_seconds` | Request latency histogram | `method`, `route` |
| `spritz_dependency_operations_total` | Cronkite, Gemini, and S3 outcomes | `dependency`, `operation`, `result` |
| `spritz_dependency_duration_seconds` | Dependency latency histogram | `dependency`, `operation` |
| `spritz_translation_jobs_total` | Enqueue/deduplication/manual retry outcomes | `operation`, `result` |
| `spritz_translation_job_transitions_total` | Durable job lifecycle transitions | `status` |
| `spritz_translation_queue_jobs` | Current durable queue inventory | `status` |
| `spritz_worker_cycles_total` | Translation worker progress/idle/errors | `worker`, `result` |
| `spritz_worker_retries_total` | Scheduled, exhausted, or manual retry paths | `worker`, `result` |
| `spritz_backup_jobs_total` | Scheduled database backup outcomes | `result` |
| `spritz_backup_duration_seconds` | Backup runtime histogram | none |
| `spritz_now_playing_publications_total` | Static now-playing publication outcomes | `status`, `result` |

Every label is checked against a closed enum before it can enter the registry.
Routes are classes such as `now_playing`, `wordpress_rest`, or `admin`, never
actual paths. The registry rejects post IDs, slugs, URLs, tenant/user/device
identities, tokens, credentials, content, and free-form errors as labels.

## Local and runtime verification

Compose provides a local-only fixture token. After the stack is ready:

```sh
curl --fail --header 'X-Spritz-Metrics-Token: local-metrics-token' \
  http://localhost:8080/metrics
```

The pull-request workflow proves unauthorized access is rejected, scrapes the
running collector, checks the required content type, and parses the exact output
with `promtool check metrics`. Unit coverage also exercises success and failure
series and verifies that unknown label values fail closed.

## Deployment dependency

This repository owns instrumentation and private exposure only. The
`gaulatti/prometheus` deployment must add the private Spritz target, store the
header credential in its scraper configuration, and own dashboards and alerts.
That central configuration is deliberately not changed by this ticket.
