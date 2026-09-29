<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use DateTimeImmutable;
use DomainException;

final readonly class DestructionEvidencePolicy
{
    /** @param array<string, mixed> $configuration */
    public function __construct(private PrivacyDeadlinePolicy $calendar, private array $configuration = []) {}

    public function available(): bool
    {
        $code = $this->configuration['code'] ?? null;
        $duration = $this->configuration['duration'] ?? null;
        $unit = $this->configuration['unit'] ?? null;
        $key = $this->configuration['hmacKey'] ?? null;
        try {
            if (!is_string($code) || !is_int($duration) || $duration < 1
                || !in_array($unit, ['calendar_days', 'working_days', 'hours'], true)
                || !is_string($key) || strlen($key) < 32) {
                return false;
            }
            MachineCode::assert($code);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function code(): string
    {
        $this->assertConfigured();
        return $this->configuration['code'];
    }

    public function retainUntil(DateTimeImmutable $executedAt): DateTimeImmutable
    {
        $this->assertConfigured();
        return $this->calendar->dueAt($this->configuration['unit'],
            $this->configuration['duration'], $executedAt);
    }

    public function referenceHash(string $resourceType, string $resourceId): string
    {
        $this->assertConfigured();
        return hash_hmac('sha256', $resourceType . ':' . $resourceId, $this->configuration['hmacKey']);
    }

    private function assertConfigured(): void
    {
        if (!$this->available()) {
            throw new DomainException('Destruction evidence retention policy is unavailable.');
        }
    }
}
