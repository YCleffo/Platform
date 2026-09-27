<?php

declare(strict_types=1);

namespace App\Core\AccountSettings\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\AccountSettings\Application\AccountSettingsService;
use App\Core\Auth\Application\AuthenticatedIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class GetAccountSettingsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        AccountSettingsService $settings,
        ResponseFactory $responses,
    ): ResponseInterface {
        $identity = AuthenticatedIdentity::fromRequest($request);
        return $responses->success($settings->get($identity->accountId)->toArray());
    }
}
