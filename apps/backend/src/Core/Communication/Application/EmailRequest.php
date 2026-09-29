<?php

declare(strict_types=1);

namespace App\Core\Communication\Application;

use App\Core\Auth\Application\AuthenticationPayloadCipher;
use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Messaging\Application\OutboxEvent;

final readonly class EmailRequest
{
    /** @param array<string, scalar|null> $variables */
    private function __construct(
        public string $recipientAddress,
        public ?string $recipientName,
        public string $locale,
        public string $templateCode,
        public array $variables,
    ) {}

    public static function fromEvent(OutboxEvent $event, SupportedLocales $supportedLocales,
        ?AuthenticationPayloadCipher $cipher = null): self
    {
        $payload = $event->payload;
        if (($payload['schemaVersion'] ?? null) !== 1) {
            throw new EmailEventPayloadException('Unsupported email payload schemaVersion.');
        }

        $recipient = $payload['recipient'] ?? null;
        if (!is_array($recipient)) {
            throw new EmailEventPayloadException('Email recipient is required.');
        }

        $address = $recipient['address'] ?? null;
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new EmailEventPayloadException('Email recipient address is invalid.');
        }

        $name = $recipient['name'] ?? null;
        if ($name !== null && !is_string($name)) {
            throw new EmailEventPayloadException('Email recipient name must be a string or null.');
        }

        $locale = $payload['locale'] ?? null;
        if (!is_string($locale) || trim($locale) === '') {
            throw new EmailEventPayloadException('Email locale must be a language tag.');
        }
        $resolvedLocale = $supportedLocales->resolve($locale);

        $requestedCode = $payload['templateCode'] ?? null;
        if ($requestedCode !== null && !is_string($requestedCode)) {
            throw new EmailEventPayloadException('Email templateCode must be a string.');
        }
        $templateCode = EmailEventType::templateCode($event->eventType, $requestedCode);
        if ($templateCode === null || trim($templateCode) === '') {
            throw new EmailEventPayloadException('Email event type or templateCode is unsupported.');
        }

        $rawVariables = $payload['variables'] ?? null;
        if (!is_array($rawVariables)) {
            throw new EmailEventPayloadException('Email variables must be an object.');
        }

        $variables = [];
        foreach ($rawVariables as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                throw new EmailEventPayloadException('Email variables must contain scalar values.');
            }
            $variables[$key] = $value;
        }
        if (isset($variables['actionUrlCiphertext'], $variables['actionUrlKeyVersion'])) {
            if ($cipher === null || !is_string($variables['actionUrlCiphertext'])
                || !is_int($variables['actionUrlKeyVersion'])) {
                throw new EmailEventPayloadException('Encrypted action URL is invalid.');
            }
            try {
                $variables['actionUrl'] = $cipher->decrypt(
                    $variables['actionUrlCiphertext'], $variables['actionUrlKeyVersion']);
            } catch (\RuntimeException $exception) {
                throw new EmailEventPayloadException('Encrypted action URL cannot be read.', previous: $exception);
            }
            unset($variables['actionUrlCiphertext'], $variables['actionUrlKeyVersion']);
        }

        return new self(
            recipientAddress: $address,
            recipientName: $name === '' ? null : $name,
            locale: $resolvedLocale->tag,
            templateCode: $templateCode,
            variables: $variables,
        );
    }
}
