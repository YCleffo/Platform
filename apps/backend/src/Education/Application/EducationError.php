<?php

declare(strict_types=1);

namespace App\Education\Application;

final class EducationError extends \DomainException
{
    public function __construct(public readonly int $status, public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
