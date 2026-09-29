<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopePolicy;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use ControlPlane\Usage\Application\DeploymentUsageForbidden;
use ControlPlane\Usage\Application\IngestDeploymentUsage;
use ControlPlane\Usage\Application\UsageIdempotencyConflict;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class M2mUsageController
{
    public function __construct(
        private DeploymentCredentialService $credentials,
        private DeploymentScopePolicy $scopes,
        private IngestDeploymentUsage $usage,
    ) {}

    #[Route('/api/m2m/usage', name: 'm2m_usage_ingest', methods: ['POST'])]
    public function ingest(Request $request): JsonResponse
    {
        $identity = $this->credentials->authenticate((string) $request->headers->get('Authorization', ''),
            $request->getClientIp() ?? 'unknown');
        if ($identity === null) {
            return self::error(Response::HTTP_UNAUTHORIZED, 'authentication_required', 'A valid service credential is required.');
        }
        try {
            $this->scopes->requireScope($identity, 'usage.write');
        } catch (\DomainException) {
            return self::error(Response::HTTP_FORBIDDEN, 'scope_denied', 'The credential cannot write usage.');
        }
        if (strlen($request->getContent()) > 2048) {
            return self::error(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'request_too_large', 'The request body is too large.');
        }
        if (!str_starts_with((string) $request->headers->get('Content-Type', ''), 'application/json')) {
            return self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'validation_failed', 'A JSON body is required.');
        }
        try {
            $key = IdentifierPolicy::opaqueKey((string) $request->headers->get('Idempotency-Key', ''));
            $body = ApiRequest::json($request);
            if (array_keys($body) !== ['featureCode', 'delta'] && array_keys($body) !== ['delta', 'featureCode']) {
                throw new \InvalidArgumentException('Only featureCode and delta are accepted.');
            }
            $featureCode = IdentifierPolicy::machineCode(ApiRequest::string($body, 'featureCode'), 160);
            $delta = ApiRequest::integer($body, 'delta');
            if ($delta === 0) {
                throw new \InvalidArgumentException('Usage delta must not be zero.');
            }
        } catch (\InvalidArgumentException|\JsonException) {
            return self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'validation_failed', 'The request data is invalid.');
        }
        try {
            $result = $this->usage->apply($identity, $featureCode, $delta, $key);
        } catch (DeploymentUsageForbidden) {
            return self::error(Response::HTTP_FORBIDDEN, 'deployment_not_allowed', 'The deployment cannot write usage.');
        } catch (UsageIdempotencyConflict) {
            return self::error(Response::HTTP_CONFLICT, 'idempotency_conflict', 'The idempotency key was reused for a different request.');
        } catch (\DomainException) {
            return self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'usage_rejected', 'The usage change was rejected.');
        }
        return new JsonResponse([
            'featureCode' => $result['feature_code'], 'mode' => $result['mode'],
            'used' => $result['used'], 'limit' => $result['limit'],
            'remaining' => $result['remaining'], 'exhausted' => $result['exhausted'],
        ]);
    }

    private static function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
