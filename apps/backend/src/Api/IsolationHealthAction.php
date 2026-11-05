<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Http\Status;

/** A disposable Core runtime must prove its nonce before browser tests may mutate it. */
final readonly class IsolationHealthAction
{
    public function __invoke(ResponseFactory $responses, ConnectionInterface $db): ResponseInterface
    {
        $nonce = getenv('E2E_ISOLATION_PROOF');
        if (!is_string($nonce) || strlen($nonce) < 32) {
            return $responses->error(ApiMessage::HTTP_NOT_FOUND, httpCode: Status::NOT_FOUND);
        }
        try {
            TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
        } catch (Throwable) {
            return $responses->error(ApiMessage::HTTP_NOT_FOUND, httpCode: Status::NOT_FOUND);
        }
        return $responses->success(['nonce' => hash('sha256', $nonce)]);
    }
}
