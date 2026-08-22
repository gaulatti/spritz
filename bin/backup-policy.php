<?php

function spritz_backup_safe_database_name(string $database): string {
    return preg_replace('/[^A-Za-z0-9_.-]/', '-', $database);
}

function spritz_backup_filename(
    string $database,
    DateTimeImmutable $created_at,
    ?string $random_hex = null
): string {
    $random_hex = $random_hex ?? bin2hex(random_bytes(16));
    if (!preg_match('/^[a-f0-9]{32}$/', $random_hex)) {
        throw new InvalidArgumentException('Backup random segment must contain exactly 128 bits of lowercase hexadecimal entropy.');
    }

    return sprintf(
        '%s-%s-%s.sql.gz',
        spritz_backup_safe_database_name($database),
        $random_hex,
        $created_at->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z')
    );
}

function spritz_backup_keys(
    string $database,
    DateTimeImmutable $created_at,
    ?string $random_hex = null
): array {
    $utc = $created_at->setTimezone(new DateTimeZone('UTC'));
    $filename = spritz_backup_filename($database, $utc, $random_hex);
    $date_path = $utc->format('Y/m/d');
    $keys = [sprintf('backups/hourly/%s/%s', $date_path, $filename)];

    if ($utc->format('H') === '00') {
        $keys[] = sprintf('backups/daily/%s/%s', $date_path, $filename);
        if ($utc->format('N') === '7') {
            $keys[] = sprintf('backups/weekly/%s/%s', $date_path, $filename);
        }
    }

    return $keys;
}

function spritz_backup_key_is_protected(string $key): bool {
    return preg_match(
        '#^backups/(hourly|daily|weekly)/\d{4}/\d{2}/\d{2}/[A-Za-z0-9_.-]+-[a-f0-9]{32}-\d{8}T\d{6}Z\.sql\.gz$#',
        $key
    ) === 1;
}

function spritz_restore_target_is_isolated(string $target_database, ?string $source_database): bool {
    return preg_match('/^spritz_restore_[A-Za-z0-9_]+$/', $target_database) === 1
        && ($source_database === null || $source_database === '' || $target_database !== $source_database);
}
