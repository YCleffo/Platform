<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\ChangePassword;
use App\Core\Auth\Application\PasswordChangeRejectedException;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class CompleteRequiredPasswordChangeAction
{
    public function __invoke(ServerRequestInterface $request, ChangePassword $passwords, ResponseFactory $responses): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || count($body) !== 3) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        $challengeToken = $body['challengeToken'] ?? null;
        $currentPassword = $body['currentPassword'] ?? null;
        $newPassword = $body['newPassword'] ?? null;
        if (!is_string($challengeToken) || !is_string($currentPassword) || !is_string($newPassword)) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        try {
            $result = $passwords->required(
                $challengeToken,
                $currentPassword,
                $newPassword,
                ClientContext::fromRequest($request),
            );
        } catch (PasswordChangeRejectedException) {
            return $responses->error(ApiMessage::AUTH_INVALID_CREDENTIALS, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        return $responses->success($result->toArray());
    }
}
