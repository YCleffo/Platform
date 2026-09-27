<?php

declare(strict_types=1);

namespace App\Core\AccountSettings\Application;

use DomainException;
use Yiisoft\Validator\Result;

final class SettingsValidationException extends DomainException
{
    public function __construct(public readonly Result $result)
    {
        parent::__construct('Account settings validation failed.');
    }
}
