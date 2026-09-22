<?php

declare(strict_types=1);

namespace App\Core\Audit\Application;

final readonly class AuditService
{
    public function __construct(private AuditWriter $writer) {}

    public function record(WriteAuditEventCommand $command): AuditEventReceipt
    {
        return $this->writer->write($command);
    }
}
