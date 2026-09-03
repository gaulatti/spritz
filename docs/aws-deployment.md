# AWS deployment target

When `ON_PREMISES` is false or absent, the deployment workflow resolves the
single running EC2 instance tagged `Name=macondo-services` and deploys through
AWS Systems Manager. The `spritz-github-deploy` role can send commands only to
an instance carrying that tag. It is intentionally not coupled to an EC2
physical instance ID, because Macondo may replace the host during an operating
system or instance update while retaining its Elastic IP.

The workflow fails closed when zero or multiple running hosts match. The
`ON_PREMISES=true` SSH path remains unchanged as the explicit fallback.

## Media upload storage

WordPress first stages uploaded media and generated image sizes in the
`spritz-uploads` Docker volume. The S3 integration copies those files to the
configured media bucket after WordPress creates the attachment metadata, and
CloudFront remains the public delivery path. This is not a browser-direct S3
upload flow.

The container repairs legacy upload-volume ownership before WordPress starts,
runs bootstrap WP-CLI commands as the same `www-data` identity used by PHP-FPM,
and fails startup if WordPress's current year/month upload directory is not
writable. The deployment test seeds a root-owned volume to prevent regressions
at this boundary.
