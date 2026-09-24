<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Usage\Application\CurrentUsage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class UsageController extends AbstractController
{
    public function __construct(private readonly CurrentUsage $usage)
    {
    }

    #[Route('/api/control/accounts/{accountId}/usage', name: 'control_account_usage', methods: ['GET'])]
    public function get(string $accountId): JsonResponse
    {
        return new JsonResponse($this->usage->forAccount($this->user()->id(), $accountId));
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
