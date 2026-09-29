<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DateTimeImmutable;

/** References a deployment verification record; location strings alone are not proof. */
final readonly class DataResidencyEvidence
{
    /** @param array<string, mixed> $configuration */
    public function __construct(private array $configuration = []) {}

    public function complete(): bool
    {
        foreach (['primaryDbCountry', 'backupCountry', 'objectStorageCountry',
            'logStorageCountry', 'cacheCountry'] as $key) {
            if (!is_string($this->configuration[$key] ?? null)
                || preg_match('/^[A-Z]{2}$/D', $this->configuration[$key]) !== 1) {
                return false;
            }
        }
        $reference = $this->configuration['verificationReference'] ?? null;
        $verified = $this->configuration['verifiedAt'] ?? null;
        if (!is_string($reference) || trim($reference) === '' || !is_string($verified)) {
            return false;
        }
        try {
            return new DateTimeImmutable($verified) <= new DateTimeImmutable('now');
        } catch (\Throwable) {
            return false;
        }
    }

    public function country(string $component): ?string
    {
        $value = $this->configuration[$component] ?? null;
        return is_string($value) && preg_match('/^[A-Z]{2}$/D', $value) === 1 ? $value : null;
    }
}
