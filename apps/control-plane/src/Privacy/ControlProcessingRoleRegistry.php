<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

final readonly class ControlProcessingRoleRegistry
{
    /** @param array<string, array<string, mixed>> $definitions */
    public function __construct(private array $definitions)
    {
        foreach ($definitions as $purpose => $definition) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $purpose) !== 1
                || !in_array($definition['role'] ?? '', ['operator', 'processor_by_instruction'], true)
                || trim($definition['operator_reference'] ?? '') === ''
                || ($definition['data_categories'] ?? []) === []
                || ($definition['operations'] ?? []) === []
                || !isset($definition['security_confirmed'], $definition['incident_notification'])
                || trim($definition['deletion_or_return'] ?? '') === ''
                || ($definition['role'] === 'processor_by_instruction' && trim($definition['instruction_reference'] ?? '') === '')) {
                throw new \InvalidArgumentException('Invalid Control Plane processing role.');
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function forPurpose(string $purpose): ?array
    {
        return $this->definitions[$purpose] ?? null;
    }

    public function complete(): bool
    {
        return $this->definitions !== [];
    }
}
