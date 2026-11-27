<?php

declare(strict_types=1);

namespace App\Workspace\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Realtime\Application\RealtimeService;
use App\Shared\Application\HttpError;
use App\Workspace\Domain\WorkspaceAccess;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class WorkspaceOperationService
{
    private const ALLOWED_MODES = ['immediate', 'reversible', 'deferred', 'background', 'irreversible', 'confirmed'];
    private const UNDO_WINDOW_SECONDS = 10;

    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
        private WorkspaceTrashService $trash,
        private WorkspacePreferencesService $preferences,
        private WorkspaceAudit $audit,
        private RealtimeService $realtime,
    ) {}

    /**
     * Executes a workspace action in one of the canonical modes:
     * immediate | reversible | deferred | background | irreversible | confirmed.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(AuthenticatedIdentity $identity, string $organizationId, array $input): array
    {
        $personId = $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $rawActionKey = $input['actionKey'] ?? $input['actionCode'] ?? null;
        $actionKey = is_string($rawActionKey) ? trim($rawActionKey) : '';
        if ($actionKey === '' || strlen($actionKey) > 120) {
            throw new HttpError(422, 'invalid_action_key');
        }

        $rawMode = $input['mode'] ?? null;
        $mode = is_string($rawMode) ? trim($rawMode) : 'immediate';
        if (!in_array($mode, self::ALLOWED_MODES, true)) {
            throw new HttpError(422, 'invalid_action_mode');
        }

        $rawEntityType = $input['entityType'] ?? $input['targetType'] ?? null;
        $entityType = is_string($rawEntityType) ? trim($rawEntityType) : '';
        $rawEntityId = $input['entityId'] ?? $input['targetId'] ?? null;
        $entityId = is_string($rawEntityId) && $rawEntityId !== ''
            ? RealtimeService::assertUuid($rawEntityId, 'entityId')
            : null;

        /** @var array<string, mixed> $payload */
        $payload = is_array($input['payload'] ?? null)
            ? $input['payload']
            : (is_array($input['params'] ?? null) ? $input['params'] : []);
        $confirmDangerous = ($input['confirmDangerous'] ?? $input['confirmed'] ?? false) === true;

        $this->assertActionSupported($actionKey);

        // Security, auth, finance, and destructive purge actions MUST NEVER be deferred or fake-undoable.
        $isHighRiskNonDeferrable = self::isHighRiskAction($actionKey);
        if ($isHighRiskNonDeferrable && in_array($mode, ['reversible', 'deferred'], true)) {
            throw new HttpError(422, 'action_mode_not_allowed');
        }

        if ((in_array($mode, ['irreversible', 'confirmed'], true) || $isHighRiskNonDeferrable) && !$confirmDangerous) {
            throw new HttpError(422, 'confirmation_required');
        }

        $delaySeconds = 0;
        if ($mode === 'deferred') {
            $delaySeconds = isset($input['delaySeconds']) && is_numeric($input['delaySeconds'])
                ? (int) $input['delaySeconds']
                : 15;
            if ($delaySeconds < 1 || $delaySeconds > 86400) {
                throw new HttpError(422, 'invalid_delay_seconds');
            }
        }

        $isPurge = $actionKey === 'trash.purge';
        $isEmptyTrash = $actionKey === 'trash.empty';
        if ($isPurge || $isEmptyTrash) {
            if ($isPurge && $entityId === null) {
                throw new HttpError(422, 'trash_id_required');
            }
            $purgeResult = $isPurge
                ? $this->trash->deletePermanent($identity, $orgId, $entityId)
                : $this->trash->emptyTrash($identity, $orgId);
            $op = $this->insertOperation(
                orgId: $orgId,
                accountId: $identity->accountId,
                personId: $personId,
                actionKey: $actionKey,
                entityType: $entityType !== '' ? $entityType : (string) ($purgeResult['entityType'] ?? 'workspace.trash'),
                entityId: $entityId,
                payload: $payload,
                mode: $mode,
                status: 'completed',
                undoDeadlineSql: null,
                executeDelaySeconds: null,
                result: $purgeResult,
                finalized: true,
            );
            $this->audit->record(
                action: 'workspace.action.executed',
                actorAccountId: $identity->accountId,
                resourceType: 'workspace.operation',
                resourceId: (string) $op['id'],
                metadata: [
                    'organizationId' => $orgId,
                    'actionKey' => $actionKey,
                    'mode' => (string) $op['mode'],
                    'status' => (string) $op['status'],
                    'entityType' => (string) $op['entityType'],
                    'entityId' => $entityId,
                    'revision' => (int) $op['revision'],
                ],
            );
            $this->publishOperationUpdated($op);
            return $op;
        }

        $op = $this->db->transaction(function () use (
            $identity,
            $orgId,
            $personId,
            $actionKey,
            $entityType,
            $entityId,
            $payload,
            $mode,
            $delaySeconds,
        ): array {
            if ($mode === 'deferred') {
                return $this->insertOperation(
                    orgId: $orgId,
                    accountId: $identity->accountId,
                    personId: $personId,
                    actionKey: $actionKey,
                    entityType: $entityType,
                    entityId: $entityId,
                    payload: $payload,
                    mode: 'deferred',
                    status: 'pending',
                    undoDeadlineSql: null,
                    executeDelaySeconds: $delaySeconds,
                    result: [],
                    finalized: false,
                );
            }

            if ($mode === 'background') {
                $result = $this->applyEffect($identity, $orgId, $personId, $actionKey, $entityType, $entityId, $payload);
                $status = ($payload['initialStatus'] ?? null) === 'running' ? 'running' : 'completed';
                return $this->insertOperation(
                    orgId: $orgId,
                    accountId: $identity->accountId,
                    personId: $personId,
                    actionKey: $actionKey,
                    entityType: $entityType,
                    entityId: $entityId,
                    payload: $payload,
                    mode: 'background',
                    status: $status,
                    undoDeadlineSql: null,
                    executeDelaySeconds: null,
                    result: $result,
                    finalized: $status === 'completed',
                );
            }

            $result = $this->applyEffect($identity, $orgId, $personId, $actionKey, $entityType, $entityId, $payload);

            if ($mode === 'reversible') {
                return $this->insertOperation(
                    orgId: $orgId,
                    accountId: $identity->accountId,
                    personId: $personId,
                    actionKey: $actionKey,
                    entityType: $entityType,
                    entityId: $entityId,
                    payload: $payload,
                    mode: 'reversible',
                    status: 'undoable',
                    undoDeadlineSql: sprintf("now() + interval '%d seconds'", self::UNDO_WINDOW_SECONDS),
                    executeDelaySeconds: null,
                    result: $result,
                    finalized: false,
                );
            }

            return $this->insertOperation(
                orgId: $orgId,
                accountId: $identity->accountId,
                personId: $personId,
                actionKey: $actionKey,
                entityType: $entityType,
                entityId: $entityId,
                payload: $payload,
                mode: $mode,
                status: 'completed',
                undoDeadlineSql: null,
                executeDelaySeconds: null,
                result: $result,
                finalized: true,
            );
        });

        $this->audit->record(
            action: 'workspace.action.executed',
            actorAccountId: $identity->accountId,
            resourceType: 'workspace.operation',
            resourceId: (string) $op['id'],
            metadata: [
                'organizationId' => $orgId,
                'actionKey' => $actionKey,
                'mode' => (string) $op['mode'],
                'status' => (string) $op['status'],
                'entityType' => $entityType !== '' ? $entityType : null,
                'entityId' => $entityId,
                'revision' => (int) $op['revision'],
            ],
        );

        $this->publishOperationUpdated($op);

        return $op;
    }

    /**
     * Registers or updates a tracked background operation (e.g. long upload).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function trackOperation(AuthenticatedIdentity $identity, string $organizationId, array $input): array
    {
        $personId = $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $rawId = $input['operationId'] ?? $input['id'] ?? null;
        $rawActionKey = $input['actionKey'] ?? null;
        $actionKey = is_string($rawActionKey) ? trim($rawActionKey) : 'media.upload';
        $rawEntityType = $input['entityType'] ?? null;
        $entityType = is_string($rawEntityType) ? trim($rawEntityType) : 'media.asset';
        $rawEntityId = $input['entityId'] ?? null;
        $entityId = is_string($rawEntityId) && $rawEntityId !== ''
            ? RealtimeService::assertUuid($rawEntityId, 'entityId')
            : null;
        $rawStatus = $input['status'] ?? null;
        $status = is_string($rawStatus) && in_array($rawStatus, ['pending', 'running', 'completed', 'cancelled', 'failed'], true)
            ? $rawStatus
            : 'running';
        /** @var array<string, mixed> $payload */
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        /** @var array<string, mixed> $result */
        $result = is_array($input['result'] ?? null) ? $input['result'] : [];

        if (is_string($rawId) && $rawId !== '') {
            $opId = RealtimeService::assertUuid($rawId, 'operationId');
            $finalizedSql = in_array($status, ['completed', 'cancelled', 'failed'], true) ? 'COALESCE(finalized_at, now())' : 'NULL';
            $rawErrCode = $input['errorCode'] ?? null;
            $rawErrMsg = $input['errorMessage'] ?? null;
            $row = $this->db->createCommand(
                "UPDATE workspace.operations
                 SET status = :status,
                     result = COALESCE(result, '{}'::jsonb) || CAST(:result AS jsonb),
                     error_code = :errCode,
                     error_message = :errMsg,
                     revision = revision + 1,
                     updated_at = now(),
                     finalized_at = {$finalizedSql}
                 WHERE id = CAST(:id AS uuid)
                   AND organization_id = CAST(:org AS uuid)
                   AND account_id = CAST(:account AS uuid)
                 RETURNING " . self::SELECT_COLUMNS,
                [
                    ':status' => $status,
                    ':result' => json_encode($result, JSON_THROW_ON_ERROR),
                    ':errCode' => is_string($rawErrCode) ? substr($rawErrCode, 0, 80) : null,
                    ':errMsg' => is_string($rawErrMsg) ? substr($rawErrMsg, 0, 500) : null,
                    ':id' => $opId,
                    ':org' => $orgId,
                    ':account' => $identity->accountId,
                ],
            )->queryOne();
            if (!is_array($row)) {
                throw new HttpError(404, 'operation_not_found');
            }
            $op = self::hydrateOperation($row);
        } else {
            $op = $this->insertOperation(
                orgId: $orgId,
                accountId: $identity->accountId,
                personId: $personId,
                actionKey: $actionKey,
                entityType: $entityType,
                entityId: $entityId,
                payload: $payload,
                mode: 'background',
                status: $status,
                undoDeadlineSql: null,
                executeDelaySeconds: null,
                result: $result,
                finalized: in_array($status, ['completed', 'cancelled', 'failed'], true),
            );
        }

        $this->publishOperationUpdated($op);
        return $op;
    }

    /**
     * Atomically undoes a reversible operation within its 10-second undo window using CAS.
     *
     * @return array<string, mixed>
     */
    public function undo(AuthenticatedIdentity $identity, string $organizationId, string $operationId): array
    {
        $personId = $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $opId = RealtimeService::assertUuid($operationId, 'operationId');

        /** @var array<string, mixed> $undoneOp */
        $undoneOp = $this->db->transaction(function () use ($identity, $orgId, $personId, $opId): array {
            $row = $this->db->createCommand(
                "UPDATE workspace.operations
                 SET status = 'undone',
                     revision = revision + 1,
                     finalized_at = NOW(),
                     updated_at = NOW()
                 WHERE id = CAST(:id AS uuid)
                   AND organization_id = CAST(:org AS uuid)
                   AND account_id = CAST(:account AS uuid)
                   AND status = 'undoable'
                   AND undo_deadline > NOW()
                   AND finalized_at IS NULL
                 RETURNING " . self::SELECT_COLUMNS,
                [
                    ':id' => $opId,
                    ':org' => $orgId,
                    ':account' => $identity->accountId,
                ],
            )->queryOne();

            if (!is_array($row)) {
                $existing = $this->db->createCommand(
                    'SELECT account_id::text AS "accountId", status, undo_deadline::text AS "undoDeadline"
                     FROM workspace.operations
                     WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
                    [':id' => $opId, ':org' => $orgId],
                )->queryOne();

                if (!is_array($existing)) {
                    throw new HttpError(404, 'operation_not_found');
                }
                if (strcasecmp((string) $existing['accountId'], $identity->accountId) !== 0) {
                    throw new HttpError(403, 'access_denied');
                }
                if ($existing['status'] === 'undone') {
                    throw new HttpError(409, 'already_undone');
                }
                if (in_array($existing['status'], ['undoable', 'committed', 'completed'], true)) {
                    throw new HttpError(409, 'undo_window_expired');
                }
                throw new HttpError(409, 'operation_not_undoable');
            }

            $hydrated = self::hydrateOperation($row);
            $this->reverseEffect($identity, $orgId, $personId, $hydrated);

            return $hydrated;
        });

        $this->audit->record(
            action: 'workspace.operation.undone',
            actorAccountId: $identity->accountId,
            resourceType: 'workspace.operation',
            resourceId: $opId,
            metadata: [
                'organizationId' => $orgId,
                'actionKey' => (string) $undoneOp['actionKey'],
                'mode' => (string) $undoneOp['mode'],
                'status' => 'undone',
                'revision' => (int) $undoneOp['revision'],
            ],
        );

        $this->publishOperationUpdated($undoneOp);

        return $undoneOp;
    }

    /** @return array<string, mixed> */
    public function cancel(AuthenticatedIdentity $identity, string $organizationId, string $operationId): array
    {
        $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $opId = RealtimeService::assertUuid($operationId, 'operationId');

        $row = $this->db->createCommand(
            "UPDATE workspace.operations
             SET status = 'cancelled',
                 revision = revision + 1,
                 finalized_at = NOW(),
                 updated_at = NOW()
             WHERE id = CAST(:id AS uuid)
               AND organization_id = CAST(:org AS uuid)
               AND account_id = CAST(:account AS uuid)
               AND status IN ('pending', 'running')
               AND finalized_at IS NULL
             RETURNING " . self::SELECT_COLUMNS,
            [':id' => $opId, ':org' => $orgId, ':account' => $identity->accountId],
        )->queryOne();

        if (!is_array($row)) {
            $existing = $this->db->createCommand(
                'SELECT account_id::text AS "accountId", status FROM workspace.operations
                 WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
                [':id' => $opId, ':org' => $orgId],
            )->queryOne();
            if (!is_array($existing)) {
                throw new HttpError(404, 'operation_not_found');
            }
            if (strcasecmp((string) $existing['accountId'], $identity->accountId) !== 0) {
                throw new HttpError(403, 'access_denied');
            }
            throw new HttpError(409, 'operation_cannot_cancel');
        }

        $op = self::hydrateOperation($row);

        $this->audit->record(
            action: 'workspace.operation.cancelled',
            actorAccountId: $identity->accountId,
            resourceType: 'workspace.operation',
            resourceId: $opId,
            metadata: [
                'organizationId' => $orgId,
                'actionKey' => (string) $op['actionKey'],
                'status' => 'cancelled',
                'revision' => (int) $op['revision'],
            ],
        );

        $this->publishOperationUpdated($op);
        return $op;
    }

    /** @return array<string, mixed> */
    public function retry(AuthenticatedIdentity $identity, string $organizationId, string $operationId): array
    {
        $personId = $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $opId = RealtimeService::assertUuid($operationId, 'operationId');

        $op = $this->db->transaction(function () use ($identity, $orgId, $personId, $opId): array {
            $row = $this->db->createCommand(
                'SELECT ' . self::SELECT_COLUMNS . '
                 FROM workspace.operations
                 WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)
                 FOR UPDATE',
                [':id' => $opId, ':org' => $orgId],
            )->queryOne();

            if (!is_array($row)) {
                throw new HttpError(404, 'operation_not_found');
            }
            if (strcasecmp((string) $row['accountId'], $identity->accountId) !== 0) {
                throw new HttpError(403, 'access_denied');
            }
            if (!in_array($row['status'], ['failed', 'cancelled'], true)) {
                throw new HttpError(409, 'operation_cannot_retry');
            }

            $hydrated = self::hydrateOperation($row);
            /** @var array<string, mixed> $payload */
            $payload = is_array($hydrated['payload']) ? $hydrated['payload'] : [];
            $result = $this->applyEffect(
                $identity,
                $orgId,
                $personId,
                (string) $hydrated['actionKey'],
                (string) $hydrated['entityType'],
                $hydrated['entityId'] !== null ? (string) $hydrated['entityId'] : null,
                $payload,
            );

            $updated = $this->db->createCommand(
                "UPDATE workspace.operations
                 SET status = 'completed',
                     result = CAST(:result AS jsonb),
                     error_code = NULL,
                     error_message = NULL,
                     revision = revision + 1,
                     finalized_at = NOW(),
                     updated_at = NOW()
                 WHERE id = CAST(:id AS uuid)
                 RETURNING " . self::SELECT_COLUMNS,
                [':id' => $opId, ':result' => json_encode($result, JSON_THROW_ON_ERROR)],
            )->queryOne();

            return is_array($updated) ? self::hydrateOperation($updated) : $hydrated;
        });

        $this->audit->record(
            action: 'workspace.operation.retried',
            actorAccountId: $identity->accountId,
            resourceType: 'workspace.operation',
            resourceId: $opId,
            metadata: [
                'organizationId' => $orgId,
                'actionKey' => (string) $op['actionKey'],
                'status' => (string) $op['status'],
                'revision' => (int) $op['revision'],
            ],
        );

        $this->publishOperationUpdated($op);
        return $op;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{items: list<array<string, mixed>>, hasMore: bool, offset: int}
     */
    public function listOperations(AuthenticatedIdentity $identity, string $organizationId, array $query = []): array
    {
        $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        // Lazily commit overdue undoable operations so status stays fresh on read
        $this->commitOverdueUndoable(50);

        $limit = max(1, min(100, isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 30));
        $offset = max(0, isset($query['offset']) && is_numeric($query['offset']) ? (int) $query['offset'] : 0);
        $status = is_string($query['status'] ?? null) && $query['status'] !== '' ? $query['status'] : null;

        $where = 'organization_id = CAST(:org AS uuid) AND account_id = CAST(:account AS uuid)';
        $params = [
            ':org' => $orgId,
            ':account' => $identity->accountId,
            ':limit' => $limit + 1,
            ':offset' => $offset,
        ];
        if ($status !== null) {
            $where .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $rows = $this->db->createCommand(
            'SELECT ' . self::SELECT_COLUMNS . "
             FROM workspace.operations
             WHERE {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset",
            $params,
        )->queryAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $items[] = self::hydrateOperation($r);
        }

        return ['items' => $items, 'hasMore' => $hasMore, 'offset' => $offset];
    }

    /**
     * Atomically commits overdue undoable operations and executes due deferred operations.
     */
    public function processDue(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $processed = $this->commitOverdueUndoable($limit);
        $processed += $this->executeDueDeferred($limit);
        return $processed;
    }

    public function commitOverdueUndoable(int $limit = 100): int
    {
        /** @var list<array<string, mixed>> $committed */
        $committed = $this->db->transaction(function () use ($limit): array {
            $rows = $this->db->createCommand(
                "WITH due AS (
                    SELECT id
                    FROM workspace.operations
                    WHERE status = 'undoable'
                      AND undo_deadline <= NOW()
                      AND finalized_at IS NULL
                    ORDER BY undo_deadline ASC
                    LIMIT :limit
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE workspace.operations o
                SET status = 'committed',
                    revision = o.revision + 1,
                    finalized_at = NOW(),
                    updated_at = NOW()
                FROM due
                WHERE o.id = due.id
                  AND o.status = 'undoable'
                  AND o.undo_deadline <= NOW()
                  AND o.finalized_at IS NULL
                RETURNING " . self::SELECT_COLUMNS_ALIASED,
                [':limit' => max(1, min(500, $limit))],
            )->queryAll();

            $out = [];
            /** @var array<string, mixed> $r */
            foreach ($rows as $r) {
                $out[] = self::hydrateOperation($r);
            }
            return $out;
        });

        foreach ($committed as $op) {
            $this->audit->record(
                action: 'workspace.operation.committed',
                actorAccountId: (string) $op['accountId'],
                resourceType: 'workspace.operation',
                resourceId: (string) $op['id'],
                metadata: [
                    'organizationId' => (string) $op['organizationId'],
                    'actionKey' => (string) $op['actionKey'],
                    'status' => 'committed',
                    'revision' => (int) $op['revision'],
                ],
            );
            $this->publishOperationUpdated($op);
        }

        return count($committed);
    }

    private function executeDueDeferred(int $limit): int
    {
        /** @var list<array<string, mixed>> $claimed */
        $claimed = $this->db->transaction(function () use ($limit): array {
            $rows = $this->db->createCommand(
                "WITH due AS (
                    SELECT id
                    FROM workspace.operations
                    WHERE status = 'pending'
                      AND mode = 'deferred'
                      AND execute_at <= NOW()
                      AND finalized_at IS NULL
                    ORDER BY execute_at ASC
                    LIMIT :limit
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE workspace.operations o
                SET status = 'running',
                    revision = o.revision + 1,
                    updated_at = NOW()
                FROM due
                WHERE o.id = due.id
                RETURNING " . self::SELECT_COLUMNS_ALIASED,
                [':limit' => max(1, min(500, $limit))],
            )->queryAll();

            $out = [];
            /** @var array<string, mixed> $r */
            foreach ($rows as $r) {
                $out[] = self::hydrateOperation($r);
            }
            return $out;
        });

        foreach ($claimed as $op) {
            $opId = (string) $op['id'];
            $orgId = (string) $op['organizationId'];
            $accountId = (string) $op['accountId'];
            $rawPerson = $op['personId'] ?? null;
            $personId = is_string($rawPerson) ? $rawPerson : '';

            try {
                $identity = $this->loadIdentityForAccount($accountId, $personId);
                $updated = $this->db->transaction(function () use ($identity, $orgId, $personId, $op, $opId): array {
                    /** @var array<string, mixed> $payload */
                    $payload = is_array($op['payload']) ? $op['payload'] : [];
                    $result = $this->applyEffect(
                        $identity,
                        $orgId,
                        $personId,
                        (string) $op['actionKey'],
                        (string) $op['entityType'],
                        $op['entityId'] !== null ? (string) $op['entityId'] : null,
                        $payload,
                    );
                    $row = $this->db->createCommand(
                        "UPDATE workspace.operations
                         SET status = 'completed',
                             result = CAST(:result AS jsonb),
                             revision = revision + 1,
                             finalized_at = NOW(),
                             updated_at = NOW()
                         WHERE id = CAST(:id AS uuid)
                         RETURNING " . self::SELECT_COLUMNS,
                        [':id' => $opId, ':result' => json_encode($result, JSON_THROW_ON_ERROR)],
                    )->queryOne();
                    return is_array($row) ? self::hydrateOperation($row) : $op;
                });

                $this->audit->record(
                    action: 'workspace.action.executed',
                    actorAccountId: $accountId,
                    resourceType: 'workspace.operation',
                    resourceId: $opId,
                    metadata: [
                        'organizationId' => $orgId,
                        'actionKey' => (string) $updated['actionKey'],
                        'mode' => 'deferred',
                        'status' => 'completed',
                        'revision' => (int) $updated['revision'],
                    ],
                );
                $this->publishOperationUpdated($updated);
            } catch (Throwable $e) {
                $errCode = $e instanceof HttpError ? $e->reason : 'execution_failed';
                $row = $this->db->createCommand(
                    "UPDATE workspace.operations
                     SET status = 'failed',
                         error_code = :code,
                         error_message = :msg,
                         revision = revision + 1,
                         finalized_at = NOW(),
                         updated_at = NOW()
                     WHERE id = CAST(:id AS uuid)
                     RETURNING " . self::SELECT_COLUMNS,
                    [
                        ':id' => $opId,
                        ':code' => substr($errCode, 0, 80),
                        ':msg' => mb_substr($e->getMessage(), 0, 500),
                    ],
                )->queryOne();
                $failedOp = is_array($row) ? self::hydrateOperation($row) : $op;
                $this->audit->record(
                    action: 'workspace.operation.failed',
                    actorAccountId: $accountId,
                    resourceType: 'workspace.operation',
                    resourceId: $opId,
                    metadata: ['organizationId' => $orgId, 'actionKey' => (string) $op['actionKey'], 'status' => 'failed'],
                    success: false,
                    errorCode: $errCode,
                );
                $this->publishOperationUpdated($failedOp);
            }
        }

        return count($claimed);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyEffect(
        AuthenticatedIdentity $identity,
        string $orgId,
        string $personId,
        string $actionKey,
        string $entityType,
        ?string $entityId,
        array $payload,
    ): array {
        /** @var array<string, mixed> */
        return match ($actionKey) {
            'workspace.preference.set' => $this->applyPreferenceSet($identity->accountId, $orgId, $payload),
            'notifications.mark_read' => $this->applyNotificationsMarkRead($orgId, $personId, $entityId, $payload),
            'notifications.archive' => $this->applyNotificationsArchive($orgId, $personId, $entityId, $payload),
            'trash.move' => $this->applyTrashMove($identity, $orgId, $personId, $entityType, $entityId),
            'chat.pin' => $this->applyChatPin($orgId, $personId, $entityId, $payload),
            'workspace.export.prepare', 'media.upload' => ['acknowledged' => true, 'entityType' => $entityType, 'entityId' => $entityId],
            'trash.purge' => $this->applyTrashPurge($identity, $orgId, $entityId),
            'auth.logout', 'security.session.revoke_all', 'finance.transfer' => ['executed' => true, 'actionKey' => $actionKey],
            default => throw new HttpError(422, 'unsupported_action_key'),
        };
    }

    /** @param array<string, mixed> $op */
    private function reverseEffect(
        AuthenticatedIdentity $identity,
        string $orgId,
        string $personId,
        array $op,
    ): void {
        $actionKey = (string) $op['actionKey'];
        /** @var array<string, mixed> $payload */
        $payload = is_array($op['payload']) ? $op['payload'] : [];
        /** @var array<string, mixed> $result */
        $result = is_array($op['result']) ? $op['result'] : [];

        match ($actionKey) {
            'workspace.preference.set' => $this->reversePreferenceSet($identity->accountId, $result),
            'notifications.mark_read' => $this->reverseNotificationsMarkRead($orgId, $personId, $result),
            'notifications.archive' => $this->reverseNotificationsArchive($orgId, $personId, $result),
            'trash.move' => $this->reverseTrashMove($identity, $orgId, $personId, $result),
            'chat.pin' => $this->reverseChatPin($orgId, $result),
            default => throw new HttpError(409, 'action_not_reversible'),
        };
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function applyPreferenceSet(string $accountId, string $orgId, array $payload): array
    {
        $rawKey = $payload['key'] ?? null;
        $key = is_string($rawKey) ? trim($rawKey) : '';
        if ($key === '' || strlen($key) > 120 || preg_match('/\A[a-zA-Z0-9._-]+\z/', $key) !== 1) {
            throw new HttpError(422, 'invalid_preference_key');
        }
        $scopeOrg = ($payload['scope'] ?? 'organization') === 'global' ? null : $orgId;
        $prev = $this->preferences->snapshot($accountId, $scopeOrg, $key);
        $this->preferences->upsert($accountId, $scopeOrg, $key, $payload['value'] ?? null);

        return [
            'key' => $key,
            'scopeOrg' => $scopeOrg,
            'value' => $payload['value'] ?? null,
            'previousExisted' => $prev['existed'],
            'previousValue' => $prev['value'],
        ];
    }

    /** @param array<string, mixed> $result */
    private function reversePreferenceSet(string $accountId, array $result): void
    {
        $key = (string) ($result['key'] ?? '');
        $rawScopeOrg = $result['scopeOrg'] ?? null;
        $scopeOrg = is_string($rawScopeOrg) ? $rawScopeOrg : null;
        if ($key === '') {
            return;
        }
        if (($result['previousExisted'] ?? false) === true) {
            $this->preferences->upsert($accountId, $scopeOrg, $key, $result['previousValue'] ?? null);
        } else {
            $this->preferences->deleteKey($accountId, $scopeOrg, $key);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyNotificationsMarkRead(string $orgId, string $personId, ?string $entityId, array $payload): array
    {
        $ids = $this->collectNotificationIds($entityId, $payload);
        if ($ids === []) {
            throw new HttpError(422, 'notification_id_required');
        }
        $rows = $this->db->createCommand(
            'SELECT id::text, read_at::text AS "readAt"
             FROM notifications.items
             WHERE organization_id = CAST(:org AS uuid)
               AND person_id = CAST(:person AS uuid)
               AND id = ANY(CAST(:ids AS uuid[]))
             FOR UPDATE',
            [':org' => $orgId, ':person' => $personId, ':ids' => '{' . implode(',', $ids) . '}'],
        )->queryAll();
        if ($rows === []) {
            throw new HttpError(404, 'not_found');
        }
        $unreadIds = [];
        foreach ($rows as $r) {
            if (($r['readAt'] ?? null) === null) {
                $unreadIds[] = (string) $r['id'];
            }
        }
        if ($unreadIds !== []) {
            $this->db->createCommand(
                'UPDATE notifications.items SET read_at = now() WHERE id = ANY(CAST(:ids AS uuid[]))',
                [':ids' => '{' . implode(',', $unreadIds) . '}'],
            )->execute();
        }
        return ['markedReadIds' => $unreadIds];
    }

    /** @param array<string, mixed> $result */
    private function reverseNotificationsMarkRead(string $orgId, string $personId, array $result): void
    {
        $ids = self::stringList($result['markedReadIds'] ?? null);
        if ($ids === []) {
            return;
        }
        $this->db->createCommand(
            'UPDATE notifications.items SET read_at = NULL
             WHERE organization_id = CAST(:org AS uuid)
               AND person_id = CAST(:person AS uuid)
               AND id = ANY(CAST(:ids AS uuid[]))',
            [':org' => $orgId, ':person' => $personId, ':ids' => '{' . implode(',', $ids) . '}'],
        )->execute();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyNotificationsArchive(string $orgId, string $personId, ?string $entityId, array $payload): array
    {
        $ids = $this->collectNotificationIds($entityId, $payload);
        if ($ids === []) {
            throw new HttpError(422, 'notification_id_required');
        }
        /** @var list<string> $archivedIds */
        $archivedIds = $this->db->createCommand(
            'UPDATE notifications.items
             SET archived_at = now()
             WHERE organization_id = CAST(:org AS uuid)
               AND person_id = CAST(:person AS uuid)
               AND archived_at IS NULL
               AND id = ANY(CAST(:ids AS uuid[]))
             RETURNING id::text',
            [':org' => $orgId, ':person' => $personId, ':ids' => '{' . implode(',', $ids) . '}'],
        )->queryColumn();

        if ($archivedIds === []) {
            throw new HttpError(404, 'not_found');
        }

        return ['archivedIds' => $archivedIds];
    }

    /** @param array<string, mixed> $result */
    private function reverseNotificationsArchive(string $orgId, string $personId, array $result): void
    {
        $ids = self::stringList($result['archivedIds'] ?? null);
        if ($ids === []) {
            return;
        }
        $this->db->createCommand(
            'UPDATE notifications.items SET archived_at = NULL
             WHERE organization_id = CAST(:org AS uuid)
               AND person_id = CAST(:person AS uuid)
               AND id = ANY(CAST(:ids AS uuid[]))',
            [':org' => $orgId, ':person' => $personId, ':ids' => '{' . implode(',', $ids) . '}'],
        )->execute();
    }

    /** @return list<string> */
    private static function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function applyTrashMove(
        AuthenticatedIdentity $identity,
        string $orgId,
        string $personId,
        string $entityType,
        ?string $entityId,
    ): array {
        if ($entityType === '' || $entityId === null) {
            throw new HttpError(422, 'entity_required');
        }
        $trashItem = $this->trash->moveToTrashInTransaction($identity, $orgId, $personId, $entityType, $entityId);
        return [
            'trashId' => (string) $trashItem['id'],
            'entityType' => $entityType,
            'entityId' => $entityId,
            'title' => (string) $trashItem['title'],
        ];
    }

    /** @param array<string, mixed> $result */
    private function reverseTrashMove(
        AuthenticatedIdentity $identity,
        string $orgId,
        string $personId,
        array $result,
    ): void {
        $trashId = (string) ($result['trashId'] ?? '');
        if ($trashId === '') {
            throw new HttpError(409, 'trash_item_missing');
        }
        $this->trash->restoreInTransaction($identity, $orgId, $personId, $trashId, true);
    }

    /** @return array<string, mixed> */
    private function applyTrashPurge(AuthenticatedIdentity $identity, string $orgId, ?string $entityId): array
    {
        if ($entityId === null) {
            throw new HttpError(422, 'trash_id_required');
        }
        // Perform inside current or immediate flow via direct purge check
        $item = $this->db->createCommand(
            'SELECT id::text, entity_type AS "entityType", entity_id::text AS "entityId", restore_status AS "restoreStatus"
             FROM workspace.trash_items
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
            [':id' => $entityId, ':org' => $orgId],
        )->queryOne();
        if (!is_array($item)) {
            throw new HttpError(404, 'trash_item_not_found');
        }
        return ['trashId' => $entityId, 'confirmed' => true];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function applyChatPin(string $orgId, string $personId, ?string $entityId, array $payload): array
    {
        if (!$this->access->isModuleEnabled('chat')) {
            throw new HttpError(404, 'module_unavailable');
        }
        $rawRoomId = $payload['roomId'] ?? null;
        $roomId = is_string($rawRoomId) && $rawRoomId !== ''
            ? RealtimeService::assertUuid($rawRoomId, 'roomId')
            : ($entityId ?? throw new HttpError(422, 'room_id_required'));
        $rawMessageId = $payload['messageId'] ?? null;
        $messageId = is_string($rawMessageId) && $rawMessageId !== ''
            ? RealtimeService::assertUuid($rawMessageId, 'messageId')
            : null;

        $member = $this->db->createCommand(
            'SELECT 1 FROM chat.members
             WHERE room_id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)
               AND person_id = CAST(:person AS uuid) AND left_at IS NULL',
            [':room' => $roomId, ':org' => $orgId, ':person' => $personId],
        )->queryScalar();
        if ($member === false) {
            throw new HttpError(403, 'access_denied');
        }

        $room = $this->db->createCommand(
            'SELECT pinned_message_id::text AS "pinnedMessageId"
             FROM chat.rooms
             WHERE id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)
             FOR UPDATE',
            [':room' => $roomId, ':org' => $orgId],
        )->queryOne();
        if (!is_array($room)) {
            throw new HttpError(404, 'room_not_found');
        }

        if ($messageId !== null) {
            $msgOk = $this->db->createCommand(
                'SELECT 1 FROM chat.messages
                 WHERE id = CAST(:mid AS uuid) AND room_id = CAST(:room AS uuid) AND deleted_at IS NULL',
                [':mid' => $messageId, ':room' => $roomId],
            )->queryScalar();
            if ($msgOk === false) {
                throw new HttpError(404, 'message_not_found');
            }
        }

        $this->db->createCommand(
            'UPDATE chat.rooms SET pinned_message_id = CAST(:mid AS uuid), updated_at = now()
             WHERE id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)',
            [':mid' => $messageId, ':room' => $roomId, ':org' => $orgId],
        )->execute();

        return [
            'roomId' => $roomId,
            'pinnedMessageId' => $messageId,
            'previousPinnedMessageId' => $room['pinnedMessageId'],
        ];
    }

    /** @param array<string, mixed> $result */
    private function reverseChatPin(string $orgId, array $result): void
    {
        $roomId = (string) ($result['roomId'] ?? '');
        if ($roomId === '') {
            return;
        }
        $prev = is_string($result['previousPinnedMessageId'] ?? null) && $result['previousPinnedMessageId'] !== ''
            ? $result['previousPinnedMessageId']
            : null;
        $this->db->createCommand(
            'UPDATE chat.rooms SET pinned_message_id = CAST(:mid AS uuid), updated_at = now()
             WHERE id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)',
            [':mid' => $prev, ':room' => $roomId, ':org' => $orgId],
        )->execute();
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function collectNotificationIds(?string $entityId, array $payload): array
    {
        $seen = [];
        $ids = [];
        if ($entityId !== null) {
            $seen[$entityId] = true;
            $ids[] = $entityId;
        }
        $rawIds = $payload['ids'] ?? null;
        if (is_array($rawIds)) {
            $count = 0;
            foreach ($rawIds as $raw) {
                if (++$count > 100) {
                    break;
                }
                if (is_string($raw) && $raw !== '') {
                    $uuid = RealtimeService::assertUuid($raw, 'ids');
                    if (!isset($seen[$uuid])) {
                        $seen[$uuid] = true;
                        $ids[] = $uuid;
                    }
                }
            }
        }
        return $ids;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function insertOperation(
        string $orgId,
        string $accountId,
        string $personId,
        string $actionKey,
        string $entityType,
        ?string $entityId,
        array $payload,
        string $mode,
        string $status,
        ?string $undoDeadlineSql,
        ?int $executeDelaySeconds,
        array $result,
        bool $finalized,
    ): array {
        $undoExpr = $undoDeadlineSql ?? 'NULL';
        $execExpr = $executeDelaySeconds !== null
            ? sprintf("now() + interval '%d seconds'", $executeDelaySeconds)
            : 'NULL';
        $finalizedExpr = $finalized ? 'now()' : 'NULL';

        $row = $this->db->createCommand(
            "INSERT INTO workspace.operations (
                organization_id, account_id, person_id, action_key, entity_type, entity_id,
                payload, mode, status, undo_deadline, execute_at, result, finalized_at
             ) VALUES (
                CAST(:org AS uuid), CAST(:account AS uuid), CAST(:person AS uuid), :actionKey, :entityType, CAST(:entityId AS uuid),
                CAST(:payload AS jsonb), :mode, :status, {$undoExpr}, {$execExpr}, CAST(:result AS jsonb), {$finalizedExpr}
             )
             RETURNING " . self::SELECT_COLUMNS,
            [
                ':org' => $orgId,
                ':account' => $accountId,
                ':person' => $personId !== '' ? $personId : null,
                ':actionKey' => $actionKey,
                ':entityType' => $entityType,
                ':entityId' => $entityId,
                ':payload' => $payload === [] ? '{}' : json_encode($payload, JSON_THROW_ON_ERROR),
                ':mode' => $mode,
                ':status' => $status,
                ':result' => $result === [] ? '{}' : json_encode($result, JSON_THROW_ON_ERROR),
            ],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(500, 'operation_insert_failed');
        }
        return self::hydrateOperation($row);
    }

    /** @param array<string, mixed> $op */
    private function publishOperationUpdated(array $op): void
    {
        $orgId = (string) $op['organizationId'];
        $rawPersonId = $op['personId'] ?? null;
        $hasPerson = is_string($rawPersonId) && $rawPersonId !== '';
        $personId = $hasPerson ? $rawPersonId : (string) $op['accountId'];
        /** @var list<string>|null $targets */
        $targets = $hasPerson ? [$rawPersonId] : null;

        $this->realtime->publish(
            organizationId: $orgId,
            channel: 'user:' . $personId,
            eventType: 'workspace.operation.updated',
            payload: $op,
            actorAccountId: (string) $op['accountId'],
            actorPersonId: $hasPerson ? $rawPersonId : null,
            targetPersonIds: $targets,
            aggregateType: 'workspace_operation',
            aggregateId: (string) $op['id'],
        );
    }

    private function loadIdentityForAccount(string $accountId, string $personId): AuthenticatedIdentity
    {
        /** @var list<string> $roles */
        $roles = $this->db->createCommand(
            'SELECT r.code FROM core.account_roles ar
             JOIN core.roles r ON r.id = ar.role_id
             WHERE ar.account_id = CAST(:account AS uuid)',
            [':account' => $accountId],
        )->queryColumn();

        /** @var list<string> $perms */
        $perms = $this->db->createCommand(
            'SELECT DISTINCT p.code FROM core.account_roles ar
             JOIN core.role_permissions rp ON rp.role_id = ar.role_id
             JOIN core.permissions p ON p.id = rp.permission_id
             WHERE ar.account_id = CAST(:account AS uuid)',
            [':account' => $accountId],
        )->queryColumn();

        return new AuthenticatedIdentity(
            accountId: $accountId,
            personId: $personId !== '' ? $personId : null,
            sessionId: '00000000-0000-0000-0000-000000000000',
            roles: $roles,
            permissions: $perms,
            locale: 'ru-RU',
            timezone: 'UTC',
        );
    }

    private function assertActionSupported(string $actionKey): void
    {
        $supported = [
            'workspace.preference.set',
            'notifications.mark_read',
            'notifications.archive',
            'trash.move',
            'trash.purge',
            'trash.empty',
            'chat.pin',
            'media.upload',
            'workspace.export.prepare',
            'auth.logout',
            'security.session.revoke_all',
            'finance.transfer',
        ];
        if (!in_array($actionKey, $supported, true)) {
            throw new HttpError(422, 'unsupported_action_key');
        }
    }

    private static function isHighRiskAction(string $actionKey): bool
    {
        foreach (['auth.', 'security.', 'finance.', 'billing.', 'payment.', 'trash.purge', 'trash.empty'] as $prefix) {
            if ($actionKey === $prefix || str_starts_with($actionKey, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrateOperation(array $row): array
    {
        $payload = json_decode((string) ($row['payloadJson'] ?? '{}'), true);
        $result = json_decode((string) ($row['resultJson'] ?? '{}'), true);
        $row['payload'] = is_array($payload) ? $payload : [];
        $row['result'] = is_array($result) ? $result : [];
        $row['revision'] = (int) ($row['revision'] ?? 1);
        unset($row['payloadJson'], $row['resultJson']);
        return $row;
    }

    private const string SELECT_COLUMNS = 'id::text, organization_id::text AS "organizationId", account_id::text AS "accountId",
        person_id::text AS "personId", action_key AS "actionKey", entity_type AS "entityType",
        entity_id::text AS "entityId", payload::text AS "payloadJson", mode, status,
        undo_deadline::text AS "undoDeadline", execute_at::text AS "executeAt",
        revision, result::text AS "resultJson", error_code AS "errorCode", error_message AS "errorMessage",
        created_at::text AS "createdAt", updated_at::text AS "updatedAt", finalized_at::text AS "finalizedAt"';

    private const string SELECT_COLUMNS_ALIASED = 'o.id::text, o.organization_id::text AS "organizationId", o.account_id::text AS "accountId",
        o.person_id::text AS "personId", o.action_key AS "actionKey", o.entity_type AS "entityType",
        o.entity_id::text AS "entityId", o.payload::text AS "payloadJson", o.mode, o.status,
        o.undo_deadline::text AS "undoDeadline", o.execute_at::text AS "executeAt",
        o.revision, o.result::text AS "resultJson", o.error_code AS "errorCode", o.error_message AS "errorMessage",
        o.created_at::text AS "createdAt", o.updated_at::text AS "updatedAt", o.finalized_at::text AS "finalizedAt"';
}
