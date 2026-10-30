<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Application\CurrentUser;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class MeController extends AbstractController
{
    public function __construct(private readonly CurrentUser $currentUser)
    {
    }

    #[Route('/api/control/me', name: 'control_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return new JsonResponse($this->currentUser->profile($this->user()->id()));
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
