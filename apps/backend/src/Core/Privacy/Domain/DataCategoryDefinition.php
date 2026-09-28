<?php

declare(strict_types=1);

namespace App\Core\Privacy\Domain;

use InvalidArgumentException;

final readonly class DataCategoryDefinition
{
    public function __construct(
        public string $code,
        public bool $sensitive = false,
        public bool $specialCategory = false,
        public bool $biometric = false,
        public bool $publicDistribution = false,
    ) {
        MachineCode::assert($code);
        if ($biometric && !$sensitive) {
            throw new InvalidArgumentException('Biometric category must be marked sensitive.');
        }
        if ($specialCategory && !$sensitive) {
            throw new InvalidArgumentException('Special category must be marked sensitive.');
        }
    }
}
