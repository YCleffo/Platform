<?php

declare(strict_types=1);

namespace App\Core\Audit\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\OffsetPagination;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Http\Status;

final readonly class ListAuditEventsAction
{
    public function __construct(
        private ConnectionInterface $db,
        private ResponseFactory $responses,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $identity = AuthenticatedIdentity::fromRequest($request);
        if (!$identity->hasRole('core.superadmin')) {
            return $this->responses->error(ApiMessage::AUTH_PERMISSION_DENIED, httpCode: Status::FORBIDDEN);
        }

        $query = $request->getQueryParams();
        $action = $query['action'] ?? null;
        $success = $query['success'] ?? null;
        $actor = $query['actorAccountId'] ?? null;
        if (($action !== null && (!is_string($action) || strlen($action) > 180))
            || ($success !== null && !in_array($success, ['true', 'false'], true))
            || ($actor !== null && (!is_string($actor) || preg_match('/^[a-f0-9-]{36}$/Di', $actor) !== 1))) {
            return $this->responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }

        $pagination = OffsetPagination::fromQuery($query);
        $where = [];
        $params = [];
        if (is_string($action) && $action !== '') {
            $where[] = 'action LIKE :action';
            $params[':action'] = $action . '%';
        }
        if ($success !== null) {
            $where[] = 'success = :success';
            $params[':success'] = $success === 'true';
        }
        if (is_string($actor)) {
            $where[] = 'actor_account_id = CAST(:actor AS uuid)';
            $params[':actor'] = $actor;
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->createCommand(
            'SELECT count(*) FROM core.audit_events' . $clause,
            $params,
        )->queryScalar();

        $params[':limit'] = $pagination->limit;
        $params[':offset'] = $pagination->offset;
        $rows = $this->db->createCommand(
            <<<'SQL'
            SELECT event_id, occurred_at, actor_type, actor_account_id, action,
                   resource_type, resource_id, request_id, correlation_id, ip_address,
                   success, error_code, metadata
            FROM core.audit_events
            SQL . $clause . ' ORDER BY id DESC LIMIT :limit OFFSET :offset',
            $params,
        )->queryAll();

        $items = [];
        foreach ($rows as $row) {
            $rawMetadata = $row['metadata'] ?? null;
            $metadata = is_string($rawMetadata) ? json_decode($rawMetadata, true) : ($rawMetadata ?? []);
            $items[] = [
                'id' => (string) $row['event_id'],
                'occurredAt' => (string) $row['occurred_at'],
                'actorType' => (string) $row['actor_type'],
                'actorAccountId' => $row['actor_account_id'] === null ? null : (string) $row['actor_account_id'],
                'action' => (string) $row['action'],
                'resourceType' => $row['resource_type'] === null ? null : (string) $row['resource_type'],
                'resourceId' => $row['resource_id'] === null ? null : (string) $row['resource_id'],
                'requestId' => $row['request_id'] === null ? null : (string) $row['request_id'],
                'correlationId' => $row['correlation_id'] === null ? null : (string) $row['correlation_id'],
                'ipAddress' => $row['ip_address'] === null ? null : (string) $row['ip_address'],
                'success' => (bool) $row['success'],
                'errorCode' => $row['error_code'] === null ? null : (string) $row['error_code'],
                'metadata' => is_array($metadata) && $metadata !== [] ? $metadata : new \stdClass(),
            ];
        }

        return $this->responses->success([
            'items' => $items,
            'pagination' => [
                'limit' => $pagination->limit,
                'offset' => $pagination->offset,
                'total' => $total,
            ],
        ]);
    }
}
