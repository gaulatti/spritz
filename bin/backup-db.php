<?php

require_once __DIR__ . '/backup-policy.php';
require_once __DIR__ . '/backup-aws.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    run_backup();
}

function run_backup(): void {
    load_pid1_environment();

    $db_host = env_required('DB_HOST');
    $db_name = env_required('DB_NAME');
    $db_user = env_required('DB_USER');
    $db_password = env_required('DB_PASSWORD');
    $bucket = env_required('S3_UPLOADS_BUCKET');
    $region = getenv('AWS_REGION') ?: 'us-east-1';
    $writer_role_arn = env_required('DB_BACKUP_WRITER_ROLE_ARN');
    $created_at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $keys = spritz_backup_keys($db_name, $created_at);
    $filename = basename($keys[0]);
    $local_path = '/tmp/' . $filename;

    fwrite(STDOUT, "Starting database backup.\n");

    $uploaded_tiers = [];
    try {
        dump_database_to_gzip($db_host, $db_user, $db_password, $db_name, $local_path);
        $checksum = hash_file('sha256', $local_path, true);
        if ($checksum === false) {
            throw new RuntimeException('Could not calculate the backup checksum.');
        }
        foreach ($keys as $key) {
            upload_backup_to_s3($local_path, $bucket, $key, $region, $writer_role_arn, $checksum);
            $uploaded_tiers[] = backup_tier_for_key($key);
        }
    } catch (Throwable $exception) {
        @unlink($local_path);
        fail(backup_failure_message($exception->getMessage(), $uploaded_tiers, count($keys)));
    }

    $size = filesize($local_path);
    @unlink($local_path);
    fwrite(STDOUT, sprintf("Database backup uploaded: copies=%d bytes=%d checksum=sha256\n", count($keys), $size));
}

function backup_tier_for_key(string $key): string {
    if (preg_match('#^backups/(hourly|daily|weekly)/#', $key, $matches) !== 1) {
        return 'unknown';
    }

    return $matches[1];
}

function backup_failure_message(string $message, array $uploaded_tiers, int $expected_copies): string {
    if (empty($uploaded_tiers)) {
        return sprintf('%s Completed copies before failure: 0/%d.', $message, $expected_copies);
    }

    return sprintf(
        '%s Completed copies before failure: %d/%d (tiers: %s).',
        $message,
        count($uploaded_tiers),
        $expected_copies,
        implode(', ', $uploaded_tiers)
    );
}

