<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\PasskeyService;
use App\Core\Client\Domain\ClientContext;
use App\Core\Shared\Application\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class PasskeyRenameAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        PasskeyService $passkeys,
        ResponseFactory $responses,
    ): ResponseInterface {
        $id = (string) ($route->getArgument('id') ?? $request->getAttribute('id'));
        if (!Uuid::isValid($id)) {
            return $responses->notFound(ApiMessage::AUTH_PASSKEY_NOT_FOUND);
        }
        $identity = AuthenticatedIdentity::fromRequest($request);
        $label = AuthInput::passkeyRename($request->getParsedBody());

        try {
            $passkey = $passkeys->rename(
                $id,
                $identity->accountId,
                $label,
                ClientContext::fromRequest($request),
            );
        } catch (NotFoundException) {
            return $responses->notFound(ApiMessage::AUTH_PASSKEY_NOT_FOUND);
        }

        return $responses->success([
            'id' => $passkey->id,
            'label' => $passkey->label,
            'createdAt' => $passkey->createdAt,
            'lastUsedAt' => $passkey->lastUsedAt,
            'transports' => $passkey->transports,
            'backupEligible' => $passkey->backupEligible,
            'backupState' => $passkey->backupState,
        ]);
    }
}
