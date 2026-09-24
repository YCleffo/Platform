<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Deployment\Application\ReserveDeployment;

final class ReserveDeploymentTest extends DatabaseTestCase
{
    public function testCloudReservationIsAtomicAndKeepsSystemDomain(): void
    {
        $account = $this->account();
        $service = new ReserveDeployment($this->connection, 'platform.example');
        $deployment = $service->reserveCloud($account, 'shared', 'school-1', 'operation-one');
        self::assertSame('school-1.platform.example', $this->connection->fetchOne("SELECT hostname FROM commercial.deployment_domains WHERE deployment_id = ? AND kind = 'system'", [$deployment]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.deployment_operations WHERE deployment_id = ?', [$deployment]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.outbox_events WHERE aggregate_id = ?', [$deployment]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.audit_events WHERE resource_id = ?', [$deployment]));
        $this->expectDatabaseRejection(fn () => $service->reserveCloud($account, 'shared', 'school-1', 'operation-two'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.deployments WHERE system_slug = ?', ['school-1']));
    }

    public function testInvalidSlugRejectedBeforeDatabase(): void
    {
        $service = new ReserveDeployment($this->connection, 'platform.example');
        $this->expectException(\InvalidArgumentException::class);
        $service->reserveCloud($this->account(), 'shared', 'Admin', 'operation-one');
    }
}
