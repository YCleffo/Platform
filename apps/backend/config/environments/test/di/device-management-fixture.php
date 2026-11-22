<?php

declare(strict_types=1);

use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\DeviceManagement\Application\DeviceStore;
use App\DeviceManagement\Infrastructure\PostgresDeviceStore;
use Yiisoft\Db\Connection\ConnectionInterface;

$proof = getenv('E2E_ISOLATION_PROOF');
if (!is_string($proof) || strlen($proof) < 32) {
    return [];
}

return [DeviceStore::class => static function (ConnectionInterface $db): DeviceStore {
    TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
    return new PostgresDeviceStore($db, allowTestAdapter: true);
}];
