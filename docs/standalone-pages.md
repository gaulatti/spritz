# Standalone pages

Published WordPress Pages, except the configured front page and the homepage
template, are canonical standalone documents. Spritz publishes them with the
`standalone-page` layout at a top-level route such as `/quienes-somos`.

Their canonical JSON is written under
`content/json/pages/{localized-slug}.json` and each published page is included
in `cronkite-inventory.json` as type `standalone-page`. Saving a Page also asks
Cronkite to render that canonical document without running article
aggregations. Pages never receive the fallback News category and never enter
social delivery.

Container startup backfills every currently published standalone Page. This
ensures Pages created before this contract was deployed receive canonical JSON,
an inventory entry, and rendered HTML without requiring an editor to resave
them.

Eligible published source Pages participate in the durable translation workflow.
Successful translations remain native Pages and publish at localized top-level
routes such as `/en/privacy`; the front Page and homepage template are never
queued. Source updates supersede stale jobs. Unpublish or delete invalidates the
source and every published localized sibling through Cronkite's synchronous,
layout-aware unpublish endpoint and refreshes the Spritz inventory.

The canonical URL uses `PUBLIC_SITE_URL` or `WP_PUBLIC_SITE_URL`, defaulting to
`https://modoitaliano.fm`; it must never point at the Spritz WordPress host.
