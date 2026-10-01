<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';

use ControlPlane\Provisioning\DockerCommand;
use ControlPlane\Provisioning\LocalDockerInfrastructureProvider;
use ControlPlane\Provisioning\LocalProvisioningSecretStore;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

$id = $argv[1] ?? '';
$url = (string) getenv('CONTROL_DATABASE_URL');
$parts = parse_url($url);
if (!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id)
    || getenv('APP_ENV') !== 'test' || ($parts['host'] ?? '') !== 'control-postgres-test'
    || ($parts['path'] ?? '') !== '/platform_commercial_test') {
    throw new RuntimeException('Cleanup requires an isolated failed E2E deployment.');
}
$db = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
$state = $db->fetchAssociative("SELECT d.status, o.status AS operation_status
    FROM commercial.deployments d JOIN commercial.deployment_operations o ON o.deployment_id = d.id
    WHERE d.id = ? AND o.operation_type = 'provision'", [$id]);
if ($state === false || $state['status'] !== 'failed' || $state['operation_status'] !== 'failed') {
    throw new RuntimeException('Only failed test deployments can be cleaned.');
}
$hostDir = (string) getenv('PROVISIONER_SECRET_HOST_DIR');
$provider = new LocalDockerInfrastructureProvider(new DockerCommand(), 'test',
    'platform_control_test_net', 'http://platform-provisioner-control-test:8000',
    'platform-provisioner-migrator:local');
$provider->cleanup($id);
(new LocalProvisioningSecretStore('/run/provisioning-secrets', $hostDir, 'test'))->cleanup($id);
echo 'failed deployment resources cleaned', PHP_EOL;
