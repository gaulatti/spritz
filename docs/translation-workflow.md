# Durable translation workflow

Spritz treats Spanish as the canonical source language and creates English and Italian translation jobs for each published source revision. Eligible native WordPress Pages use the same durable queue as posts; the configured front Page and the homepage template remain excluded. The MySQL queue deduplicates replayed publication events, supersedes obsolete queued revisions, leases work with a unique token inside a `FOR UPDATE SKIP LOCKED` transaction, and recovers leases older than ten minutes.

The Supervisor-managed `translation-worker` runs in the Spritz image and processes one job at a time. It does not depend on WordPress page traffic or WP-Cron. Failures are retried after 1 minute, 5 minutes, 15 minutes, and 1 hour; the next failure is terminal and remains visible in Tools > Spritz Translations. An administrator can retry a terminal failure. A sibling changed by a person enters the `manual` state and is never overwritten unless an administrator explicitly chooses **Resume machine translation**.

Queue inventory, worker cycles, job transitions, Gemini outcomes/latency, and
retry exhaustion are exposed through the private metrics contract documented in
[`observability.md`](observability.md). Labels contain only controlled lifecycle
and result values.

`GEMINI_API_KEY` must be provided by the deployment secret loaded through `APP_SECRET_ARN`. Spritz never writes the key to WordPress options. The admin page reports only whether the environment variable is configured.

The worker rechecks the engine switch before the Gemini request and before persistence. Successful translations preserve the source WordPress type and set Spritz's `_spritz_language` and `_spritz_original_post_id` metadata first; Polylang metadata is compatibility-only. Publication then uses the normal WordPress save hooks, which produce canonical JSON and call Cronkite. Spanish keeps the unprefixed route, while English and Italian use `/en/...` and `/it/...`. Page translations stay top-level, fail on reserved or colliding slugs, and never enter article aggregation or social delivery.

Unpublishing or deleting a published source Page synchronously asks Cronkite to remove the source and every published localized sibling using the authoritative `standalone-page` layout. Local siblings become drafts and Spritz refreshes its inventory, so stale localized routes are not retained. A failed target-language job remains visible and independently retryable in **Tools > Spritz Translations**.

For local lifecycle validation:

```sh
GEMINI_API_KEY=integration-test-only TRANSLATION_WORKER_DISABLED=1 docker compose up --build -d --wait
docker compose cp tests/translation-e2e.php wordpress:/tmp/translation-e2e.php
docker compose exec -T wordpress wp eval-file /tmp/translation-e2e.php --path=/var/www/html/wordpress --allow-root
docker compose down --volumes
```
