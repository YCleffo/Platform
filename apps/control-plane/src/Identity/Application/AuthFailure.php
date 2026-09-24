<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Application;

final class AuthFailure extends \DomainException
{
    public function __construct(public readonly string $errorCode, string $message = 'Authentication request failed.')
    {
        parent::__construct($message);
    }
}
