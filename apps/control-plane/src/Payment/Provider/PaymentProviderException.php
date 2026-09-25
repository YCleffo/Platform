<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

final class PaymentProviderException extends \RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly bool $retryable)
    {
        parent::__construct('The payment provider request could not be completed.');
    }

    public static function uncertain(string $errorCode = 'provider_unavailable'): self
    {
        return new self($errorCode, true);
    }

    public static function definitive(string $errorCode): self
    {
        return new self($errorCode, false);
    }
}
