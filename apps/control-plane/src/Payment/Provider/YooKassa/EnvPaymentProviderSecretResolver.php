<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider\YooKassa;

use ControlPlane\Payment\Provider\PaymentProviderSecretResolver;

final class EnvPaymentProviderSecretResolver implements PaymentProviderSecretResolver
{
    public function resolve(string $secretRef): string
    {
        $name = self::environmentName($secretRef);
        $secret = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        if (!is_string($secret) || $secret === '') {
            throw new \DomainException('Payment provider credentials are not configured.');
        }

        return $secret;
    }

    public static function validateReference(string $secretRef): string
    {
        self::environmentName($secretRef);

        return $secretRef;
    }

    private static function environmentName(string $secretRef): string
    {
        if (preg_match('/\Aenv:([A-Z_][A-Z0-9_]*)\z/D', $secretRef, $matches) !== 1) {
            throw new \InvalidArgumentException('Only env:VARIABLE_NAME payment secret references are supported.');
        }

        return $matches[1];
    }
}
