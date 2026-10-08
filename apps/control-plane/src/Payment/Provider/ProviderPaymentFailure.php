<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider;

use ControlPlane\Shared\Domain\IdentifierPolicy;

final readonly class ProviderPaymentFailure
{
    public const RETRYABLE = 'retryable';
    public const NON_RETRYABLE = 'non_retryable';
    public const PAYMENT_METHOD_INVALID = 'payment_method_invalid';
    public const UNKNOWN = 'unknown';
    public const OPERATIONAL = 'operational';

    public function __construct(public string $class, public string $code, public bool $paymentMethodInvalid = false)
    {
        if (!in_array($class, [self::RETRYABLE, self::NON_RETRYABLE, self::PAYMENT_METHOD_INVALID, self::UNKNOWN, self::OPERATIONAL], true)) {
            throw new \InvalidArgumentException('Unsupported provider payment failure class.');
        }
        IdentifierPolicy::machineCode($code);
        if ($paymentMethodInvalid !== ($class === self::PAYMENT_METHOD_INVALID)) {
            throw new \InvalidArgumentException('Payment-method invalidity must match its failure class.');
        }
    }
}
