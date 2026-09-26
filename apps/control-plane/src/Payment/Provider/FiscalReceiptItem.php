<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

final readonly class FiscalReceiptItem
{
    public function __construct(
        public string $description,
        public int $quantity,
        public int $unitAmountMinor,
        public int $totalMinor,
        public string $currency,
        public int $vatCode,
        public string $paymentSubject,
        public string $paymentMode,
    ) {
    }
}
