<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

interface RefundProviderGateway
{
    public function createRefund(ProviderAccount $account, RefundProviderRequest $request): ProviderRefund;

    public function getRefund(ProviderAccount $account, string $providerRefundId): ProviderRefund;
}
