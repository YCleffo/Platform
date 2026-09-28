<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\ChangePassword;
use App\Core\Auth\Application\PasswordChangeRejectedException;
use App\Core\Auth\Application\RecentAuthenticationRequiredException;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class ChangeOwnPasswordAction
{
    public function __invoke(ServerRequestInterface $request, ChangePassword $passwords, ResponseFactory $responses): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || count($body) !== 2) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        $currentPassword = $body['currentPassword'] ?? null;
        $newPassword = $body['newPassword'] ?? null;
        if (!is_string($currentPassword) || !is_string($newPassword)) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        $identity = AuthenticatedIdentity::fromRequest($request);
        try {
            $passwords->authenticated(
                $identity->accountId,
                $identity->sessionId,
                $currentPassword,
                $newPassword,
                ClientContext::fromRequest($request),
            );
        } catch (RecentAuthenticationRequiredException) {
            return $responses->error(ApiMessage::AUTH_RECENT_REQUIRED, httpCode: Status::FORBIDDEN);
        } catch (PasswordChangeRejectedException) {
            return $responses->error(ApiMessage::AUTH_INVALID_CREDENTIALS, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        return $responses->success(['status' => 'password_changed']);
    }
}
