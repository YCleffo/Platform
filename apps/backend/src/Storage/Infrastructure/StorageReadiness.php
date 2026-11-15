<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use Psr\Container\ContainerInterface;

final readonly class StorageReadiness
{
    public function __construct(private ContainerInterface $container) {}

    public function ready(): bool
    {
        try {
            $config = $this->container->get(S3Config::class);
            $client = $this->container->get(S3ClientFactory::class)->internal();
            foreach (array_unique([$config->bucketStorage, $config->bucketEducation]) as $bucket) {
                $client->headBucket(['Bucket' => $bucket,
                    '@http' => ['connect_timeout' => 1, 'timeout' => 2], '@retries' => 0]);
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
