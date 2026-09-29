<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Privacy\ControlIncidentService;
use ControlPlane\Privacy\ControlSubjectRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/control/privacy/operator', name: 'control_privacy_operator_')]
final class PrivacyOperatorController extends AbstractController
{
    public function __construct(private readonly ControlSubjectRequestService $requests, private readonly ControlIncidentService $incidents) {}

    #[Route('/requests/intake', name: 'intake', methods: ['POST'])]
    public function intake(Request $request): JsonResponse
    {
        return new JsonResponse(['id' => $this->requests->intake($this->actor(), ApiRequest::json($request))], Response::HTTP_CREATED);
    }

    #[Route('/requests/{id}/resolve', name: 'resolve', methods: ['POST'])]
    public function resolve(string $id, Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);
        $response = new JsonResponse($this->requests->resolve($this->actor(), $id,
            ApiRequest::nullableString($data, 'contactName'), ApiRequest::nullableString($data, 'contactEmail'),
            ApiRequest::nullableString($data, 'responseEvidenceReference')));
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    #[Route('/incidents', name: 'detect_incident', methods: ['POST'])]
    public function detect(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);
        if (!is_array($data['categories'] ?? null) || !array_is_list($data['categories'])) {
            throw new \InvalidArgumentException('Incident categories required.');
        }
        $estimated = $data['estimatedSubjects'] ?? null;
        if ($estimated !== null && !is_int($estimated)) {
            throw new \InvalidArgumentException('Invalid estimatedSubjects.');
        }
        return new JsonResponse($this->incidents->detect($this->actor(), ApiRequest::string($data, 'detectedAt'), $data['categories'], $estimated), Response::HTTP_CREATED);
    }

    #[Route('/incidents/{id}/events', name: 'record_incident', methods: ['POST'])]
    public function record(string $id, Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);
        $this->incidents->record($this->actor(), $id, ApiRequest::string($data, 'eventCode'), ApiRequest::string($data, 'evidenceReference'));
        return new JsonResponse(['status' => 'recorded']);
    }

    #[Route('/incidents/{id}/close', name: 'close_incident', methods: ['POST'])]
    public function close(string $id, Request $request): JsonResponse
    {
        ApiRequest::emptyObject($request);
        $this->incidents->close($this->actor(), $id);
        return new JsonResponse(['status' => 'closed']);
    }

    private function actor(): string
    {
        $user = $this->getUser();
        if (!$user instanceof ControlUserPrincipal) {
            throw $this->createAccessDeniedException();
        }
        return $user->id();
    }
}
