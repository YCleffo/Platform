<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Tools\DsnParser;

$url = getenv('CONTROL_DATABASE_URL');
$parts = is_string($url) ? parse_url($url) : false;
if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1'
    || !is_array($parts) || ($parts['host'] ?? '') !== 'control-postgres-test'
    || ($parts['path'] ?? '') !== '/platform_commercial_test') {
    exit(3);
}

[$script, $mode, $value, $extra, $start] = $argv;
while (microtime(true) < (float) $start) {
    usleep(5_000);
}

$connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
try {
    switch ($mode) {
        case 'account':
            $connection->executeStatement("INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Race Test')", [$value]);
            break;
        case 'plan':
            $connection->executeStatement("INSERT INTO commercial.plans (code, version, name) VALUES (?, 1, 'Race Test')", [$value]);
            break;
        case 'slug':
            $connection->beginTransaction();
            $deployment = $connection->fetchOne("INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode, system_slug) VALUES (?, uuidv7(), 'shared', ?) RETURNING id", [$extra, $value]);
            $connection->executeStatement("INSERT INTO commercial.deployment_domains (deployment_id, kind, hostname) VALUES (?, 'system', ?)", [$deployment, $value . '.platform.example']);
            $connection->commit();
            break;
        case 'hostname':
            $connection->executeStatement("INSERT INTO commercial.deployment_domains (deployment_id, kind, hostname) VALUES (?, 'custom', ?)", [$extra, $value]);
            break;
        case 'idempotency':
            $connection->executeStatement("INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status) VALUES ('test.race', ?, decode(repeat('00',32),'hex'), 'completed')", [$value]);
            break;
        default:
            exit(3);
    }
    exit(0);
} catch (UniqueConstraintViolationException) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    exit(2);
} catch (Throwable) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    exit(3);
}
