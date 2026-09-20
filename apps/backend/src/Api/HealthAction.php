<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
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

            if ($database !== 'platform' || $coreTables !== 23) {
                return $responseFactory->fail(
                    message: 'Service is not ready.',
                    data: [
                        'database' => $database,
                        'coreTables' => $coreTables,
                    ],
                    httpCode: Status::SERVICE_UNAVAILABLE,
                );
            }

            return $responseFactory->success([
                'status' => 'ok',
                'database' => 'ok',
                'coreTables' => $coreTables,
            ]);
        } catch (Throwable) {
            return $responseFactory->fail(
                message: 'Database is unavailable.',
                httpCode: Status::SERVICE_UNAVAILABLE,
            );
        }
    }
}
