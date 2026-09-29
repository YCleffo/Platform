<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Entitlement\Application\DeploymentEntitlementIssuer;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopePolicy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class M2mEntitlementController
{
    public function __construct(private DeploymentCredentialService $credentials,
        private DeploymentScopePolicy $scopes, private DeploymentEntitlementIssuer $issuer) {}

    #[Route('/api/m2m/entitlements/latest', name: 'm2m_entitlements_latest', methods: ['GET'])]
    public function latest(Request $request): JsonResponse
    {
        $identity = $this->credentials->authenticate((string) $request->headers->get('Authorization', ''),
            $request->getClientIp() ?? 'unknown');
        if ($identity === null) {
            return new JsonResponse(['error' => ['code' => 'authentication_required']], Response::HTTP_UNAUTHORIZED);
        }
        try {
            $this->scopes->requireScope($identity, 'entitlements.read');
        } catch (\DomainException) {
            return new JsonResponse(['error' => ['code' => 'scope_denied']], Response::HTTP_FORBIDDEN);
        }
        try {
            $issued = $this->issuer->ensureLatest($identity->deploymentId);
        } catch (\DomainException) {
            return new JsonResponse(['error' => ['code' => 'entitlements_unavailable']], Response::HTTP_CONFLICT);
        }
        $response = new JsonResponse(['documentBase64' => $issued['documentBase64'],
            'signatureBase64' => $issued['signatureBase64'], 'keyId' => $issued['keyId']]);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}
