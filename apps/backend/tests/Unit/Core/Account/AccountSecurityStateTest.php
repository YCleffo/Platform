<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Account;

use App\Core\Account\Application\AccountSecurityState;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AccountSecurityStateTest extends TestCase
{
    public function testAuthenticationPolicy(): void
    {
        $cases = [
            ['active', null, null, null, true],
            ['active', '2026-09-20T00:00:00Z', null, null, true],
            ['active', '2026-09-22T00:00:00Z', null, null, false],
            ['pending_verification', null, null, null, false],
            ['disabled', null, '2026-09-20T00:00:00Z', null, false],
            ['archived', null, null, '2026-09-20T00:00:00Z', false],
        ];
        foreach ($cases as [$status, $lockedUntil, $disabledAt, $archivedAt, $expected]) {
            $state = new AccountSecurityState(
                accountId: 'account',
                personId: null,
                accountType: 'service',
                status: $status,
                securityStamp: 'stamp',
                lockedUntil: $lockedUntil,
                locale: null,
                timezone: null,
                disabledAt: $disabledAt,
                archivedAt: $archivedAt,
            );

            self::assertSame(
                $expected,
                $state->allowsAuthentication(new DateTimeImmutable('2026-09-21T00:00:00Z')),
            );
        }
    }
}
