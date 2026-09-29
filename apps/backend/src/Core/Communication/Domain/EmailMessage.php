<?php

declare(strict_types=1);

namespace App\Core\Communication\Domain;

use InvalidArgumentException;

final readonly class EmailMessage
{
    public function __construct(
        public string $recipientAddress,
        public ?string $recipientName,
        public string $subject,
        public string $htmlBody,
        public string $textBody,
        public string $messageId,
        public string $privacyPurposeCode = 'communication_email',
    ) {
        if (filter_var($recipientAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Recipient address is invalid.');
        }
        if (trim($subject) === '') {
            throw new InvalidArgumentException('Email subject must not be blank.');
        }
        if (trim($htmlBody) === '' || trim($textBody) === '') {
            throw new InvalidArgumentException('Both HTML and text email bodies are required.');
        }
        if (
            !str_contains($messageId, '@')
            || str_contains($messageId, '<')
            || str_contains($messageId, '>')
            || preg_match('/[\r\n]/', $messageId) === 1
        ) {
            throw new InvalidArgumentException('Email Message-ID is invalid.');
        }
    }
}
