<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

interface PaymentProviderGateway
{
    public function createPayment(ProviderAccount $account, ProviderPaymentRequest $request): ProviderPayment;

    public function getPayment(ProviderAccount $account, string $providerPaymentId): ProviderPayment;
}
