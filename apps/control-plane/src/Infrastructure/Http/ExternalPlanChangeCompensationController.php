<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Application\AuthSecurityService;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Subscription\Application\ExternalPlanChangeCompensation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/control/finance/plan-change-compensations', name: 'control_external_compensation_')]
final class ExternalPlanChangeCompensationController extends AbstractController
{
    public function __construct(private readonly ExternalPlanChangeCompensation $compensations,
        private readonly AuthSecurityService $security) {}

    #[Route('/{changeId}', name: 'current', methods: ['GET'])]
    public function current(string $changeId): JsonResponse
    {
        return $this->privateResponse($this->compensations->current($this->actor()->id(), $changeId));
    }

    #[Route('/{changeId}', name: 'propose', methods: ['POST'])]
    public function propose(string $changeId, Request $request): JsonResponse
    {
        $actor = $this->actor();
        $this->security->requireSensitiveMfa($actor);
        $data = ApiRequest::json($request);
        return $this->privateResponse($this->compensations->propose($actor->id(), $changeId,
            ApiRequest::string($data, 'externalTransactionReference'),
            ApiRequest::string($data, 'documentReference'),
            ApiRequest::string($data, 'documentSha256'), ApiRequest::correlationId($request)),
            Response::HTTP_CREATED);
    }

    #[Route('/attestations/{attestationId}/approve', name: 'approve', methods: ['POST'])]
    public function approve(string $attestationId, Request $request): JsonResponse
    {
        return $this->review($attestationId, $request, true);
    }

    #[Route('/attestations/{attestationId}/reject', name: 'reject', methods: ['POST'])]
    public function reject(string $attestationId, Request $request): JsonResponse
    {
        return $this->review($attestationId, $request, false);
    }

    private function review(string $attestationId, Request $request, bool $approve): JsonResponse
    {
        $actor = $this->actor();
        $this->security->requireSensitiveMfa($actor);
        $data = ApiRequest::json($request);
        return $this->privateResponse($this->compensations->review($actor->id(), $attestationId,
            ApiRequest::string($data, 'externalTransactionReference'),
            ApiRequest::string($data, 'documentReference'),
            ApiRequest::string($data, 'documentSha256'), $approve,
            $approve ? null : ApiRequest::string($data, 'rejectionReason'), ApiRequest::correlationId($request)));
    }

    /** @param array<string, mixed> $data */
    private function privateResponse(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    private function actor(): ControlUserPrincipal
    {
        $actor = $this->getUser();
        if (!$actor instanceof ControlUserPrincipal) throw $this->createAccessDeniedException();
        return $actor;
    }
}
