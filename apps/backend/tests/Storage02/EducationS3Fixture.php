<?php

declare(strict_types=1);

namespace App\Tests\Storage02;

use App\Storage\Infrastructure\S3ClientFactory;
use App\Storage\Infrastructure\S3Config;
use App\Storage\Infrastructure\S3Objects;
use RuntimeException;

final class EducationS3Fixture
{
    /** @param list<string> $keys All keys must have been created by the calling test. */
    public static function removeOwned(array $keys): void
    {
        $config = new S3Config();
        IsolatedS3Runtime::assertConfigured($config);
        $client = (new S3ClientFactory($config))->internal();
        foreach (array_unique($keys) as $key) {
            $client->deleteObject(['Bucket' => $config->bucketEducation,'Key' => S3Objects::objectKey($key)]);
        }
    }

    public static function bytes(array $download): string
    {
        if (!is_resource($download['stream'] ?? null)) {
            throw new RuntimeException('A verified stream is required.');
        }
        try {
            $content = stream_get_contents($download['stream']);
            if (!is_string($content)) {
                throw new RuntimeException('Cannot read verified download.');
            }
            return $content;
        } finally {
            fclose($download['stream']);
        }
    }
}
