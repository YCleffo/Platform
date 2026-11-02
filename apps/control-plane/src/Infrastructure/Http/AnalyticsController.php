<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Analytics\Application\GetAccountAnalytics;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AnalyticsController extends AbstractController
{
    public function __construct(private readonly GetAccountAnalytics $analytics)
    {
    }

    #[Route('/api/control/accounts/{accountId}/analytics', name: 'control_account_analytics', methods: ['GET'])]
    public function get(Request $request, string $accountId): JsonResponse
    {
        return new JsonResponse($this->analytics->forUser(
            $this->user()->id(),
            $accountId,
            (int) $request->query->get('days', '30'),
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
