<?php

declare(strict_types=1);

namespace App\Core\Audit\Application;

interface AuditWriter
{
    public function write(WriteAuditEventCommand $command): AuditEventReceipt;
}
