<?php

use Aws\Credentials\CredentialProvider;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;

function spritz_assumed_s3_client(string $region, string $role_arn, string $session_name): S3Client {
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

    $sts = new StsClient($client_config);
    $client_config['credentials'] = CredentialProvider::assumeRole([
        'client' => $sts,
        'assume_role_params' => [
            'RoleArn' => $role_arn,
            'RoleSessionName' => $session_name,
        ],
    ]);

    return new S3Client($client_config);
}
