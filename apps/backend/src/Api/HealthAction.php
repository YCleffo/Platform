<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use App\Api\Shared\ApiMessage;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Http\Status;

final class HealthAction
{
    public function __invoke(
        ResponseFactory $responseFactory,
        ConnectionInterface $db,
    ): ResponseInterface {
        try {
            $database = $db
                ->createCommand('SELECT current_database()')
                ->queryScalar();

            $schemaReady = $db->createCommand(
                <<<'SQL'
                SELECT to_regclass('core.accounts') IS NOT NULL
                   AND to_regclass('core.privacy_consents') IS NOT NULL
                   AND to_regclass('core.legal_representations') IS NOT NULL
                   AND to_regclass('core.legal_representation_scopes') IS NOT NULL
                   AND to_regclass('platform_runtime.installations') IS NOT NULL
                   AND to_regclass('platform_runtime.entitlement_documents') IS NOT NULL
                SQL,
            )->queryScalar();

            if (
                !is_string($database)
                || $database === ''
                || !in_array($schemaReady, [true, 't', '1', 1], true)
            ) {
                return $responseFactory->error(
                    messageId: ApiMessage::HEALTH_NOT_READY,
                    httpCode: Status::SERVICE_UNAVAILABLE,
                );
            }

            return $responseFactory->success([
                'status' => 'ok',
            ]);
        } catch (Throwable) {
            return $responseFactory->error(
                messageId: ApiMessage::HEALTH_DATABASE_UNAVAILABLE,
                httpCode: Status::SERVICE_UNAVAILABLE,
            );
        }
    }
}
