<?php

declare(strict_types=1);

namespace App\Core\Privacy\Domain;

use InvalidArgumentException;

/** A deployment-owned subject category vocabulary used by processing purposes. */
final readonly class SubjectCategoryDefinition
{
    public function __construct(public string $code)
    {
        try {
            MachineCode::assert($code);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Invalid subject category definition.', 0, $exception);
        }
    }
}
