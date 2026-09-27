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
            $ready = (bool) $connection->fetchOne(<<<'SQL'
SELECT to_regclass('commercial.control_users') IS NOT NULL
   AND to_regclass('commercial.control_user_email_verifications') IS NOT NULL
   AND to_regclass('commercial.control_sessions') IS NOT NULL
   AND to_regclass('commercial.control_session_refresh_history') IS NOT NULL
   AND to_regclass('commercial.rate_limit_counters') IS NOT NULL
   AND to_regclass('commercial.refunds') IS NOT NULL
   AND to_regclass('commercial.refund_items') IS NOT NULL
   AND to_regclass('commercial.receipts') IS NOT NULL
SQL);
        } catch (\Throwable) {
            $ready = false;
        }

        return new JsonResponse(['status' => $ready ? 'ok' : 'not_ready'], $ready ? 200 : 503);
    }
}