function dump_database_to_gzip(string $host, string $user, string $password, string $database, string $path): void {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn = mysqli_init();
    $flags = 0;
    if (defined('MYSQLI_CLIENT_SSL')) {
        $flags |= MYSQLI_CLIENT_SSL;
    }
    if (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')) {
        $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
    }

    $conn->real_connect($host, $user, $password, $database, null, null, $flags);
    $conn->set_charset('utf8mb4');

    $gz = gzopen($path, 'wb9');
    if ($gz === false) {
        throw new RuntimeException('Could not open temporary gzip file for writing.');
    }

    try {
        // This generator intentionally emits a restricted SQL subset understood by
        // restore-db.php: ordinary semicolon-terminated DDL/DML only. It never emits
        // DELIMITER changes, stored routines, triggers, events, or conditional comments.
        gzwrite($gz, "-- Spritz database backup\n");
        gzwrite($gz, '-- Generated at ' . gmdate('c') . "\n");
        gzwrite($gz, '-- Database: ' . $database . "\n\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
        gzwrite($gz, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");

        $tables = list_database_objects($conn);

        foreach ($tables['tables'] as $table) {
            write_table_schema($conn, $gz, $table);
            write_table_rows($conn, $gz, $table);
        }

        foreach ($tables['views'] as $view) {
            write_view_schema($conn, $gz, $view);
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        gzclose($gz);
        $conn->close();
    }

    if (!is_readable($path) || filesize($path) === 0) {
        throw new RuntimeException('Database dump produced an empty or unreadable file.');
    }
}

function list_database_objects(mysqli $conn): array {
    $result = $conn->query('SHOW FULL TABLES');
    $objects = ['tables' => [], 'views' => []];

    while ($row = $result->fetch_array(MYSQLI_NUM)) {
        $name = (string) $row[0];
        $type = strtoupper((string) ($row[1] ?? 'BASE TABLE'));
        if ($type === 'VIEW') {
            $objects['views'][] = $name;
        } else {
            $objects['tables'][] = $name;
        }
    }

    $result->free();
    return $objects;
}

function write_table_schema(mysqli $conn, $gz, string $table): void {
    $result = $conn->query('SHOW CREATE TABLE ' . sql_identifier($table));
    $row = $result->fetch_array(MYSQLI_NUM);
    $result->free();

    if (!$row || empty($row[1])) {
        throw new RuntimeException('Could not read schema for table ' . $table);
    }

    gzwrite($gz, 'DROP TABLE IF EXISTS ' . sql_identifier($table) . ";\n");
    gzwrite($gz, $row[1] . ";\n\n");
}

function write_view_schema(mysqli $conn, $gz, string $view): void {
    $result = $conn->query('SHOW CREATE VIEW ' . sql_identifier($view));
    $row = $result->fetch_array(MYSQLI_NUM);
    $result->free();

    if (!$row || empty($row[1])) {
        throw new RuntimeException('Could not read schema for view ' . $view);
    }

    gzwrite($gz, 'DROP VIEW IF EXISTS ' . sql_identifier($view) . ";\n");
    gzwrite($gz, $row[1] . ";\n\n");
}

function write_table_rows(mysqli $conn, $gz, string $table): void {
    $result = $conn->query('SELECT * FROM ' . sql_identifier($table), MYSQLI_USE_RESULT);
    $fields = $result->fetch_fields();
    $columns = array_map(fn($field) => sql_identifier($field->name), $fields);
    $batch = [];

    while ($row = $result->fetch_assoc()) {
        $values = [];
        foreach ($fields as $field) {
            $value = $row[$field->name];
            $values[] = $value === null ? 'NULL' : "'" . $conn->real_escape_string((string) $value) . "'";
        }

        $batch[] = '(' . implode(', ', $values) . ')';
        if (count($batch) >= 100) {
            flush_insert_batch($gz, $table, $columns, $batch);
            $batch = [];
        }
    }

    $result->free();

    if (!empty($batch)) {
        flush_insert_batch($gz, $table, $columns, $batch);
    }

    gzwrite($gz, "\n");
}

function flush_insert_batch($gz, string $table, array $columns, array $rows): void {
    if (empty($rows)) {
        return;
    }

    gzwrite(
        $gz,
        'INSERT INTO ' . sql_identifier($table) . ' (' . implode(', ', $columns) . ") VALUES\n" .
        implode(",\n", $rows) . ";\n"
    );
}

function upload_backup_to_s3(
    string $path,
    string $bucket,
    string $key,
    string $region,
    string $writer_role_arn,
    string $checksum
): void {
    $client = spritz_assumed_s3_client($region, $writer_role_arn, 'spritz-backup-writer');
    $result = $client->putObject([
        'Bucket' => $bucket,
        'Key' => $key,
        'SourceFile' => $path,
        'ContentType' => 'application/gzip',
        'ChecksumAlgorithm' => 'SHA256',
        'ChecksumSHA256' => base64_encode($checksum),
        'ServerSideEncryption' => 'AES256',
    ]);

    if (!hash_equals(base64_encode($checksum), (string) ($result['ChecksumSHA256'] ?? ''))) {
        throw new RuntimeException('S3 did not confirm the expected SHA-256 checksum.');
    }
}

function sql_identifier(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function load_pid1_environment(): void {
    $path = '/proc/1/environ';
    if (!is_readable($path)) {
        return;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return;
    }

    foreach (explode("\0", trim($contents, "\0")) as $entry) {
        if ($entry === '' || strpos($entry, '=') === false) {
            continue;
        }

        [$name, $value] = explode('=', $entry, 2);
        if ($name === '') {
            continue;
        }

        putenv($entry);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function env_required(string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        fail(sprintf('Required environment variable %s is not set.', $name));
    }

    return $value;
}

function fail(string $message): void {
    fwrite(STDERR, 'DB backup failed: ' . $message . PHP_EOL);
    exit(1);
}
