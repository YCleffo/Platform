<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

final readonly class ControlProviderEgressPolicy
{
    /** @param array<string, array<string, mixed>> $decisions */
    public function __construct(private array $decisions)
    {
    }

    /** @param list<string> $categories */
    public function assertAllowed(string $provider, string $purpose, array $categories): void
    {
        $decision = $this->decisions[$provider] ?? null;
        if (!is_array($decision) || ($decision['status'] ?? null) !== 'allowed'
            || !is_string($decision['country'] ?? null)
            || preg_match('/^[A-Z]{2}$/', $decision['country']) !== 1
            || trim((string) ($decision['evidence_reference'] ?? '')) === ''
            || !in_array($purpose, $decision['purposes'] ?? [], true)
            || array_diff($categories, $decision['data_categories'] ?? []) !== []
            || ($decision['country'] !== 'RU' && ($decision['notification_status'] ?? null) !== 'notified')) {
            throw new \DomainException('Control Plane provider egress is not approved.');
        }
    }
}
