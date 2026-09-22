<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

interface ContactNormalizer
{
    public function normalize(string $contactType, string $value): string;
}
