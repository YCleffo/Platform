<?php

declare(strict_types=1);

namespace App\Core\Communication\Application;

use App\Core\Auth\Application\AuthenticationPayloadCipher;
use App\Core\Messaging\Application\OutboxEvent;
use App\Core\Messaging\Application\OutboxEventCommand;
use App\Core\Messaging\Application\OutboxRepository;
use App\Core\Internationalization\Application\SupportedLocales;

final readonly class EmailService
{
    public function __construct(
        private OutboxRepository $outbox,
        private SupportedLocales $supportedLocales,
        private AuthenticationPayloadCipher $cipher,
    ) {}

    public function queue(QueueEmailCommand $command): OutboxEvent
    {
        $variables = $command->variables;
        if (isset($variables['actionUrl']) && is_string($variables['actionUrl'])) {
            $sealed = $this->cipher->encrypt($variables['actionUrl']);
            unset($variables['actionUrl']);
            $variables['actionUrlCiphertext'] = $sealed->ciphertextHex;
            $variables['actionUrlKeyVersion'] = $sealed->keyVersion;
        }
        return $this->outbox->enqueue(new OutboxEventCommand(
            producerModule: $command->producerModule,
            eventType: EmailEventType::EMAIL_REQUESTED,
            payload: [
                'schemaVersion' => 1,
                'recipient' => [
                    'address' => $command->recipientAddress,
                    'name' => $command->recipientName,
                ],
                'locale' => $this->supportedLocales->resolve($command->locale)->tag,
                'templateCode' => $command->templateCode,
                'variables' => $variables,
            ],
            aggregateType: $command->aggregateType,
            aggregateId: $command->aggregateId,
            correlationId: $command->correlationId,
            causationId: $command->causationId,
            availableAt: $command->availableAt,
        ));
    }
}
