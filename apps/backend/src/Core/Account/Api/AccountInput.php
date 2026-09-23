<?php

declare(strict_types=1);

namespace App\Core\Account\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\Uuid;
use App\Core\Account\Application\CreateAccountCommand;
use App\Core\Shared\Domain\ValueNormalizer;
use InvalidArgumentException;
use Yiisoft\Input\Http\InputValidationException;
use Yiisoft\Validator\Result;

final class AccountInput
{
    public static function create(mixed $body): CreateAccountCommand
    {
        if (!is_array($body)) {
            throw new InputValidationException(
                (new Result())->addError(ApiMessage::VALIDATION_BODY_OBJECT_REQUIRED),
            );
        }

        /** @var array<string, mixed> $body */
        $result = new Result();
        foreach (array_keys($body) as $field) {
            if (!in_array($field, ['accountType', 'personId', 'label'], true)) {
                $result->addError(ApiMessage::VALIDATION_UNKNOWN_FIELD, valuePath: [$field]);
            }
        }

        $accountType = $body['accountType'] ?? 'human';
        if (!is_string($accountType) || !in_array($accountType, ['human', 'service', 'system'], true)) {
            $result->addError(
                ApiMessage::VALIDATION_ACCOUNT_TYPE,
                valuePath: ['accountType'],
            );
        }

        $personId = $body['personId'] ?? null;
        if ($personId !== null && (!is_string($personId) || !Uuid::isValid($personId))) {
            $result->addError(ApiMessage::VALIDATION_UUID, valuePath: ['personId']);
        }
        if ($accountType === 'human' && $personId === null) {
            $result->addError(ApiMessage::VALIDATION_HUMAN_PERSON_REQUIRED, valuePath: ['personId']);
        }

        $label = $body['label'] ?? null;
        if ($label !== null) {
            if (!is_string($label)) {
                $result->addError(ApiMessage::VALIDATION_LABEL, ['max' => 160], ['label']);
            } else {
                try {
                    $label = ValueNormalizer::normalizeLabel($label, 160);
                } catch (InvalidArgumentException) {
                    $result->addError(ApiMessage::VALIDATION_LABEL, ['max' => 160], ['label']);
                }
            }
        }

        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }

        return new CreateAccountCommand(
            is_string($accountType) ? $accountType : 'human',
            is_string($personId) ? $personId : null,
            is_string($label) ? $label : null,
        );
    }
}
