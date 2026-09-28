<?php

declare(strict_types=1);

namespace App\Core\Privacy\Domain;

use InvalidArgumentException;

final class MachineCode
{
    public static function assert(string $code): void
    {
        if (strlen($code) > 120 || preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $code) !== 1) {
            throw new InvalidArgumentException('Invalid privacy policy code.');
        }
    }
}
