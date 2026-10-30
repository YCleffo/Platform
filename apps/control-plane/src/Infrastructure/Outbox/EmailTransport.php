<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Outbox;

interface EmailTransport
{
    /** @param list<string> $categories */
    public function send(
        string $recipient,
        string $subject,
        string $text,
        string $html,
        string $messageId,
        string $purpose = 'control_email_verification',
        array $categories = ['contact_email', 'verification_link'],
    ): void;
}
