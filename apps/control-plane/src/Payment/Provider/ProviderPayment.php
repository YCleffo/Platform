<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

final readonly class ProviderPayment
{
    /** @param array<string, string> $metadata */
    public function __construct(
        public string $id,
        public string $status,
        public bool $paid,
        public int $amountMinor,
        public string $currency,
        public array $metadata,
        public ?string $confirmationUrl,
        public ?\DateTimeImmutable $capturedAt,
    ) {
    }
}
