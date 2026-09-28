<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\DataSubjectRequestStateMachine;
use App\Core\Privacy\Application\DataSubjectRequestTypeRegistry;
use Codeception\Test\Unit;
use DomainException;

final class DataSubjectRequestPolicyTest extends Unit
{
    public function testScopesAndCompletionGuard(): void
    {
        $types = new DataSubjectRequestTypeRegistry();
        self::assertSame('privacy.request.submit', $types->get('access')['scope']);
        self::assertSame('privacy.processing_restriction.request', $types->get('restriction')['scope']);
        self::assertSame('privacy.consent.withdraw', $types->get('consent_withdrawal')['scope']);
        self::assertSame('privacy.data.export', $types->get('export')['requiredCapability']);
        self::assertSame('privacy.data.erasure', $types->get('erasure')['requiredCapability']);
    }

    public function testTerminalCannotReopen(): void
    {
        $states = new DataSubjectRequestStateMachine();
        $states->assertTransition('submitted', 'in_review');
        $states->assertTransition('in_review', 'in_progress');
        $this->expectException(DomainException::class);
        $states->assertTransition('completed', 'in_review');
    }
}
