# AWS deployment target

When `ON_PREMISES` is false or absent, the deployment workflow resolves the
single running EC2 instance tagged `Name=macondo-services` and deploys through
AWS Systems Manager. The `spritz-github-deploy` role can send commands only to
an instance carrying that tag. It is intentionally not coupled to an EC2
physical instance ID, because Macondo may replace the host during an operating
system or instance update while retaining its Elastic IP.

The workflow fails closed when zero or multiple running hosts match. The
`ON_PREMISES=true` SSH path remains unchanged as the explicit fallback.
