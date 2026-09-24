<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Application\CreateCommercialAccount;

final class CreateCommercialAccountTest extends DatabaseTestCase
{
    public function testCreateIsIdempotentAndWritesAuditAndOutboxAtomically(): void
    {
        $service = new CreateCommercialAccount($this->connection);
        $id = $service->create('academy-one', 'Academy One', 'request-one');
        self::assertSame($id, $service->create('academy-one', 'Academy One', 'request-one'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.commercial_accounts WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.audit_events WHERE commercial_account_id = ?', [$id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.outbox_events WHERE aggregate_id = ?', [$id]));
    }

    public function testDifferentRequestWithSameKeyConflicts(): void
    {
        $service = new CreateCommercialAccount($this->connection);
        $service->create('academy-one', 'Academy One', 'request-one');
        $this->expectException(\DomainException::class);
        $service->create('academy-two', 'Academy Two', 'request-one');
    }

    public function testDuplicateCodeRollsBackOutboxAndIdempotencyRecord(): void
    {
        $service = new CreateCommercialAccount($this->connection);
        $service->create('academy-one', 'Academy One', 'request-one');
        $this->expectDatabaseRejection(fn () => $service->create('academy-one', 'Different Display Name', 'request-two'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.idempotency_keys WHERE idempotency_key = 'request-two'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'account.created'"));
    }
}
