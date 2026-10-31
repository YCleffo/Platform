<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Application\CreateCommercialAccount;

final class CreateCommercialAccountTest extends DatabaseTestCase
{
    public function testCreateIsIdempotentAndWritesAuditAndOutboxAtomically(): void
    {
        $service = new CreateCommercialAccount($this->connection);
        $user = $this->user();
        $correlation = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $id = $service->create($user, 'academy-one', 'Academy One', null, 'request-one', $correlation);
        self::assertSame($id, $service->create($user, 'academy-one', 'Academy One', null, 'request-one', $correlation));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.commercial_accounts WHERE id = ?', [$id]));
        self::assertSame('owner', $this->connection->fetchOne('SELECT role FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND control_user_id = ?', [$id, $user]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.audit_events WHERE commercial_account_id = ?', [$id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.outbox_events WHERE aggregate_id = ?', [$id]));
    }

    public function testDifferentRequestWithSameKeyConflicts(): void
    {
        $service = new CreateCommercialAccount($this->connection);
        $user = $this->user();
        $correlation = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $service->create($user, 'academy-one', 'Academy One', null, 'request-one', $correlation);
        $this->expectException(\DomainException::class);
        $service->create($user, 'academy-two', 'Academy Two', null, 'request-one', $correlation);
    }

    public function testDuplicateCodeRollsBackOutboxAndIdempotencyRecord(): void
    {
        $service = new CreateCommercialAccount($this->connection);
        $user = $this->user();
        $correlation = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $accountId = $service->create($user, 'academy-one', 'Academy One', null, 'request-one', $correlation);
        $this->expectDatabaseRejection(fn () => $service->create($user, 'academy-one', 'Different Display Name', null, 'request-two', $correlation));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.idempotency_keys WHERE idempotency_key = 'request-two'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'commercial_account.created' AND aggregate_id = ?", [$accountId]));
    }
}
