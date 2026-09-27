<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

final readonly class ProviderReceipt
{
    /** @param list<FiscalReceiptItem> $items */
    public function __construct(
        public string $id,
        public string $type,
        public string $providerPaymentId,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public array $items,
        public ?\DateTimeImmutable $completedAt,
        public ?string $providerRefundId = null,
    ) {
    }
}
