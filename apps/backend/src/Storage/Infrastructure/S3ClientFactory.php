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

    private function create(string $endpoint): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $this->config->region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => $this->config->pathStyle,
            'signature_version' => 'v4',
            'credentials' => [
                'key' => $this->config->accessKeyId,
                'secret' => $this->config->secretAccessKey,
            ],
            'http' => ['connect_timeout' => 5, 'timeout' => 120],
            'retries' => ['mode' => 'standard', 'max_attempts' => 3],
        ]);
    }
}
