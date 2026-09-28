<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Outbox;

interface EmailTransport
{
    public function send(string $recipient, string $subject, string $text, string $html, string $messageId): void;
}
