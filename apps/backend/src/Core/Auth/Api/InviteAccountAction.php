<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Auth\Application\InviteAccount;
use App\Core\Auth\Application\LoginRateLimitedException;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\RateLimiterUnavailableException;
use App\Core\Shared\Application\ConflictException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class InviteAccountAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        InviteAccount $invite,
        ResponseFactory $responses,
    ): ResponseInterface {
        $accountId = (string) $route->getArgument('id');
        if (!Uuid::isValid($accountId)) {
            return $responses->error(ApiMessage::ACCOUNT_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }
        try {
            $queued = $invite->execute(
                $accountId,
                AuthInput::email($request->getParsedBody()),
                ClientContext::fromRequest($request),
            );
        } catch (LoginRateLimitedException) {
            return $responses->error(ApiMessage::AUTH_ACTIVATION_RATE_LIMITED, httpCode: Status::TOO_MANY_REQUESTS);
        } catch (RateLimiterUnavailableException) {
            return $responses->error(ApiMessage::AUTH_ACTIVATION_UNAVAILABLE, httpCode: Status::SERVICE_UNAVAILABLE);
        } catch (InvalidArgumentException) {
            return $responses->error(ApiMessage::ACCOUNT_INVITE_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        } catch (ConflictException) {
            return $responses->error(ApiMessage::ACCOUNT_CONFLICT, httpCode: Status::CONFLICT);
        }

        return $queued
            ? $responses->success(['queued' => true])->withStatus(Status::ACCEPTED)
            : $responses->notFound(ApiMessage::ACCOUNT_NOT_FOUND);
    }
}
