<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

final readonly class FiscalReceiptRequest
{
    /** @param list<FiscalReceiptItem> $items */
    public function __construct(
        public string $providerRequestKey,
        public string $providerPaymentId,
        public string $customerEmail,
        public ?string $customerName,
        public int $amountMinor,
        public string $currency,
        public array $items,
        public string $receiptType = 'payment',
        public ?string $providerRefundId = null,
    ) {
    }
}
