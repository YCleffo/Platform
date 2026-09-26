<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

interface FiscalReceiptProviderGateway
{
    public function createReceipt(ProviderAccount $account, FiscalReceiptRequest $request): ProviderReceipt;

    public function getReceipt(ProviderAccount $account, string $providerReceiptId): ProviderReceipt;
}
