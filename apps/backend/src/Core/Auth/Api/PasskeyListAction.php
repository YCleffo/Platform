<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\PasskeyService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class PasskeyListAction
{
    public function __invoke(
        ServerRequestInterface $request,
        PasskeyService $passkeys,
        ResponseFactory $responses,
    ): ResponseInterface {
        $identity = AuthenticatedIdentity::fromRequest($request);
        $list = $passkeys->listPasskeys($identity->accountId);

        return $responses->success(['items' => $list]);
    }
}
