<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    #[Route('/health', name: 'control_health', methods: ['GET'])]
    public function __invoke(Connection $connection): JsonResponse
    {
        try {
            $ready = (int) $connection->fetchOne(
                "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'commercial' AND table_type = 'BASE TABLE'",
            ) >= 21;
        } catch (\Throwable) {
            $ready = false;
        }

        return new JsonResponse(['status' => $ready ? 'ok' : 'not_ready'], $ready ? 200 : 503);
    }
}
