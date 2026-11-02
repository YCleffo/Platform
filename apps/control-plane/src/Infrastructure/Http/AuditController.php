<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Audit\Application\ListAccountAuditEvents;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AuditController extends AbstractController
{
    public function __construct(private readonly ListAccountAuditEvents $events)
    {
    }

    #[Route('/api/control/accounts/{accountId}/audit-events', name: 'control_account_audit_events', methods: ['GET'])]
    public function list(Request $request, string $accountId): JsonResponse
    {
        $limit = (int) $request->query->get('limit', '25');
        $cursor = $request->query->get('cursor');
        $action = $request->query->get('action', '');
        $outcome = $request->query->get('outcome', '');

        return new JsonResponse($this->events->forUser(
            $this->user()->id(),
            $accountId,
            $limit,
            is_string($cursor) ? $cursor : null,
            is_string($action) ? $action : '',
            is_string($outcome) ? $outcome : '',
        ));
    }

    private function user(): ControlUserPrincipal
    {
        $user = $this->getUser();
        if (!$user instanceof ControlUserPrincipal) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
