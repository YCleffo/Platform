<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use Aws\S3\S3Client;

/** Public URLs are signed against the endpoint the client will actually use. */
final class S3ClientFactory
{
    private ?S3Client $internalClient = null;
    private ?S3Client $publicClient = null;

    public function __construct(private readonly S3Config $config) {}

    public function internal(): S3Client
    {
        return $this->internalClient ??= $this->create($this->config->internalEndpoint);
    }

    public function public(): S3Client
    {
        return $this->publicClient ??= $this->create($this->config->publicEndpoint);
    }

    public function assertBucket(string $bucket): void
    {
        try {
            $this->internal()->headBucket([
                'Bucket' => $bucket,
                '@http' => ['connect_timeout' => 1, 'timeout' => 2],
                '@retries' => 0,
            ]);
        } catch (\Throwable $error) {
            throw S3Failure::storage($error, bucketProbe: true);
        }
    }

    private function create(string $endpoint): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $this->config->region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => $this->config->pathStyle,
            'signature_version' => 'v4',
            'credentials' => $this->config->credentials(),
            'http' => [
                'connect_timeout' => min(5, $this->config->requestTimeoutSeconds),
                'timeout' => $this->config->requestTimeoutSeconds,
            ],
            'retries' => ['mode' => 'standard', 'max_attempts' => $this->config->maxAttempts],
        ]);
    }
}
