<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

final readonly class RefundProviderRequest
{
    public function __construct(
        public string $providerRequestKey,
        public string $providerPaymentId,
        public int $amountMinor,
        public string $currency,
        public string $refundId,
    ) {
    }
}
