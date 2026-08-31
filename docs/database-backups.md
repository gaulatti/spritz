# Spritz database backup and restore runbook

This runbook is the reviewed operational path for G-124. Never print, paste, or
attach bucket names, object keys, database contents, or credentials to logs,
tickets, pull requests, or chat.

## Deployment order

1. Deploy the Loredana infrastructure change first. Confirm that both CloudFront
   distributions receive an explicit S3 `Deny` for `backups/*`, lifecycle rules
   exist for all three retention tiers, and the backup audit trail is logging.
2. In the Spritz application secret, set `DB_SECRET_ARN` to the Macondo-owned
   `llanquihue` secret and set `DB_BACKUP_WRITER_ROLE_ARN` and
   `DB_BACKUP_RESTORE_ROLE_ARN` from the reviewed Loredana stack outputs. The
   Cumulus instance role reads both secrets and assumes only the writer role for
   scheduled backups; no static AWS credentials are mounted in production.
3. Deploy Spritz. The container schedules database backups every three hours at
   minute zero. Confirm one run reports only its copy count, byte count, and
   checksum algorithm. It must not log the database, bucket, or key.
   Confirm the private metrics endpoint records a successful backup and bounded
   duration without exposing any backup identifier.
4. Verify new objects by metadata only. Their keys must remain beneath
   `backups/hourly/`, `backups/daily/`, or `backups/weekly/`; filenames must
   contain a fresh 32-character hexadecimal random segment.

Do not deploy Spritz before the S3 deny is active. Doing so would create new
objects before the delivery boundary is contained.

## Existing-object migration

Use the Loredana migration role for this one-time operation. Start with a dry
inventory that emits only total object count, total bytes, oldest timestamp,
and newest timestamp. Do not request or download object bodies.

Build an explicitly reviewed migration manifest locally:

- retain every hourly recovery point from the newest 48 hours;
- retain one recovery point per UTC day for the newest 14 days;
- retain one recovery point per UTC week for the newest 8 weeks;
- retain no monthly or archive tier;
- mark every other existing backup for deletion.

Copy retained objects server-side into the matching protected tier with
SSE-S3 and SHA-256 checksum calculation enabled. For every copy, verify via
object metadata that its byte length matches the source and that S3 returned a
SHA-256 checksum. Only after every retained copy passes verification may the
reviewed deletion manifest be executed. Re-run the metadata-only inventory and
confirm that no legacy-prefix or expired objects remain.

The migration is intentionally not automatic at stack deployment: deletion of
recovery points requires a separately reviewed manifest and must not be hidden
inside CloudFormation.

## CloudFront containment verification

After the S3 policy deployment, invalidate `/backups/*` on both distributions.
Use a former backup path supplied privately to the operator and verify non-2xx
responses through both distribution domains, including at least two distinct
query-string variants. Repeat from more than one network or edge location.
Never include the tested path in command output retained as evidence.

Also inventory the media bucket by top-level prefix and metadata only. Escalate
any operational artifact outside the documented public media, generated HTML,
JSON, and protected backup prefixes before deleting it.

## Isolated restore drill

Select one retained object privately and create an empty non-production MySQL
database whose name begins with `spritz_restore_`. Set the restore variables in
the operator shell without echoing their values:

- `ENV` must not be `production`;
- `DB_RESTORE_CONFIRM=isolated-non-production`;
- `DB_RESTORE_KEY` identifies the retained protected object;
- `DB_RESTORE_NAME` identifies the isolated database;
- the remaining `DB_RESTORE_*` variables provide the isolated database access;
- `DB_BACKUP_RESTORE_ROLE_ARN` selects the read-only restore role.

Run `php /usr/local/bin/restore-db.php`. The command downloads through the
restore role, verifies S3's SHA-256 checksum, refuses the production database,
and restores without printing content or identifiers. Validate representative
row counts and application invariants inside the isolated database, then remove
the isolated database according to the approved test-data procedure.

The restore parser accepts only the restricted SQL emitted by `backup-db.php`:
semicolon-terminated table/view DDL and row DML outside string literals. It
rejects `DELIMITER` changes, stored procedures or functions, triggers, events,
and MySQL conditional comments. If backup generation changes to emit any of
those constructs, the restore parser and its guardrail tests must be updated in
the same reviewed change.

Record only the restore timestamp, selected retention tier, checksum success,
and pass/fail result. Do not record the object key or database contents.
