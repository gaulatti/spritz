<?php

use Aws\S3\S3Client;

load_pid1_environment();

$db_host = env_required('DB_HOST');
$db_name = env_required('DB_NAME');
$db_user = env_required('DB_USER');
$db_password = env_required('DB_PASSWORD');
$bucket = env_required('S3_UPLOADS_BUCKET');
$region = getenv('AWS_REGION') ?: 'us-east-1';
$prefix = trim(getenv('DB_BACKUP_PREFIX') ?: 'backups/mysql', '/');
$timestamp = gmdate('Ymd\THis\Z');
$date_path = gmdate('Y/m/d');
$safe_db_name = preg_replace('/[^A-Za-z0-9_.-]/', '-', $db_name);
$filename = sprintf('%s-%s.sql.gz', $safe_db_name, $timestamp);
$local_path = '/tmp/' . $filename;
$key = sprintf('%s/%s/%s/%s', $prefix, $safe_db_name, $date_path, $filename);

fwrite(STDOUT, sprintf("Starting DB backup: database=%s bucket=%s key=%s\n", $db_name, $bucket, $key));

try {
    dump_database_to_gzip($db_host, $db_user, $db_password, $db_name, $local_path);
    upload_backup_to_s3($local_path, $bucket, $key, $region);
} catch (Throwable $exception) {
    @unlink($local_path);
    fail($exception->getMessage());
}

$size = filesize($local_path);
@unlink($local_path);
fwrite(STDOUT, sprintf("DB backup uploaded: s3://%s/%s (%d bytes)\n", $bucket, $key, $size));

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
        gzwrite($gz, "-- Spritz database backup\n");
        gzwrite($gz, '-- Generated at ' . gmdate('c') . "\n");
        gzwrite($gz, '-- Database: ' . $database . "\n\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
        gzwrite($gz, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        gzwrite($gz, 'CREATE DATABASE IF NOT EXISTS ' . sql_identifier($database) . ";\n");
        gzwrite($gz, 'USE ' . sql_identifier($database) . ";\n\n");

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

function upload_backup_to_s3(string $path, string $bucket, string $key, string $region): void {
    $autoload = '/var/www/html/vendor/autoload.php';
    if (!is_readable($autoload)) {
        throw new RuntimeException('AWS SDK autoload file is not available.');
    }
    require_once $autoload;

    $client_config = [
        'version' => 'latest',
        'region' => $region,
    ];

    $credentials_file = getenv('AWS_SHARED_CREDENTIALS_FILE') ?: '/var/www/.aws/credentials';
    if (is_readable($credentials_file)) {
        putenv('AWS_SHARED_CREDENTIALS_FILE=' . $credentials_file);
        $client_config['profile'] = getenv('AWS_PROFILE') ?: 'default';
    }

    putenv('AWS_EC2_METADATA_DISABLED=true');

    $client = new S3Client($client_config);
    $client->putObject([
        'Bucket' => $bucket,
        'Key' => $key,
        'SourceFile' => $path,
        'ContentType' => 'application/gzip',
        'ServerSideEncryption' => 'AES256',
    ]);
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
