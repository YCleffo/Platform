<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Audit\Application\AuditPayloadPolicy;
use App\Core\Audit\Application\WriteAuditEventCommand;
use Codeception\Test\Unit;
use InvalidArgumentException;

final class AuditPayloadPolicyTest extends Unit
{
    public function testRejectsSecretsAndEntitySnapshotsAtSharedBoundary(): void
    {
        $policy = new AuditPayloadPolicy();
        foreach ([
            new WriteAuditEventCommand('account', null, 'core.test', metadata: ['nested' => ['refresh_token' => 'secret']]),
            new WriteAuditEventCommand('account', null, 'core.test', afterData: ['profile' => ['email' => 'person@example.test']]),
            new WriteAuditEventCommand('account', null, 'core.test', metadata: ['value' => 'person@example.test']),
            new WriteAuditEventCommand('account', null, 'core.test', metadata: ['value' => str_repeat('x', 600)]),
            new WriteAuditEventCommand('account', null, 'privacy.consent.granted', metadata: ['arbitrary' => true]),
        ] as $command) {
            try {
                $policy->assertAllowed($command);
                self::fail('Unsafe audit payload was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $policy->assertAllowed(new WriteAuditEventCommand('account', null, 'privacy.consent.granted', resourceType: 'core.privacy_consent', resourceId: '018f2892-aaaa-7aaa-8aaa-aaaaaaaaaaaa'));
    }
}
