<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

final class ProvisioningFailure extends \RuntimeException
{
    public function __construct(public readonly string $machineCode, public readonly bool $retryable = false)
    {
        parent::__construct($machineCode);
    }
}
