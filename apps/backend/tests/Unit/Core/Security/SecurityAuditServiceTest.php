<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Audit\Application\AuditEventReceipt;
use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\AuditWriter;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use Codeception\Test\Unit;
use InvalidArgumentException;

final class SecurityAuditServiceTest extends Unit
{
    public function testRejectsSecretShapedMetadata(): void
    {
        $service = new SecurityAuditService(new AuditService(new NullAuditWriter(), new \App\Core\Audit\Application\AuditPayloadPolicy(new \App\Core\Audit\Application\AuditEventDefinitionRegistry())));

        $this->expectException(InvalidArgumentException::class);
        $service->record(new SecurityAuditCommand(
            action: SecurityAction::LOGIN_FAILED,
            actorAccountId: null,
            metadata: ['sessionToken' => 'must never be recorded'],
        ));
    }
}

final readonly class NullAuditWriter implements AuditWriter
{
    public function write(WriteAuditEventCommand $command): AuditEventReceipt
    {
        return new AuditEventReceipt(1, '018f2892-aaaa-7aaa-8aaa-aaaaaaaaaaaa', '2026-09-20T00:00:00Z');
    }
}
