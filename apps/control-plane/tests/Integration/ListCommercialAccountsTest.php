<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Application\ListCommercialAccounts;

final class ListCommercialAccountsTest extends DatabaseTestCase
{
    public function testListsOnlyActiveMembershipsForTheRequestingUser(): void
    {
        $owner = $this->user();
        $first = $this->accountFor($owner, 'academy-alpha');
        $second = $this->accountFor($owner, 'academy-beta');
        $other = $this->user();
        $this->accountFor($other, 'academy-other');

        $result = (new ListCommercialAccounts($this->connection))->forUser($owner, 50, 0);

        self::assertSame(2, $result['total']);
        self::assertSame(2, count($result['items']));
        $codes = array_column($result['items'], 'code');
        self::assertContains('academy-alpha', $codes);
        self::assertContains('academy-beta', $codes);
        self::assertNotContains('academy-other', $codes);
        $ids = array_column($result['items'], 'id');
        self::assertContains($first, $ids);
        self::assertContains($second, $ids);
        foreach ($result['items'] as $item) {
            self::assertSame('owner', $item['role']);
        }
    }

    public function testRevokedMembershipsAreExcluded(): void
    {
        $owner = $this->user();
        $account = $this->accountFor($owner, 'academy-revoked');
        $this->connection->executeStatement(
            'UPDATE commercial.commercial_account_memberships SET revoked_at = now() WHERE control_user_id = ? AND commercial_account_id = ?',
            [$owner, $account],
        );

        $result = (new ListCommercialAccounts($this->connection))->forUser($owner, 50, 0);

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
    }

    public function testPaginationIsBoundedAndOrdered(): void
    {
        $owner = $this->user();
        $this->accountFor($owner, 'academy-1');
        $this->accountFor($owner, 'academy-2');
        $this->accountFor($owner, 'academy-3');

        $service = new ListCommercialAccounts($this->connection);

        $firstPage = $service->forUser($owner, 2, 0);
        self::assertSame(3, $firstPage['total']);
        self::assertSame(2, count($firstPage['items']));

        $secondPage = $service->forUser($owner, 2, 2);
        self::assertSame(1, count($secondPage['items']));

        $clamped = $service->forUser($owner, 500, -5);
        self::assertSame(100, $clamped['limit']);
        self::assertSame(0, $clamped['offset']);
    }

    private function accountFor(string $userId, string $code): string
    {
        $id = $this->connection->fetchOne(
            "INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Account') RETURNING id",
            [$code],
        );
        self::assertIsString($id);
        $this->connection->executeStatement(
            "INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')",
            [$userId, $id],
        );

        return $id;
    }
}
