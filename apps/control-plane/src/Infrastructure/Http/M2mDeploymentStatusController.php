<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Deployment\Application\ReportDeploymentStatus;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopePolicy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class M2mDeploymentStatusController
{
    public function __construct(private DeploymentCredentialService $credentials,
        private DeploymentScopePolicy $scopes, private ReportDeploymentStatus $reporter) {}

    #[Route('/api/m2m/deployment/status', name: 'm2m_deployment_status', methods: ['POST'])]
    public function report(Request $request): JsonResponse
    {
        $identity = $this->credentials->authenticate((string) $request->headers->get('Authorization', ''),
            $request->getClientIp() ?? 'unknown');
        if ($identity === null) { return new JsonResponse(['error' => ['code' => 'authentication_required']], 401); }
        try { $this->scopes->requireScope($identity, 'deployment.status.write'); }
        catch (\DomainException) { return new JsonResponse(['error' => ['code' => 'scope_denied']], 403); }
        if (strlen($request->getContent()) > 4096 || !str_starts_with($request->headers->get('Content-Type', ''), 'application/json')) {
            return new JsonResponse(['error' => ['code' => 'invalid_report']], 422);
        }
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($body)) { throw new \DomainException('Invalid report.'); }
            if (($body['status'] ?? null) === 'ready' && count($body) === 2
                && isset($body['entitlementSequence']) && is_int($body['entitlementSequence'])) {
                $this->reporter->ready($identity->deploymentId, $body['entitlementSequence']);
            } elseif (($body['status'] ?? null) === 'failed' && count($body) === 2
                && isset($body['errorCode']) && is_string($body['errorCode'])) {
                $this->reporter->failed($identity->deploymentId, $body['errorCode']);
            } else {
                throw new \DomainException('Invalid report.');
            }
            return new JsonResponse(['status' => $body['status']]);
        } catch (\DomainException|\JsonException) {
            return new JsonResponse(['error' => ['code' => 'invalid_report']], 422);
        }
    }
}
