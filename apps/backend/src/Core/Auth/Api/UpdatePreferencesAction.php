<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Account\Application\UpdateAccountPreferences;
use App\Core\Auth\Application\AuthenticatedIdentity;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class UpdatePreferencesAction
{
    public function __invoke(
        ServerRequestInterface $request,
        UpdateAccountPreferences $update,
        ResponseFactory $responses,
    ): ResponseInterface {
        $identity = AuthenticatedIdentity::fromRequest($request);
        $input = AuthInput::preferences($request->getParsedBody());
        $locale = array_key_exists('locale', $input) ? $input['locale'] : $identity->locale;
        $timezone = array_key_exists('timezone', $input) ? $input['timezone'] : $identity->timezone;
        $locale = is_string($locale) ? $locale : null;
        $timezone = is_string($timezone) ? $timezone : null;

        try {
            $preferences = $update->update(
                $identity->accountId,
                $locale,
                $timezone,
            );
        } catch (InvalidArgumentException) {
            return $responses->error(ApiMessage::ACCOUNT_PREFERENCES_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }

        return $responses->success([
            'locale' => $preferences?->locale,
            'timezone' => $preferences?->timezone,
        ]);
    }
}
