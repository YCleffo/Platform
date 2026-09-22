<?php

declare(strict_types=1);

namespace App\Core\Communication\Application;

final readonly class EmailProcessingReport
{
    public function __construct(
        public int $claimed,
        public int $sent,
        public int $retrying,
        public int $dead,
        public int $stateConflicts,
    ) {}
}
