<?php

declare(strict_types=1);

use App\DeviceManagement\Application\DeviceStore;
use App\DeviceManagement\Application\Mutation;
use App\DeviceManagement\Infrastructure\DeviceMigrationRunner;
use App\DeviceManagement\Infrastructure\DeviceMutation;
use App\DeviceManagement\Infrastructure\PostgresDeviceStore;

$root = dirname(__DIR__, 3);
if (!class_exists(PostgresDeviceStore::class)) {
    return [];
}
return [
    DeviceStore::class => ['class' => PostgresDeviceStore::class],
    Mutation::class => ['class' => DeviceMutation::class],
    DeviceMigrationRunner::class => ['__construct()' => ['directory' => $root . '/migrations/device_management']],
];
