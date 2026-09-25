<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

final readonly class ProviderPaymentRequest
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
        public string $providerRequestKey,
        public string $description,
        public string $paymentId,
        public string $attemptId,
        public string $invoiceId,
    ) {
    }
}
