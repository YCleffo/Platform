<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\ExternalAuthService;
use Psr\Http\Message\ResponseInterface;

final readonly class ProvidersAction
{
    public function __invoke(ExternalAuthService $external, ResponseFactory $responses): ResponseInterface
    {
        return $responses->success(['items' => $external->publicProviders()]);
    }
}
