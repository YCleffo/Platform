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
        $ready = $storage->ready();
        return $responses->createResponse(['status' => $ready ? 'ok' : 'not_ready'])
            ->withStatus($ready ? 200 : 503)->withHeader('Cache-Control', 'no-store');
    }
}
