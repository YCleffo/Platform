<?php

declare(strict_types=1);

namespace ControlPlane\Deployment\Application;

use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Creates the durable request; the provisioner performs all mutable work. */
final readonly class RequestDeploymentUpdate
{
    public function __construct(private Connection $db, private string $environment) {}

    public function request(string $deploymentId, string $targetReleaseId, string $idempotencyKey): string
    {
        if (!in_array($this->environment, ['dev', 'test'], true)
            || !Uuid::isValid($deploymentId) || !Uuid::isValid($targetReleaseId)) {
            throw new \DomainException('Deployment update is unavailable.');
        }
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        return $this->db->transactional(function (Connection $db) use ($deploymentId, $targetReleaseId, $idempotencyKey): string {
            $d = $db->fetchAssociative("SELECT d.*, current.version AS current_version, current.channel AS current_channel
                FROM commercial.deployments d JOIN commercial.releases current ON current.id = d.current_release_id
                WHERE d.id = ? FOR UPDATE", [$deploymentId]);
            if ($d === false || $d['mode'] !== 'dedicated' || $d['status'] !== 'active'
                || $d['archived_at'] !== null || $d['current_release_id'] === null) {
                throw new \DomainException('Deployment is not eligible for update.');
            }
            $same = $db->fetchOne('SELECT id FROM commercial.deployment_operations WHERE deployment_id = ? AND idempotency_key = ?',
                [$deploymentId, $idempotencyKey]);
            if (is_string($same)) { return $same; }
            $target = $db->fetchAssociative("SELECT id, version, channel, status, oci_reference, image_digest
                FROM commercial.releases WHERE id = ? FOR UPDATE", [$targetReleaseId]);
            if ($target === false || $target['status'] !== 'published' || $target['oci_reference'] === null
                || !is_string($target['image_digest']) || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $target['image_digest']) !== 1
                || $target['channel'] !== $d['current_channel']
                || version_compare((string) $target['version'], (string) $d['current_version'], '<=')) {
                throw new \DomainException('Target release is not eligible for update.');
            }
            $busy = $db->fetchOne("SELECT 1 FROM commercial.deployment_operations WHERE deployment_id = ?
                AND operation_type IN ('provision', 'update', 'deprovision')
                AND status IN ('pending', 'running', 'retry')", [$deploymentId]);
            if ($busy !== false) { throw new \DomainException('Another deployment operation is in progress.'); }
            $operation = $db->fetchOne("INSERT INTO commercial.deployment_operations
                (deployment_id, operation_type, idempotency_key, status, current_step)
                VALUES (?, 'update', ?, 'pending', 'quiesce') RETURNING id", [$deploymentId, $idempotencyKey]);
            if (!is_string($operation)) { throw new \LogicException('Operation ID unavailable.'); }
            $db->executeStatement('UPDATE commercial.deployments SET desired_release_id = ?, updated_at = now() WHERE id = ?',
                [$targetReleaseId, $deploymentId]);
            return $operation;
        });
    }
}
