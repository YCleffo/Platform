<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DomainException;

/** Missing deployment duration means retain; evidence uses its own per-row policy. */
final readonly class PrivacyCleanupPolicy
{
    /** @param array<string, mixed> $configuration */
    public function __construct(private array $configuration = []) {}

    public function days(string $category): ?int
    {
        if ($category === 'destruction_evidence') { return null; }
        $key = match ($category) {
            'audit' => 'auditDays',
            'outbox' => 'outboxDays', 'inbox' => 'inboxDays',
            'dsar_events', 'dsar_deadlines', 'dsar_executions' => 'dsarDays',
            'processing_blocks' => 'blockDays', 'legal_holds' => 'holdDays',
            'retention_decisions' => 'decisionDays', 'auth_sessions' => 'sessionDays',
            default => throw new DomainException('Unknown privacy cleanup category.'),
        };
        $value = $this->configuration[$key] ?? null;
        if ($value === null || $value === '') { return null; }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,5}$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > 365000) {
            throw new DomainException('Invalid privacy cleanup duration.');
        }
        return $value;
    }
}
