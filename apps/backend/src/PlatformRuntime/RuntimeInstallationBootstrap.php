<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class RuntimeInstallationBootstrap
{
    public function __construct(private ConnectionInterface $db) {}

    /** @param array{installationId:string,mode:string,environment:string,applicationVersion:string,releaseId:string,releaseChannel:string} $identity */
    public function initialize(array $identity): string
    {
        if (preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $identity['installationId']) !== 1
            || !in_array($identity['mode'], ['shared', 'dedicated', 'on_prem'], true)
            || !in_array($identity['environment'], ['development', 'test', 'staging', 'production'], true)
            || !in_array($identity['releaseChannel'], ['stable', 'beta', 'canary'], true)
            || $identity['applicationVersion'] === '' || strlen($identity['applicationVersion']) > 120
            || $identity['releaseId'] === '' || strlen($identity['releaseId']) > 160
            || preg_match('/[[:cntrl:]]/', $identity['applicationVersion'] . $identity['releaseId']) === 1) {
            throw new DomainException('Invalid installation configuration.');
        }
        return $this->db->transaction(function () use ($identity): string {
            $this->db->createCommand('SELECT pg_advisory_xact_lock(47001, 1)')->queryScalar();
            $this->db->createCommand(<<<'SQL'
INSERT INTO platform_runtime.installations
 (id, deployment_mode, environment, application_version, release_id, release_channel, status)
VALUES (CAST(:id AS uuid), CAST(:mode AS platform_runtime.deployment_mode),
        CAST(:environment AS platform_runtime.environment), :version, :release,
        CAST(:channel AS platform_runtime.release_channel), 'provisioned')
ON CONFLICT DO NOTHING
SQL, [':id' => $identity['installationId'], ':mode' => $identity['mode'],
                ':environment' => $identity['environment'], ':version' => $identity['applicationVersion'],
                ':release' => $identity['releaseId'], ':channel' => $identity['releaseChannel']])->execute();
            $row = $this->db->createCommand(<<<'SQL'
SELECT id::text, deployment_mode::text, environment::text, application_version,
       release_id, release_channel::text FROM platform_runtime.installations WHERE singleton_key = 1 FOR UPDATE
SQL)->queryOne();
            if (!is_array($row) || $row['id'] !== strtolower($identity['installationId'])
                || $row['deployment_mode'] !== $identity['mode']
                || $row['environment'] !== $identity['environment']
                || $row['application_version'] !== $identity['applicationVersion']
                || $row['release_id'] !== $identity['releaseId']
                || $row['release_channel'] !== $identity['releaseChannel']) {
                throw new DomainException('Installation identity conflicts with existing runtime.');
            }
            return $row['id'];
        });
    }
}
