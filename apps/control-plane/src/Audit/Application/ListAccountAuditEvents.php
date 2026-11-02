<?php

declare(strict_types=1);

namespace ControlPlane\Audit\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

final readonly class ListAccountAuditEvents
{
    public function __construct(
        private Connection $connection,
        private AccountAuthorization $authorization,
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, next_cursor: string|null} */
    public function forUser(
        string $userId,
        string $accountId,
        int $limit = 25,
        ?string $cursor = null,
        string $action = '',
        string $outcome = '',
    ): array {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId)) {
            throw new AccessDeniedException('Account resource is not available.');
        }
        $this->authorization->requirePermission($userId, $accountId, 'account.manage');

        $limit = max(1, min($limit, 100));
        $cursorId = $cursor !== null && ctype_digit($cursor) ? (int) $cursor : null;
        $action = trim($action);
        if (mb_strlen($action) > 160) {
            throw new \InvalidArgumentException('Action filter is too long.');
        }
        if (!in_array($outcome, ['', 'success', 'failure', 'denied'], true)) {
            throw new \InvalidArgumentException('Invalid audit outcome.');
        }

        $where = ['commercial_account_id = ?'];
        $params = [$accountId];
        if ($action !== '') {
            $where[] = 'action LIKE ?';
            $params[] = $action . '%';
        }
        if ($outcome !== '') {
            $where[] = 'outcome = ?';
            $params[] = $outcome;
        }
        if ($cursorId !== null) {
            $where[] = 'id < ?';
            $params[] = $cursorId;
        }
        $params[] = $limit + 1;
        $types = [];
        foreach (array_keys($params) as $index) {
            $types[] = $index === count($params) - 1 ? ParameterType::INTEGER : ParameterType::STRING;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, event_id, occurred_at, actor_type, actor_id, action, resource_type, resource_id,
                    correlation_id, outcome, metadata
             FROM commercial.audit_events
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY id DESC
             LIMIT ?',
            $params,
            $types,
        );
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $nextCursor = $hasMore ? (string) $rows[array_key_last($rows)]['id'] : null;

        $items = [];
        foreach ($rows as $row) {
            $metadata = is_string($row['metadata']) ? json_decode($row['metadata'], true) : $row['metadata'];
            $items[] = [
                'id' => (string) $row['event_id'],
                'occurred_at' => (string) $row['occurred_at'],
                'actor_type' => (string) $row['actor_type'],
                'actor_id' => $row['actor_id'] === null ? null : (string) $row['actor_id'],
                'action' => (string) $row['action'],
                'resource_type' => (string) $row['resource_type'],
                'resource_id' => (string) $row['resource_id'],
                'correlation_id' => $row['correlation_id'] === null ? null : (string) $row['correlation_id'],
                'outcome' => (string) $row['outcome'],
                'metadata' => is_array($metadata) && $metadata !== [] ? $metadata : new \stdClass(),
            ];
        }

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
