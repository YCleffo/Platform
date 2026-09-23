<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\LastAuthenticationMethodException;
use App\Core\Auth\Application\PasskeyService;
use App\Core\Auth\Application\RecentAuthenticationRequiredException;
use App\Core\Client\Domain\ClientContext;
use App\Core\Shared\Application\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class PasskeyRevokeAction
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

        try {
            $passkeys->revoke(
                $id,
                $identity->accountId,
                $identity->sessionId,
                ClientContext::fromRequest($request),
            );
        } catch (RecentAuthenticationRequiredException) {
            return $responses->error(ApiMessage::AUTH_RECENT_REQUIRED, httpCode: Status::FORBIDDEN);
        } catch (LastAuthenticationMethodException) {
            return $responses->error(ApiMessage::AUTH_LAST_METHOD, httpCode: Status::CONFLICT);
        } catch (NotFoundException) {
            return $responses->notFound(ApiMessage::AUTH_PASSKEY_NOT_FOUND);
        }

        return $responses->success(['status' => 'revoked']);
    }
}
