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
            || ($decision['country'] !== 'RU' && !$this->crossBorderAllowed($decision))) {
            throw new \DomainException('Control Plane provider egress is not approved.');
        }
    }

    /** @param array<string, mixed> $decision */
    private function crossBorderAllowed(array $decision): bool
    {
        if (!in_array($decision['adequacy_status'] ?? null, ['adequate', 'non_adequate'], true)
            || !in_array($decision['regulator_decision'] ?? null, ['pending', 'no_restriction'], true)
            || !is_string($decision['notification_submitted_at'] ?? null)
            || trim($decision['notification_submitted_at']) === ''
            || !is_string($decision['notification_evidence_reference'] ?? null)
            || trim($decision['notification_evidence_reference']) === '') {
            return false;
        }
        try {
            $submitted = new \DateTimeImmutable((string) ($decision['notification_submitted_at'] ?? ''));
            $now = new \DateTimeImmutable();
            if ($submitted > $now) {
                return false;
            }
            if ($decision['adequacy_status'] === 'non_adequate') {
                if (!is_string($decision['regulator_review_until'] ?? null)
                    || trim($decision['regulator_review_until']) === '') {
                    return false;
                }
                $until = new \DateTimeImmutable((string) ($decision['regulator_review_until'] ?? ''));
                return $now >= $until && $until >= $submitted;
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
