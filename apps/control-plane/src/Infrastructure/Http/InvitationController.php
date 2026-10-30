<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Account\Invitation\Application\InvitationService;
use ControlPlane\Identity\Application\AuthSecurityService;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/control/accounts', name: 'control_invitations_')]
final class InvitationController extends AbstractController
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly AuthSecurityService $security,
    ) {
    }

    #[Route('/{accountId}/invitations', name: 'create', methods: ['POST'])]
    public function create(string $accountId, Request $request): JsonResponse
    {
        $user = $this->user();
        $this->security->requireSensitiveMfa($user);
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new \InvalidArgumentException('Idempotency-Key is required.');
        }
        $data = ApiRequest::json($request);
        $result = $this->invitations->create(
            $user->id(),
            $accountId,
            ApiRequest::string($data, 'email'),
            ApiRequest::string($data, 'role'),
            $key,
            ApiRequest::correlationId($request),
        );

        return new JsonResponse($result, Response::HTTP_CREATED);
    }

    #[Route('/{accountId}/invitations', name: 'list', methods: ['GET'])]
    public function list(string $accountId, Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', '50');
        $offset = (int) $request->query->get('offset', '0');

        return new JsonResponse($this->invitations->list($this->user()->id(), $accountId, $limit, $offset));
    }

    #[Route('/{accountId}/invitations/{invitationId}', name: 'revoke', methods: ['DELETE'])]
    public function revoke(string $accountId, string $invitationId, Request $request): Response
    {
        $user = $this->user();
        $this->security->requireSensitiveMfa($user);
        $this->invitations->revoke($user->id(), $accountId, $invitationId, ApiRequest::correlationId($request));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{accountId}/invitations/{invitationId}/resend', name: 'resend', methods: ['POST'])]
    public function resend(string $accountId, string $invitationId, Request $request): JsonResponse
    {
        $user = $this->user();
        $this->security->requireSensitiveMfa($user);

        return new JsonResponse($this->invitations->resend($user->id(), $accountId, $invitationId, ApiRequest::correlationId($request)));
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
