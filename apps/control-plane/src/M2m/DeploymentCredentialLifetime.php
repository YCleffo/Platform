<?php

declare(strict_types=1);

namespace ControlPlane\M2m;

/** Deployment runtime credential lifetime configured outside application code. */
final readonly class DeploymentCredentialLifetime
{
    private int $days;

    public function __construct(string $ttlDays)
    {
        if (!preg_match('/\A[1-9][0-9]{0,2}\z/D', $ttlDays)
            || (int) $ttlDays > 365) {
            throw new \DomainException('Deployment credential TTL is not configured.');
        }
        $this->days = (int) $ttlDays;
    }

    public function expiresAt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $this->days . ' days')->format(DATE_ATOM);
    }
}
