<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use Psr\Container\ContainerInterface;

final readonly class StorageReadiness
{
    public function __construct(private ContainerInterface $container) {}

    public function ready(): bool
    {
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
