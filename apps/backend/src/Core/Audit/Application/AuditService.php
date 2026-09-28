<?php

declare(strict_types=1);

namespace App\Core\Audit\Application;

final readonly class AuditService
{
    public function __construct(private AuditWriter $writer, private AuditPayloadPolicy $policy) {}

    public function record(WriteAuditEventCommand $command): AuditEventReceipt
    {
        $this->policy->assertAllowed($command);
        return $this->writer->write($command);
    }
}
