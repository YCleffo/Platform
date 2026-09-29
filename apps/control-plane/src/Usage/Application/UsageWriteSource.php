<?php

declare(strict_types=1);

namespace ControlPlane\Usage\Application;

use Symfony\Component\Uid\Uuid;

final readonly class UsageWriteSource
{
    private function __construct(public string $sourceType, public string $sourceId) {}

    public static function deployment(string $deploymentId): self
    {
        if (!Uuid::isValid($deploymentId)) {
            throw new \InvalidArgumentException('Invalid deployment source.');
        }
        return new self('deployment', strtolower($deploymentId));
    }

    public function namespaceKey(string $key): string
    {
        return 'm2m.' . $this->sourceType . '.' . $this->sourceId . '.' . hash('sha256', $key);
    }
}
