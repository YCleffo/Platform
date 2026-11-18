<?php

declare(strict_types=1);

namespace App\Tests\Storage02;

use App\Storage\Infrastructure\S3Config;
use RuntimeException;

final class IsolatedS3Runtime
{
    public static function assertConfigured(S3Config $config): void
    {
        if (getenv('APP_ENV') !== 'test' || getenv('TEST_ISOLATED_RUNTIME') !== '1'
            || getenv('DB_HOST') !== 'postgres-test' || getenv('DB_NAME') !== 'platform_test'
            || getenv('REDIS_HOST') !== 'redis-test') {
            throw new RuntimeException('Dedicated local S3 test runtime required.');
        }

        $localEducation = getenv('EDUCATION_LOCAL_FIXTURE') === '1'
            && $config->internalEndpoint === 'http://seaweedfs-test:8333'
            && $config->bucketStorage === 'education-local-test-storage'
            && $config->bucketEducation === 'education-local-test-education';
        $storage02 = getenv('STORAGE02_TEST_RUNTIME') === '1'
            && $config->internalEndpoint === 'http://seaweedfs:8333'
            && $config->bucketStorage === 'storage02-test-storage'
            && $config->bucketEducation === 'storage02-test-education';

        $public = parse_url($config->publicEndpoint);
        if ((!$localEducation && !$storage02) || !is_array($public)
            || ($public['scheme'] ?? null) !== 'http'
            || !in_array($public['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            || !isset($public['port'])) {
            throw new RuntimeException('Use the dedicated local S3 service and its test buckets.');
        }
    }
}
