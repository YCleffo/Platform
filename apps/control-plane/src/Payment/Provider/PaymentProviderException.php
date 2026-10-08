<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

final class PaymentProviderException extends \RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly bool $retryable, public readonly string $failureClass)
    {
        parent::__construct('The payment provider request could not be completed.');
    }

    public static function uncertain(string $errorCode = 'provider_unavailable'): self
    {
        return new self($errorCode, true, ProviderPaymentFailure::UNKNOWN);
    }

    public static function definitive(string $errorCode): self
    {
        $class = in_array($errorCode, ['provider_authentication_failed', 'invalid_provider_request'], true)
            ? ProviderPaymentFailure::OPERATIONAL : ProviderPaymentFailure::NON_RETRYABLE;
        return new self($errorCode, false, $class);
    }
}
