<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

final readonly class ProviderAccount
{
    public function __construct(
        public string $id,
        public string $providerCode,
        public string $externalAccountId,
        public string $secretRef,
        public string $receiptMode = 'disabled',
        public ?int $receiptVatCode = null,
        public ?string $receiptPaymentSubject = null,
        public ?string $receiptPaymentMode = null,
    ) {
    }
}
