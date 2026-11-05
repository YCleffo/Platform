<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\OffsetPagination;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Router\CurrentRoute;

/** Minimal authorized directories for workflow selectors, not a general SQL/PII browser. */
final readonly class PrivacyWorkItemsAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route,
        ConnectionInterface $db, ResponseFactory $responses): ResponseInterface
    {
        $kind = (string) $route->getArgument('kind');
        $definition = match ($kind) {
            'representation' => ['core.legal_representations', 'authority_type', 'status'],
            'erasure' => ['core.privacy_erasure_cases', 'reason_code', 'status'],
            'incident' => ['core.privacy_incidents', 'incident_type', 'status'],
            'distribution' => ['core.privacy_distribution_consents', 'purpose_code', "CASE WHEN withdrawn_at IS NULL THEN 'active' ELSE 'withdrawn' END"],
            default => null,
        };
        if ($definition === null) return $responses->notFound();
        [$table, $name, $status] = $definition;
        $pagination = OffsetPagination::fromQuery($request->getQueryParams());
        $params = [];
        $where = '';
        if ($kind === 'distribution') {
            // Distribution grant/withdraw is subject/representative scoped, never a global admin permission.
            $actor = AuthenticatedIdentity::fromRequest($request);
            if ($actor->personId === null) return $responses->success(['items' => [], 'total' => 0]);
            $where = ' WHERE subject_person_id = CAST(:person AS uuid) OR granted_by_account_id = CAST(:actor AS uuid)';
            $params[':person'] = $actor->personId;
            $params[':actor'] = $actor->accountId;
        }
        $total = (int) $db->createCommand("SELECT count(*) FROM $table$where", $params)->queryScalar();
        $rows = $db->createCommand("SELECT id::text, $name AS name, $status AS status FROM $table$where ORDER BY id DESC LIMIT :limit OFFSET :offset",
            [...$params, ':limit' => $pagination->limit, ':offset' => $pagination->offset])->queryAll();
        return $responses->success(['items' => $rows, 'total' => $total]);
    }
}
