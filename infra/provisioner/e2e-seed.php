<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';

use ControlPlane\Deployment\Application\ReserveDeployment;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

$url = (string) getenv('CONTROL_DATABASE_URL');
$parts = parse_url($url);
if (getenv('APP_ENV') !== 'test' || ($parts['host'] ?? '') !== 'control-postgres-test'
    || ($parts['path'] ?? '') !== '/platform_commercial_test') {
    throw new RuntimeException('E2E fixture requires isolated Control Plane test database.');
}
$db = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
$digest = (string) getenv('E2E_IMAGE_DIGEST');
$public = (string) getenv('E2E_PUBLIC_KEY_HEX');
if (!preg_match('/\Asha256:[a-f0-9]{64}\z/D', $digest)
    || !preg_match('/\A[a-f0-9]{64}\z/D', $public)) {
    throw new RuntimeException('Invalid E2E release inputs.');
}
$suffix = bin2hex(random_bytes(5));
$id = $db->transactional(function ($db) use ($digest, $public, $suffix): string {
    $key = 'provisioner-e2e-' . $suffix;
    $db->executeStatement("INSERT INTO commercial.signing_keys
        (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from)
        VALUES (?, 'Ed25519', 'entitlement', decode(?, 'hex'), 'vault://keys/provisioner-e2e', 'active', now() - interval '1 minute')",
        [$key, $public]);
    $account = $db->fetchOne("INSERT INTO commercial.commercial_accounts (code, display_name)
        VALUES (?, 'Provisioner E2E') RETURNING id", ['provisioner-e2e-' . $suffix]);
    $user = $db->fetchOne("INSERT INTO commercial.control_users
        (email, email_normalized, password_hash, status, email_verified_at, password_changed_at)
        VALUES (?, ?, '!e2e-only!', 'active', now(), now()) RETURNING id",
        ['e2e-' . $suffix . '@example.test', 'e2e-' . $suffix . '@example.test']);
    $db->executeStatement("INSERT INTO commercial.commercial_account_memberships
        (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$user, $account]);
    $release = $db->fetchOne("INSERT INTO commercial.releases
        (version, channel, status, oci_reference, image_digest, manifest_sha256,
         manifest_signature, signing_key_id, published_at)
        VALUES (?, 'stable', 'published', 'platform-runtime:local', ?,
            decode(repeat('00',32),'hex'), decode(repeat('00',64),'hex'), ?, now()) RETURNING id",
        ['5.4.0-e2e.' . $suffix, $digest, $key]);
    $deployment = (new ReserveDeployment($db, 'platform.example'))
        ->reserveCloud((string) $account, 'dedicated', 'e2e-' . $suffix, 'provisioner-e2e-' . $suffix);
    $db->executeStatement('UPDATE commercial.deployments SET desired_release_id = ? WHERE id = ?',
        [$release, $deployment]);
    return $deployment;
});
echo $id, PHP_EOL;
