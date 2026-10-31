<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Infrastructure\Scheduler\OperationalStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    public function __construct(private string $environment, private string $isolationNonce)
    {
    }

    #[Route('/health/live', name: 'control_health_live', methods: ['GET'])]
    public function live(): JsonResponse { return new JsonResponse(['status' => 'ok']); }

    #[Route('/health/isolation', name: 'control_health_isolation', methods: ['GET'])]
    public function isolation(): JsonResponse
    {
        if ($this->environment !== 'test' || strlen($this->isolationNonce) < 32) {
            return new JsonResponse(['status' => 'not_found'], 404);
        }

        return new JsonResponse(['status' => 'ok', 'nonce' => hash('sha256', $this->isolationNonce)]);
    }

    #[Route('/health/ready', name: 'control_health_ready', methods: ['GET'])]
    public function ready(OperationalStatus $status): JsonResponse
    {
        $ready = $status->ready();
        return new JsonResponse(['status' => $ready ? 'ok' : 'not_ready'], $ready ? 200 : 503);
    }

    #[Route('/health', name: 'control_health', methods: ['GET'])]
    public function __invoke(OperationalStatus $status): JsonResponse
    {
        return $this->ready($status);
    }
}
