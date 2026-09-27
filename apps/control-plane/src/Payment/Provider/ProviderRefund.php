<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

final readonly class ProviderRefund
{
    public function __construct(
        public string $id,
        public string $providerPaymentId,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?\DateTimeImmutable $completedAt,
        public ?string $localRefundId = null,
    ) {
    }
}
