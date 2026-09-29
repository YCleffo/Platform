<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use Predis\ClientInterface as PredisClientInterface;
use RuntimeException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

final class TestReset
{
    /**
     * Resets mutable database state and test Redis cache.
     * Enforces strict fail-fast TestRuntimeGuard before executing any destructive command.
     *
     * @throws RuntimeException if guard fails or cleanup errors out.
     */
    public static function reset(ConnectionInterface $db, ?PredisClientInterface $redis = null): void
    {
        TestRuntimeGuard::assertDestructiveOperationsAllowed($db, $redis);

        $db->transaction(static function (ConnectionInterface $db): void {
            $db->createCommand('DELETE FROM core.outbox_events')->execute();
            $db->createCommand('DELETE FROM core.inbox_events')->execute();
            $db->createCommand('DELETE FROM core.audit_events')->execute();
            $db->createCommand('DELETE FROM core.auth_challenges')->execute();
            $db->createCommand('DELETE FROM core.service_credentials')->execute();
            $db->createCommand('DELETE FROM core.organization_memberships')->execute();
            $db->createCommand('DELETE FROM core.organizations')->execute();
            $db->createCommand('DELETE FROM core.privacy_cleanup_runs')->execute();

            // Isolated test reset may discard consent history; production writes cannot.
            $db->createCommand('TRUNCATE core.privacy_incident_events, core.privacy_incident_subject_scope, core.privacy_incident_categories, core.privacy_incidents, core.privacy_distribution_consents, core.privacy_destruction_evidence, core.privacy_erasure_item_purposes, core.privacy_erasure_items, core.privacy_erasure_cases, core.privacy_retention_decisions, core.privacy_legal_holds, core.data_subject_request_executions, core.privacy_processing_blocks, core.data_subject_request_events, core.data_subject_request_deadlines, core.data_subject_requests, core.privacy_consents, core.legal_representation_scopes, core.legal_representations')->execute();

            // Deleting accounts cascades to auth_sessions, auth_identifiers, credentials, passkeys, factors, tokens
            $db->createCommand('DELETE FROM core.accounts')->execute();

            // Deleting persons cascades to person_contacts
            $db->createCommand('DELETE FROM core.persons')->execute();

            $db->createCommand('DELETE FROM core.module_settings')->execute();
            $db->createCommand('DELETE FROM core.feature_flags')->execute();
            $db->createCommand('DELETE FROM core.system_settings')->execute();
            $db->createCommand('DELETE FROM core.modules')->execute();

            $db->createCommand(
                'DELETE FROM core.role_permissions WHERE role_id NOT IN (SELECT id FROM core.roles WHERE is_system = true)',
            )->execute();
            $db->createCommand('DELETE FROM core.permissions WHERE is_system = false')->execute();
            $db->createCommand('DELETE FROM core.roles WHERE is_system = false')->execute();
        });

        if ($redis !== null) {
            try {
                $redis->flushdb();
            } catch (Throwable $e) {
                throw new RuntimeException('Test Redis reset failed loudly: ' . $e->getMessage(), 0, $e);
            }
        }
    }
}
