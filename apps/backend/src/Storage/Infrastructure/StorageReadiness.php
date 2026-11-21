<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use Psr\Container\ContainerInterface;

final readonly class StorageReadiness
{
    public function __construct(private ContainerInterface $container) {}

    public function isConfigured(): bool
    {
        $key = getenv('S3_ACCESS_KEY_ID');
        return is_string($key) && $key !== '';
    }

    public function ready(): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }
        return $this->failureReason() === null;
    }

    public function failureReason(): ?string
    {
        try {
            $config = $this->container->get(S3Config::class);
            $clients = $this->container->get(S3ClientFactory::class);
            if (!$config instanceof S3Config || !$clients instanceof S3ClientFactory) {
                return 's3_configuration_invalid';
            }
            foreach (array_unique([$config->bucketStorage, $config->bucketEducation]) as $bucket) {
                $clients->assertBucket($bucket);
            }
            return null;
        } catch (\Throwable $error) {
            // The container can wrap constructor exceptions while resolving dependencies.
            return S3Failure::storage($error, bucketProbe: true)->reason;
        }
    }
}
