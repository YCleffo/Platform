<?php

declare(strict_types=1);

namespace App\Api;

use Psr\Http\Message\ResponseInterface;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;

final readonly class LiveHealthAction
{
    public function __invoke(DataResponseFactoryInterface $responses): ResponseInterface
    {
        return $responses->createResponse(['status' => 'ok']);
    }
}
