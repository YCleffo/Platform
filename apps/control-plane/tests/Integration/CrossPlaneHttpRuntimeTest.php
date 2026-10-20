<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use App\PlatformRuntime\PostgresEntitlementApplier;
use App\PlatformRuntime\PostgresEffectiveRuntimeEntitlements;
use App\PlatformRuntime\PostgresEntitlementDocumentRepository;
use App\PlatformRuntime\PostgresInstallationRepository;
use App\PlatformRuntime\RuntimeInstallationBootstrap;
use App\PlatformRuntime\SodiumEntitlementSignatureVerifier;
use App\PlatformRuntime\TrustedEntitlementKeyRegistry;
use ControlPlane\Deployment\Application\ReserveDeployment;
use ControlPlane\Deployment\Application\StartDeploymentProvisioning;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopeRegistry;
use PHPUnit\Framework\Attributes\Group;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

#[Group('cross-plane')]
final class CrossPlaneHttpRuntimeTest extends RefundTestCase
{
    public function testActualHttpAndTenantRuntimeEntrypoint(): void
    {
        if (getenv('CROSS_PLANE_REQUIRED') !== '1' || getenv('PHASE47_CROSS_PLANE') !== '1') {
            self::fail('Required cross-plane prerequisites are missing.');
        }
        if (getenv('DB_HOST') !== 'postgres-test' || getenv('DB_NAME') !== 'platform_test'
            || getenv('TEST_ISOLATED_RUNTIME') !== '1') {
            self::fail('Refusing runtime fixture writes outside the isolated Core test database.');
        }
        $backendRoot = is_dir('/backend') ? '/backend' : dirname(__DIR__, 3) . '/backend';
        require_once $backendRoot . '/vendor/autoload.php';
        \App\Environment::prepare();
        $core = (new HttpApplicationRunner(rootPath: $backendRoot, environment: 'test'))
            ->getContainer()->get(ConnectionInterface::class);
        self::assertSame(0, (int) $core->createCommand(
            'SELECT count(*) FROM platform_runtime.installations')->queryScalar(),
            'Isolated Core test DB must start without an installation.');

        $suffix = bin2hex(random_bytes(7));
        $keyId = 'http-runtime-' . $suffix;
        $reference = 'vault://test/' . $keyId;
        $keyFile = sys_get_temp_dir() . '/' . $keyId . '.key';
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        self::assertNotFalse(file_put_contents($keyFile, base64_encode($secret)));
        chmod($keyFile, 0600);
        $server = null;
        $serverPipes = [];
        $installationId = null;
        try {
            [$owner, $account, $profile] = $this->readyAccount('http-runtime-' . $suffix);
            $deployment = (new ReserveDeployment($this->connection, 'platform.example'))
                ->reserveCloud($account, 'shared', 'http-runtime-' . $suffix,
                    'http-runtime-operation-' . $suffix);
            $credentials = new DeploymentCredentialService($this->connection,
                new DeploymentScopeRegistry(), new DatabaseRateLimiter($this->connection,
                    new TokenCodec('test-only-control-token-secret-at-least-32-chars')));
            $issued = (new StartDeploymentProvisioning($this->connection, $credentials,
                new \ControlPlane\M2m\DeploymentCredentialLifetime('30')))->start($deployment);
            $installationId = $issued['installationId'];
            $this->connection->executeStatement("INSERT INTO commercial.signing_keys
                (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from)
                VALUES (?, 'Ed25519', 'entitlement', decode(?, 'hex'), ?, 'active', now() - interval '1 day')",
                [$keyId, bin2hex($public), $reference]);
            $featureCode = 'http-runtime.' . $suffix . '.enabled';
            $feature = $this->feature($featureCode, 'boolean');
            [, $price] = $this->planPrice('http-runtime-' . $suffix, featureValues: [$feature => true]);
            $subscription = $this->draftSubscription($account, $profile, [$price]);
            $this->connection->commit();
            $this->connection->beginTransaction();

            (new RuntimeInstallationBootstrap($core))->initialize([
                'installationId' => $installationId, 'mode' => 'shared', 'environment' => 'test',
                'applicationVersion' => '4.7.0', 'releaseId' => 'http-runtime-test',
                'releaseChannel' => 'stable',
            ]);
            $port = random_int(19000, 29000);
            $url = 'http://127.0.0.1:' . $port;
            $serverEnvironment = getenv();
            $serverEnvironment['CONTROL_ENTITLEMENT_SECRET_FILES'] = json_encode(
                [$reference => $keyFile], JSON_THROW_ON_ERROR);
            $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', 'public', 'public/index.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $serverPipes,
                dirname(__DIR__, 2), $serverEnvironment);
            self::assertIsResource($server);
            $this->waitForHttp($url . '/health/live', $server, $serverPipes);

            [$invalidStatus] = $this->httpGet($url . '/api/m2m/entitlements/latest',
                'Service ' . $issued['credentialId'] . '.' . str_repeat('A', 43));
            self::assertSame(401, $invalidStatus);
            $credential = $issued['credentialId'] . '.' . $issued['credentialSecret'];
            [$validStatus, $firstEnvelope] = $this->httpGet($url . '/api/m2m/entitlements/latest',
                'Service ' . $credential);
            self::assertSame(200, $validStatus);
            $first = json_decode($firstEnvelope, true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($first);
            self::assertSame($keyId, $first['keyId']);

            $runtimeEnvironment = getenv();
            $runtimeEnvironment['CONTROL_PLANE_URL'] = $url;
            $runtimeEnvironment['CONTROL_PLANE_DEPLOYMENT_CREDENTIAL_REF'] = 'env:CONTROL_PLANE_DEPLOYMENT_CREDENTIAL';
            $runtimeEnvironment['CONTROL_PLANE_DEPLOYMENT_CREDENTIAL'] = $credential;
            $runtimeEnvironment['RUNTIME_ENTITLEMENT_TRUSTED_KEYS'] = json_encode([
                $keyId => ['publicKeyBase64' => base64_encode($public), 'status' => 'trusted'],
            ], JSON_THROW_ON_ERROR);
            $this->syncThroughCli($backendRoot, $runtimeEnvironment, 1);
            $this->syncThroughCli($backendRoot, $runtimeEnvironment, 1);

            $this->activatePaid($account, $subscription, new \DateTimeImmutable('now'), $this->uuid());
            $this->connection->commit();
            $this->connection->beginTransaction();
            [$newStatus, $newEnvelope] = $this->httpGet($url . '/api/m2m/entitlements/latest',
                'Service ' . $credential);
            self::assertSame(200, $newStatus);
            $new = json_decode($newEnvelope, true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($new);
            $newDocument = base64_decode($new['documentBase64'], true);
            self::assertIsString($newDocument);
            self::assertSame(2, json_decode($newDocument, true, 8, JSON_THROW_ON_ERROR)['sequence']);
            $committedState = $this->commercialEffectSnapshot($subscription, $deployment);
            $unavailableEnvironment = $runtimeEnvironment;
            $unavailableEnvironment['DB_HOST'] = '127.0.0.1';
            $unavailableEnvironment['DB_PORT'] = '1';
            $this->assertSyncUnavailable($backendRoot, $unavailableEnvironment);
            self::assertSame($committedState, $this->commercialEffectSnapshot($subscription, $deployment));
            self::assertSame(1, (int) $core->createCommand(
                'SELECT current_entitlement_sequence FROM platform_runtime.installations WHERE id = CAST(:id AS uuid)',
                [':id' => $installationId])->queryScalar());
            $this->syncThroughCli($backendRoot, $runtimeEnvironment, 2);
            $this->syncThroughCli($backendRoot, $runtimeEnvironment, 2);
            self::assertSame($committedState, $this->commercialEffectSnapshot($subscription, $deployment));
            $installations = new PostgresInstallationRepository($core);
            $verifier = new SodiumEntitlementSignatureVerifier(new TrustedEntitlementKeyRegistry([
                $keyId => ['publicKeyBase64' => base64_encode($public), 'status' => 'trusted'],
            ]));
            $rights = new PostgresEffectiveRuntimeEntitlements($installations,
                new PostgresEntitlementDocumentRepository($core), $verifier);
            self::assertSame([$featureCode => true], $rights->forInstallation($installationId));
            $applier = new PostgresEntitlementApplier($core, $verifier);
            $oldDocument = base64_decode($first['documentBase64'], true);
            $oldSignature = base64_decode($first['signatureBase64'], true);
            self::assertIsString($oldDocument);
            self::assertIsString($oldSignature);
            try {
                $applier->apply($installationId, $oldDocument, $oldSignature, $keyId);
                self::fail('Stale signed entitlement was accepted.');
            } catch (\DomainException $error) {
                self::assertSame('Stale entitlement sequence.', $error->getMessage());
            }
            $foreignDocument = base64_decode($new['documentBase64'], true);
            $foreignSignature = base64_decode($new['signatureBase64'], true);
            self::assertIsString($foreignDocument);
            self::assertIsString($foreignSignature);
            try {
                $applier->apply($this->uuid(), $foreignDocument, $foreignSignature, $keyId);
                self::fail('Wrong-installation signed entitlement was accepted.');
            } catch (\DomainException $error) {
                self::assertSame('Entitlement installation mismatch.', $error->getMessage());
            }
            self::assertSame(2, (int) $core->createCommand(
                'SELECT count(*) FROM platform_runtime.entitlement_documents WHERE installation_id = CAST(:id AS uuid)',
                [':id' => $installationId])->queryScalar());
        } finally {
            if (is_resource($server)) {
                proc_terminate($server, 9);
                foreach ($serverPipes as $pipe) { fclose($pipe); }
                proc_close($server);
            }
            if ($installationId !== null) {
                $core->createCommand('TRUNCATE platform_runtime.entitlement_documents, platform_runtime.installations')->execute();
            }
            if ($this->connection->isTransactionActive()) { $this->connection->rollBack(); }
            $this->connection->beginTransaction();
            @unlink($keyFile);
        }
    }

    /** @param resource $server
     *  @param array<int, resource> $pipes
     */
    private function waitForHttp(string $url, $server, array $pipes): void
    {
        $deadline = microtime(true) + 10;
        $lastStatus = 0;
        $lastBody = '';
        do {
            [$lastStatus, $lastBody] = $this->httpGet($url);
            if ($lastStatus === 200) { return; }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        proc_terminate($server, 9);
        $logs = stream_get_contents($pipes[2]);
        self::fail('Control Plane HTTP process did not become reachable: status=' . $lastStatus
            . ', body=' . substr($lastBody, 0, 500) . ', logs=' . substr((string) $logs, 0, 1000));
    }

    /** @return array{int,string} */
    private function httpGet(string $url, ?string $authorization = null): array
    {
        $headers = ['Accept: application/json'];
        if ($authorization !== null) { $headers[] = 'Authorization: ' . $authorization; }
        $curl = curl_init($url);
        self::assertNotFalse($curl);
        curl_setopt_array($curl, [CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return [$status, is_string($body) ? $body : ''];
    }

    /** @param array<string,string> $environment */
    private function syncThroughCli(string $backendRoot, array $environment, int $expected): void
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, 'yii', 'runtime:entitlements:sync'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $backendRoot, $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $errors . (string) $output);
        self::assertIsString($output);
        self::assertStringContainsString('"entitlementSequence":' . $expected, $output);
    }

    /** @return array<string,mixed> */
    private function commercialEffectSnapshot(string $subscription, string $deployment): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT s.status, s.current_period_start, s.current_period_end,
  (SELECT count(*) FROM commercial.invoices WHERE subscription_id=s.id AND status='paid') AS paid_invoices,
  (SELECT count(*) FROM commercial.payments p JOIN commercial.invoices i ON i.id=p.invoice_id
    WHERE i.subscription_id=s.id AND p.status='succeeded') AS succeeded_payments,
  (SELECT count(*) FROM commercial.audit_events WHERE resource_id=s.id::text) AS audit_events,
  (SELECT count(*) FROM commercial.outbox_events WHERE aggregate_id=s.id) AS subscription_outbox,
  (SELECT count(*) FROM commercial.deployment_entitlement_documents WHERE deployment_id=?) AS signed_documents,
  (SELECT count(*) FROM commercial.outbox_events WHERE aggregate_id=? AND event_type='entitlement.issued') AS entitlement_outbox
FROM commercial.subscriptions s WHERE s.id=?
SQL, [$deployment, $deployment, $subscription]);
        self::assertIsArray($row);
        return $row;
    }

    /** @param array<string,string> $environment */
    private function assertSyncUnavailable(string $backendRoot, array $environment): void
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, 'yii', 'runtime:entitlements:sync'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $backendRoot, $environment);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(0, proc_close($process), 'Tenant sync unexpectedly succeeded while unavailable.');
    }
}
