<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';

use ControlPlane\Provisioning\DockerCommand;
use ControlPlane\Provisioning\LocalDockerInfrastructureProvider;
use ControlPlane\Provisioning\ProvisioningRequest;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

$id = $argv[1] ?? '';
$url = (string) getenv('CONTROL_DATABASE_URL');
$parts = parse_url($url);
if (!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id)
    || getenv('APP_ENV') !== 'test' || ($parts['host'] ?? '') !== 'control-postgres-test'
    || ($parts['path'] ?? '') !== '/platform_commercial_test') {
    throw new RuntimeException('Replay requires isolated active E2E deployment.');
}
$db = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
$row = $db->fetchAssociative("SELECT d.installation_id, d.mode, d.status, d.core_db_secret_ref,
    r.id AS release_id, r.version, r.channel, r.oci_reference, r.image_digest
    FROM commercial.deployments d JOIN commercial.releases r ON r.id = d.desired_release_id
    WHERE d.id = ?", [$id]);
if ($row === false || $row['status'] !== 'active' || $row['mode'] !== 'dedicated'
    || $row['core_db_secret_ref'] !== 'localdev://' . $id . '/db') {
    throw new RuntimeException('Deployment is not the expected active local E2E resource.');
}
$keys = [];
foreach ($db->fetchAllAssociative("SELECT key_id, encode(public_key,'base64') AS encoded
    FROM commercial.signing_keys WHERE purpose='entitlement' AND status='active'") as $key) {
    $keys[$key['key_id']] = ['publicKeyBase64' => preg_replace('/\s+/', '', $key['encoded']),
        'status' => 'trusted'];
}
$base = rtrim((string) getenv('PROVISIONER_SECRET_HOST_DIR'), '/\\') . '/' . $id;
$request = new ProvisioningRequest($id, $row['installation_id'], 'dedicated', $row['release_id'],
    $row['version'], $row['channel'], $row['oci_reference'], $row['image_digest'],
    $base . '/db_password', $base . '/deployment_credential', json_encode($keys, JSON_THROW_ON_ERROR));
$provider = new LocalDockerInfrastructureProvider(new DockerCommand(), 'test',
    'platform_control_test_net', 'http://platform-provisioner-control-test:8000',
    'platform-provisioner-migrator:local');
$result = $provider->reconcile($request);
if (!$result->reused || $provider->status($id) !== ['runtime' => true, 'database' => true,
    'redis' => true, 'scheduler' => true, 'emailWorker' => true, 'network' => true]) {
    throw new RuntimeException('Idempotent resource reuse failed.');
}
echo 'existing dedicated resources reused', PHP_EOL;
