<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Time\Application\ClockInterface;
use DomainException;

/** Every external egress must name a registered active provider and covered data. */
final readonly class CrossBorderTransferPolicy
{
    public function __construct(private ProcessorRegistry $processors,
        private DataRecipientRegistry $recipients,
        private CrossBorderTransferRegistry $transfers, private ClockInterface $clock) {}

    /** @param list<string> $dataCategories */
    public function assertEgressAllowed(string $providerCode, string $purposeCode,
        array $dataCategories): void
    {
        $now = $this->clock->now();
        $provider = $this->processors->get($providerCode) ?? $this->recipients->get($providerCode);
        if ($provider === null || !$provider->activeAt($now)
            || !$provider->securityRequirementsConfirmed || !$provider->localizationCompatible
            || !in_array($purposeCode, $provider->purposes, true)
            || array_diff($dataCategories, $provider->dataCategories) !== []) {
            throw new DomainException('External provider is not approved for this egress.');
        }
        if ($provider->country === 'RU') {
            return;
        }
        $transfer = $this->transfers->find($providerCode, $provider->country, $purposeCode);
        if ($transfer === null || !$transfer->permits($now)
            || array_diff($dataCategories, $transfer->dataCategories) !== []) {
            throw new DomainException('Cross-border transfer is not approved.');
        }
    }
}
