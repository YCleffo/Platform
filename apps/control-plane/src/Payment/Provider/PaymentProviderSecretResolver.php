<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

interface PaymentProviderSecretResolver
{
    public function resolve(string $secretRef): string;
}
