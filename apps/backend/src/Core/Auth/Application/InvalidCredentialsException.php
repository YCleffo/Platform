<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use RuntimeException;

final class InvalidCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid credentials.');
    }
}
