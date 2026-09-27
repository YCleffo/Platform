<?php

declare(strict_types=1);

namespace App\Core\AccountSettings\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\AccountSettings\Application\AccountSettingsService;
use App\Core\AccountSettings\Application\SettingsSchemaRegistry;
use App\Core\AccountSettings\Application\SettingsValidationException;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Validator\Result;

final readonly class PatchAccountSettingsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        AccountSettingsService $settings,
        ResponseFactory $responses,
    ): ResponseInterface {
        $body = (string) $request->getBody();
        if (strlen($body) > SettingsSchemaRegistry::MAX_PAYLOAD_BYTES) {
            return $responses->error(ApiMessage::HTTP_PAYLOAD_TOO_LARGE, httpCode: Status::PAYLOAD_TOO_LARGE);
        }
        $input = $request->getParsedBody();
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            return $responses->failValidation(
                (new Result())->addError(ApiMessage::VALIDATION_BODY_OBJECT_REQUIRED),
            );
        }
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > SettingsSchemaRegistry::MAX_PAYLOAD_BYTES) {
            return $responses->error(ApiMessage::HTTP_PAYLOAD_TOO_LARGE, httpCode: Status::PAYLOAD_TOO_LARGE);
        }
        /** @var array<string, mixed> $input */
        $identity = AuthenticatedIdentity::fromRequest($request);
        try {
            $updated = $settings->patch(
                $identity->accountId,
                $input,
                ClientContext::fromRequest($request),
            );
        } catch (SettingsValidationException $exception) {
            return $responses->failValidation($exception->result);
        }
        return $responses->success($updated->toArray());
    }
}
