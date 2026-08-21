<?php

require_once __DIR__ . '/backup-policy.php';
require_once __DIR__ . '/backup-aws.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    run_restore();
}

function run_restore(): void {
    if (strtolower((string) getenv('ENV')) === 'production') {
        fail_restore('Restore is disabled in production.');
    }
    if (getenv('DB_RESTORE_CONFIRM') !== 'isolated-non-production') {
        fail_restore('DB_RESTORE_CONFIRM must explicitly select an isolated non-production restore.');
    }

    $bucket = restore_env_required('S3_UPLOADS_BUCKET');
    $key = restore_env_required('DB_RESTORE_KEY');
    $restore_role_arn = restore_env_required('DB_BACKUP_RESTORE_ROLE_ARN');
    $region = getenv('AWS_REGION') ?: 'us-east-1';
    $target_host = restore_env_required('DB_RESTORE_HOST');
    $target_database = restore_env_required('DB_RESTORE_NAME');
    $target_user = restore_env_required('DB_RESTORE_USER');
    $target_password = restore_env_required('DB_RESTORE_PASSWORD');
    $source_database = getenv('DB_NAME') ?: null;

    if (!spritz_backup_key_is_protected($key)) {
        fail_restore('Restore key does not satisfy the protected backup naming contract.');
    }
    if (!spritz_restore_target_is_isolated($target_database, $source_database)) {
        fail_restore('Restore database must be a distinct isolated database with the spritz_restore_ prefix.');
    }

    $local_path = tempnam('/tmp', 'spritz-restore-');
    if ($local_path === false) {
        fail_restore('Could not allocate temporary restore storage.');
    }

    try {
        $client = spritz_assumed_s3_client($region, $restore_role_arn, 'spritz-backup-restore');
        $result = $client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $local_path,
            'ChecksumMode' => 'ENABLED',
        ]);

        $expected_checksum = (string) ($result['ChecksumSHA256'] ?? '');
        $actual_checksum = hash_file('sha256', $local_path, true);
        if ($expected_checksum === '' || $actual_checksum === false || !hash_equals($expected_checksum, base64_encode($actual_checksum))) {
            throw new RuntimeException('Backup checksum verification failed.');
        }

        restore_gzip_to_database($local_path, $target_host, $target_database, $target_user, $target_password);
    } catch (Throwable $exception) {
        @unlink($local_path);
        fail_restore($exception->getMessage());
    }

    @unlink($local_path);
    fwrite(STDOUT, "Isolated database restore completed after SHA-256 verification.\n");
}

function restore_gzip_to_database(
    string $path,
    string $host,
    string $database,
    string $user,
    string $password
): void {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = mysqli_init();
    $flags = 0;
    if (defined('MYSQLI_CLIENT_SSL')) {
        $flags |= MYSQLI_CLIENT_SSL;
    }
    if (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')) {
        $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
    }
    $connection->real_connect($host, $user, $password, $database, null, null, $flags);
    $connection->set_charset('utf8mb4');

    $gzip = gzopen($path, 'rb');
    if ($gzip === false) {
        $connection->close();
        throw new RuntimeException('Backup is not a readable gzip stream.');
    }

    $statement = '';
    $quote = null;
    $escaped = false;
    while (!gzeof($gzip)) {
        $chunk = gzread($gzip, 1024 * 1024);
        if ($chunk === false) {
            gzclose($gzip);
            $connection->close();
            throw new RuntimeException('Backup stream failed during the isolated restore.');
        }

        $length = strlen($chunk);
        for ($index = 0; $index < $length; $index++) {
            $character = $chunk[$index];
            $statement .= $character;

            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($quote !== null) {
                if ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                continue;
            }
            if ($character === ';') {
                $sql = trim(substr($statement, 0, -1));
                if ($sql !== '') {
                    assert_supported_restore_statement($sql);
                    $connection->query($sql);
                }
                $statement = '';
            }
        }
    }

    gzclose($gzip);
    if (trim($statement) !== '') {
        $connection->close();
        throw new RuntimeException('Backup ended with an incomplete SQL statement.');
    }
    $connection->close();
}

function assert_supported_restore_statement(string $sql): void {
    $structure = restore_sql_structure($sql);
    if (str_contains($structure, '/*!')) {
        throw new RuntimeException(
            'Backup contains unsupported MySQL conditional comments for the isolated restore parser.'
        );
    }
    if (preg_match('/\bDELIMITER\b/i', $structure) === 1
        || preg_match('/\bCREATE\b[\s\S]*\b(PROCEDURE|FUNCTION|TRIGGER|EVENT)\b/i', $structure) === 1) {
        throw new RuntimeException(
            'Backup contains unsupported SQL constructs (DELIMITER, procedures, functions, triggers, or events) '
            . 'for the isolated restore parser.'
        );
    }
}

function restore_sql_structure(string $sql): string {
    $structure = '';
    $length = strlen($sql);
    $quote = null;
    $escaped = false;

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';

        if ($quote !== null) {
            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === $quote) {
                $quote = null;
            }
            $structure .= ' ';
            continue;
        }

        if ($character === "'" || $character === '"' || $character === '`') {
            $quote = $character;
            $structure .= ' ';
            continue;
        }
        if ($character === '/' && $next === '*' && ($sql[$index + 2] ?? '') === '!') {
            $structure .= '/*!';
            $index += 2;
            continue;
        }
        if ($character === '/' && $next === '*') {
            $end = strpos($sql, '*/', $index + 2);
            if ($end === false) {
                return $structure;
            }
            $structure .= ' ';
            $index = $end + 1;
            continue;
        }
        if ($character === '#' || ($character === '-' && $next === '-' && preg_match('/\s/', $sql[$index + 2] ?? '') === 1)) {
            $end = strpos($sql, "\n", $index + 1);
            if ($end === false) {
                return $structure;
            }
            $structure .= "\n";
            $index = $end;
            continue;
        }

        $structure .= $character;
    }

    return $structure;
}

function restore_env_required(string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        fail_restore(sprintf('Required restore environment variable %s is not set.', $name));
    }
    return $value;
}

function fail_restore(string $message): void {
    fwrite(STDERR, 'Database restore failed: ' . $message . PHP_EOL);
    exit(1);
}
