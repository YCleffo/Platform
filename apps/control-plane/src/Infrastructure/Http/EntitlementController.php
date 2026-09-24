<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class EntitlementController extends AbstractController
{
    public function __construct(private readonly EffectiveEntitlements $entitlements)
    {
    }

    #[Route('/api/control/accounts/{accountId}/entitlements', name: 'control_account_entitlements', methods: ['GET'])]
    public function get(string $accountId): JsonResponse
    {
        return new JsonResponse($this->entitlements->forAccount($this->user()->id(), $accountId));
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
