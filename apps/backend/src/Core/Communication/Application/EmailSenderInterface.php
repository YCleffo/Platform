<?php

declare(strict_types=1);

namespace App\Core\Communication\Application;

use App\Core\Communication\Domain\EmailDeliveryResult;
use App\Core\Communication\Domain\EmailMessage;

interface EmailSenderInterface
{
    public function send(EmailMessage $message): EmailDeliveryResult;
}
