<?php

require_once __DIR__ . '/../bin/backup-db.php';
require_once __DIR__ . '/../bin/restore-db.php';

$host = getenv('DRILL_DB_HOST') ?: 'spritz-g124-mysql';
$root_password = getenv('DRILL_DB_ROOT_PASSWORD') ?: 'restore-test-root';
$source_database = 'spritz_backup_source';
$target_database = 'spritz_restore_drill';
$backup_path = '/tmp/spritz-restore-drill.sql.gz';

$admin = new mysqli($host, 'root', $root_password);
$admin->query('DROP DATABASE IF EXISTS `' . $source_database . '`');
$admin->query('DROP DATABASE IF EXISTS `' . $target_database . '`');
$admin->query('CREATE DATABASE `' . $source_database . '`');
$admin->query('CREATE DATABASE `' . $target_database . '`');

$source = new mysqli($host, 'root', $root_password, $source_database);
$source->query('CREATE TABLE drill_items (id INT PRIMARY KEY, title VARCHAR(64) NOT NULL)');
$source->query("INSERT INTO drill_items (id, title) VALUES (1, 'first recovery row'), (2, 'second recovery row')");
$source->close();

dump_database_to_gzip($host, 'root', $root_password, $source_database, $backup_path);
$checksum_before = hash_file('sha256', $backup_path);
if ($checksum_before === false) {
    fwrite(STDERR, "restore drill could not checksum the generated backup\n");
    exit(1);
}

restore_gzip_to_database($backup_path, $host, $target_database, 'root', $root_password);
$checksum_after = hash_file('sha256', $backup_path);
if (!hash_equals($checksum_before, (string) $checksum_after)) {
    fwrite(STDERR, "restore drill changed the backup artifact\n");
    exit(1);
}

$restored = new mysqli($host, 'root', $root_password, $target_database);
$result = $restored->query('SELECT COUNT(*) AS total FROM drill_items');
$total = (int) $result->fetch_assoc()['total'];
$result->free();
$restored->close();

$admin->query('DROP DATABASE `' . $source_database . '`');
$admin->query('DROP DATABASE `' . $target_database . '`');
$admin->close();
@unlink($backup_path);

if ($total !== 2) {
    fwrite(STDERR, "restore drill did not recover the expected synthetic rows\n");
    exit(1);
}

fwrite(STDOUT, "isolated backup restore drill passed with SHA-256 integrity\n");
