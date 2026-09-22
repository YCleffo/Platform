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

            $coreTables = (int) $db
                ->createCommand(
                    <<<'SQL'
                    SELECT count(*)
                    FROM information_schema.tables
                    WHERE table_schema = 'core'
                        AND table_type = 'BASE TABLE'
                    SQL,
                )
                ->queryScalar();

            if (
                !is_string($database)
                || $database === ''
                || $coreTables !== 23
            ) {
                return $responseFactory->error(
                    messageId: ApiMessage::HEALTH_NOT_READY,
                    meta: [
                        'database' => $database,
                        'coreTables' => $coreTables,
                    ],
                    httpCode: Status::SERVICE_UNAVAILABLE,
                );
            }

            return $responseFactory->success([
                'status' => 'ok',
                'database' => $database,
                'coreTables' => $coreTables,
            ]);
        } catch (Throwable) {
            return $responseFactory->error(
                messageId: ApiMessage::HEALTH_DATABASE_UNAVAILABLE,
                httpCode: Status::SERVICE_UNAVAILABLE,
            );
        }
    }
}
