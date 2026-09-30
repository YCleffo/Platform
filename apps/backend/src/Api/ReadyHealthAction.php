<?php

declare(strict_types=1);

namespace App\Api;

use App\PlatformRuntime\Scheduler\OperationalStatus;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;

final readonly class ReadyHealthAction
{
    public function __invoke(DataResponseFactoryInterface $responses, OperationalStatus $status): ResponseInterface
    {
        $ready = $status->ready();
        return $responses->createResponse(['status' => $ready ? 'ok' : 'not_ready'])
            ->withStatus($ready ? 200 : 503);
    }
}
