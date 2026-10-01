<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';

use ControlPlane\Deployment\Application\ReserveDeployment;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

$url = (string)getenv('CONTROL_DATABASE_URL');
$parts = parse_url($url);
if (getenv('APP_ENV') !== 'test' || ($parts['host'] ?? '') !== 'control-postgres-test'
    || ($parts['path'] ?? '') !== '/platform_commercial_test') {
    throw new RuntimeException('Wide E2E fixture requires isolated Control Plane test database.');
}
$a = (string)getenv('E2E_IMAGE_A_DIGEST'); $b = (string)getenv('E2E_IMAGE_B_DIGEST');
$public = (string)getenv('E2E_PUBLIC_KEY_HEX');
foreach ([$a, $b] as $digest) {
    if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $digest) !== 1) { throw new RuntimeException('Invalid image digest.'); }
}
if ($a === $b || preg_match('/\A[a-f0-9]{64}\z/D', $public) !== 1) {
    throw new RuntimeException('Wide E2E requires distinct images and a valid key.');
}
$db = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
$suffix = bin2hex(random_bytes(5));
$result = $db->transactional(function ($db) use ($a, $b, $public, $suffix): array {
    $key = 'wide-e2e-' . $suffix;
    $db->executeStatement("INSERT INTO commercial.signing_keys
        (key_id,algorithm,purpose,public_key,private_key_secret_ref,status,valid_from)
        VALUES (?,'Ed25519','entitlement',decode(?,'hex'),'vault://keys/provisioner-e2e','active',now()-interval '1 minute')",
        [$key, $public]);
    $account = (string)$db->fetchOne("INSERT INTO commercial.commercial_accounts(code,display_name)
        VALUES (?, 'Wide Infrastructure E2E') RETURNING id", ['wide-e2e-' . $suffix]);
    $user = (string)$db->fetchOne("INSERT INTO commercial.control_users
        (email,email_normalized,password_hash,status,email_verified_at,password_changed_at)
        VALUES (?,?,'!e2e-only!','active',now(),now()) RETURNING id",
        ['wide-' . $suffix . '@example.test', 'wide-' . $suffix . '@example.test']);
    $db->executeStatement("INSERT INTO commercial.commercial_account_memberships
        (control_user_id,commercial_account_id,role) VALUES (?,?,'owner')", [$user,$account]);
    $releaseA = (string)$db->fetchOne("INSERT INTO commercial.releases
        (version,channel,status,oci_reference,image_digest,manifest_sha256,manifest_signature,signing_key_id,published_at)
        VALUES (?,'stable','published','platform-runtime:e2e-a',?,decode(repeat('00',32),'hex'),decode(repeat('00',64),'hex'),?,now()) RETURNING id",
        ['5.8.3-e2e.a.' . $suffix, $a, $key]);
    $releaseB = (string)$db->fetchOne("INSERT INTO commercial.releases
        (version,channel,status,oci_reference,image_digest,manifest_sha256,manifest_signature,signing_key_id,published_at)
        VALUES (?,'stable','published','platform-runtime:e2e-b',?,decode(repeat('00',32),'hex'),decode(repeat('00',64),'hex'),?,now()) RETURNING id",
        ['5.8.3-e2e.b.' . $suffix, $b, $key]);
    $reserve = new ReserveDeployment($db, 'platform.example');
    $test = $reserve->reserveCloud($account, 'dedicated', 'wide-test-' . $suffix, 'wide-provision-test-' . $suffix);
    $control = $reserve->reserveCloud($account, 'dedicated', 'wide-control-' . $suffix, 'wide-provision-control-' . $suffix);
    $db->executeStatement('UPDATE commercial.deployments SET desired_release_id=? WHERE id IN (?,?)', [$releaseA,$test,$control]);
    return ['testDeployment' => $test, 'controlDeployment' => $control,
        'releaseA' => $releaseA, 'releaseB' => $releaseB];
});
echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
