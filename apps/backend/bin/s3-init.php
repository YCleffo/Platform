<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$required = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || $value === '') throw new RuntimeException('Missing initializer setting ' . $name);
    return $value;
};
$client = new S3Client(['version' => 'latest', 'region' => getenv('S3_REGION') ?: 'us-east-1',
    'endpoint' => $required('S3_ENDPOINT_INTERNAL'), 'use_path_style_endpoint' => true,
    'credentials' => ['key' => $required('S3_INIT_ACCESS_KEY_ID'), 'secret' => $required('S3_INIT_SECRET_ACCESS_KEY')],
    'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
    'http' => ['connect_timeout' => 3, 'timeout' => 20], 'retries' => 2]);
$origins = array_values(array_filter(array_map('trim', explode(',', getenv('S3_CORS_ORIGINS') ?: ''))));
foreach ($origins as $origin) {
    if (!preg_match('~^https?://[^/]+$~', $origin) || str_contains($origin, '*')) throw new RuntimeException('S3 CORS requires explicit origins.');
}
try {
    foreach ([$required('S3_BUCKET_STORAGE'), $required('S3_BUCKET_EDUCATION')] as $bucket) {
        try {
            $client->headBucket(['Bucket' => $bucket]);
        } catch (AwsException $error) {
            if ($error->getStatusCode() !== 404) {
                throw $error;
            }
            $client->createBucket(['Bucket' => $bucket]);
        }
        $client->putBucketCors(['Bucket' => $bucket, 'CORSConfiguration' => ['CORSRules' => [[
            'AllowedOrigins' => $origins, 'AllowedMethods' => ['GET', 'PUT', 'HEAD'],
            'AllowedHeaders' => ['content-type', 'content-md5', 'x-amz-checksum-sha256', 'range'],
            'ExposeHeaders' => ['ETag', 'Content-Length', 'Content-Range', 'x-amz-checksum-sha256'], 'MaxAgeSeconds' => 300,
        ]]]]);
        $client->headBucket(['Bucket' => $bucket]);
    }
} catch (AwsException $error) {
    $reason = match ($error->getAwsErrorCode()) {
        'InvalidAccessKeyId', 'InvalidToken', 'ExpiredToken' => 'initializer credentials were rejected',
        'SignatureDoesNotMatch' => 'the request signature was rejected',
        'AccessDenied' => 'the initializer cannot create buckets or configure CORS',
        'NoSuchBucket' => 'the test bucket could not be found',
        default => match ($error->getStatusCode()) {
            401, 403 => 'initializer access was rejected',
            404 => 'the bucket could not be found',
            default => 'the S3 service did not complete the bucket operation',
        },
    };
    fwrite(STDERR, "S3 initialization failed: $reason.\n");
    exit(1);
}
echo "S3 buckets and explicit browser CORS initialized.\n";
