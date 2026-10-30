<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Account\Invitation\Application\InvitationService;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/control/invitations', name: 'control_invitation_recipient_')]
final class InvitationRecipientController extends AbstractController
{
    public function __construct(private readonly InvitationService $invitations)
    {
    }

    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);

        return new JsonResponse($this->invitations->preview(ApiRequest::string($data, 'token')));
    }

    #[Route('/accept', name: 'accept', methods: ['POST'])]
    public function accept(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);

        return new JsonResponse($this->invitations->accept($this->user()->id(), ApiRequest::string($data, 'token'), ApiRequest::correlationId($request)));
    }

    #[Route('/decline', name: 'decline', methods: ['POST'])]
    public function decline(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);

        return new JsonResponse($this->invitations->decline($this->user()->id(), ApiRequest::string($data, 'token'), ApiRequest::correlationId($request)));
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
