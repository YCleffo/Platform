<?php

declare(strict_types=1);

namespace App\Api;

use App\Storage\Infrastructure\StorageReadiness;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;

final readonly class StorageHealthAction
{
    public function __invoke(DataResponseFactoryInterface $responses, StorageReadiness $storage): ResponseInterface
    {
        $reason = $storage->failureReason();
        return $responses->createResponse($reason === null
            ? ['status' => 'ok']
            : ['status' => 'not_ready', 'reason' => $reason])
            ->withStatus($reason === null ? 200 : 503)->withHeader('Cache-Control', 'no-store');
    }
}
