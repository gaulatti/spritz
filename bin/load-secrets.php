<?php

require_once '/var/www/html/vendor/autoload.php';

use Aws\SecretsManager\SecretsManagerClient;

$secretArn = getenv('APP_SECRET_ARN');
if (!$secretArn) {
    exit(0);
}

$region = getenv('AWS_REGION') ?: 'us-east-1';
$secretKey = getenv('APP_SECRET_KEY') ?: null;

fwrite(STDERR, sprintf(
    "Loading config from Secrets Manager: secret=%s key=%s region=%s\n",
    $secretArn,
    $secretKey ?: '(none)',
    $region
));

try {
    $client = new SecretsManagerClient([
        'version' => 'latest',
        'region' => $region,
        'http' => [
            'connect_timeout' => 2,
            'timeout' => 4,
        ],
    ]);

    $response = $client->getSecretValue(['SecretId' => $secretArn]);
    $raw = json_decode($response['SecretString'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf(
        "Failed to load config from Secrets Manager %s: %s\n",
        $secretArn,
        $exception->getMessage()
    ));
    exit(0);
}

if ($secretKey) {
    $raw = $raw[$secretKey] ?? null;
    if (is_string($raw)) {
        try {
            $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf(
                "Secret key %s is not valid JSON in %s\n",
                $secretKey,
                $secretArn
            ));
            exit(0);
        }
    }
    if (!is_array($raw)) {
        fwrite(STDERR, sprintf(
            "Secret key %s was not found or is not an object in %s\n",
            $secretKey,
            $secretArn
        ));
        exit(0);
    }
}

foreach ($raw as $key => $value) {
    if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', (string) $key)) {
        fwrite(STDERR, sprintf("Skipping invalid env key from secret: %s\n", $key));
        continue;
    }

    $existing = getenv($key);
    if ($existing !== false && $existing !== '') {
        continue;
    }

    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    } elseif (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_SLASHES);
    } elseif ($value === null) {
        $value = '';
    } else {
        $value = (string) $value;
    }

    fwrite(STDERR, sprintf("Injecting %s from secret\n", $key));
    printf("export %s=%s\n", $key, escapeshellarg($value));
}
