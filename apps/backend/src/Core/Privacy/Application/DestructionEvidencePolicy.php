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
            $minimum = match ($unit) {
                'calendar_days', 'working_days' => 1096,
                'hours' => 26304,
                default => null,
            };
            if (!is_string($code) || !is_int($duration) || $minimum === null
                || $duration < $minimum
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
        return $this->configured()['code'];
    }

    public function retainUntil(DateTimeImmutable $executedAt): DateTimeImmutable
    {
        $configuration = $this->configured();
        $due = $this->calendar->dueAt(
            $configuration['unit'],
            $configuration['duration'],
            $executedAt,
        );
        if ($due < $executedAt->modify('+3 years')) {
            throw new DomainException('Destruction evidence retention is shorter than three years.');
        }
        return $due;
    }

    public function referenceHash(string $resourceType, string $resourceId): string
    {
        $configuration = $this->configured();
        return hash_hmac('sha256', $resourceType . ':' . $resourceId, $configuration['hmacKey']);
    }

    /** @return array{code:string, duration:int, unit:string, hmacKey:string} */
    private function configured(): array
    {
        $this->assertConfigured();
        /** @var array{code:string, duration:int, unit:string, hmacKey:string} $configuration */
        $configuration = $this->configuration;
        return $configuration;
    }

    private function assertConfigured(): void
    {
        if (!$this->available()) {
            throw new DomainException('Destruction evidence retention policy is unavailable.');
        }
    }
}
