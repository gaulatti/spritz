<?php

require_once __DIR__ . '/../bin/backup-policy.php';
require_once __DIR__ . '/../bin/backup-db.php';

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$hourly_time = new DateTimeImmutable('2026-08-19T13:00:00-04:00');
$hourly_keys = spritz_backup_keys('wordpress prod', $hourly_time, '00112233445566778899aabbccddeeff');
assert_same(1, count($hourly_keys), 'A non-midnight run must create only an hourly recovery point.');
assert_same(
    'backups/hourly/2026/08/19/wordpress-prod-00112233445566778899aabbccddeeff-20260819T170000Z.sql.gz',
    $hourly_keys[0],
    'Hourly key does not match the protected naming contract.'
);
assert_true(spritz_backup_key_is_protected($hourly_keys[0]), 'Hourly key must be recognized as protected.');

$daily_time = new DateTimeImmutable('2026-08-20T00:00:00Z');
$daily_keys = spritz_backup_keys('wordpress', $daily_time, 'ffeeddccbbaa99887766554433221100');
assert_same(2, count($daily_keys), 'UTC midnight must create hourly and daily recovery points.');
assert_true(str_starts_with($daily_keys[1], 'backups/daily/'), 'The second midnight key must use the daily tier.');

$weekly_time = new DateTimeImmutable('2026-08-23T00:00:00Z');
$weekly_keys = spritz_backup_keys('wordpress', $weekly_time, '0123456789abcdef0123456789abcdef');
assert_same(3, count($weekly_keys), 'Sunday UTC midnight must create hourly, daily, and weekly recovery points.');
assert_true(str_starts_with($weekly_keys[2], 'backups/weekly/'), 'The third Sunday key must use the weekly tier.');

$generated_one = spritz_backup_filename('wordpress', $weekly_time);
$generated_two = spritz_backup_filename('wordpress', $weekly_time);
assert_true($generated_one !== $generated_two, 'Every backup run must generate an independent random segment.');
assert_true(
    preg_match('/^wordpress-[a-f0-9]{32}-20260823T000000Z\.sql\.gz$/', $generated_one) === 1,
    'Generated filenames must contain at least 128 bits of hexadecimal entropy.'
);

assert_true(!spritz_backup_key_is_protected('backups/mysql/legacy.sql.gz'), 'Legacy keys must not satisfy the protected naming contract.');
assert_true(spritz_restore_target_is_isolated('spritz_restore_drill', 'wordpress'), 'A distinct restore database must be accepted.');
assert_true(!spritz_restore_target_is_isolated('wordpress', 'wordpress'), 'The production database name must be rejected.');
assert_true(!spritz_restore_target_is_isolated('restore_drill', 'wordpress'), 'Restore databases without the isolation prefix must be rejected.');

$backup_source = file_get_contents(__DIR__ . '/../bin/backup-db.php');
assert_true($backup_source !== false, 'Backup source must be readable.');
assert_true(!str_contains($backup_source, 's3://%s/%s'), 'Backup logs must not disclose bucket or object identifiers.');
assert_same('daily', backup_tier_for_key($daily_keys[1]), 'Backup reporting must identify completed tiers without exposing keys.');
assert_same(
    'upload failed Completed copies before failure: 2/3 (tiers: hourly, daily).',
    backup_failure_message('upload failed', ['hourly', 'daily'], 3),
    'Partial backup failure reporting must identify safe completed tiers.'
);
assert_true(
    !str_contains(backup_failure_message('upload failed', ['hourly'], 3), $hourly_keys[0]),
    'Partial backup failure reporting must not expose object keys.'
);

fwrite(STDOUT, "backup policy tests passed\n");
