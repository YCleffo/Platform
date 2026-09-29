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
            ['human', 'active', null, null, null, 'active', null, true],
            ['human', 'active', '2026-09-20T00:00:00Z', null, null, 'active', null, true],
            ['human', 'active', '2026-09-22T00:00:00Z', null, null, 'active', null, false],
            ['human', 'pending_verification', null, null, null, 'active', null, false],
            ['human', 'disabled', null, '2026-09-20T00:00:00Z', null, 'active', null, false],
            ['human', 'archived', null, null, '2026-09-20T00:00:00Z', 'active', null, false],
            ['service', 'active', null, null, null, 'active', null, false],
            ['system', 'active', null, null, null, 'active', null, false],
            ['human', 'active', null, null, null, 'archived', null, false],
            ['human', 'active', null, null, null, 'active', '2026-09-20T00:00:00Z', false],
        ];
        foreach ($cases as [$type, $status, $lockedUntil, $disabledAt, $archivedAt, $personStatus, $personArchivedAt, $expected]) {
            $state = new AccountSecurityState(
                accountId: 'account',
                personId: 'person',
                accountType: $type,
                status: $status,
                securityStamp: 'stamp',
                lockedUntil: $lockedUntil,
                locale: null,
                timezone: null,
                disabledAt: $disabledAt,
                archivedAt: $archivedAt,
                personStatus: $personStatus,
                personArchivedAt: $personArchivedAt,
            );

            self::assertSame(
                $expected,
                $state->allowsHumanAuthentication(new DateTimeImmutable('2026-09-21T00:00:00Z')),
            );
        }
        self::assertFalse((new AccountSecurityState(
            accountId: 'account', personId: null, accountType: 'human', status: 'active',
            securityStamp: 'stamp', lockedUntil: null, locale: null, timezone: null,
            disabledAt: null, archivedAt: null, personStatus: null, personArchivedAt: null,
        ))->allowsHumanAuthentication(new DateTimeImmutable('2026-09-21T00:00:00Z')));
    }
}
