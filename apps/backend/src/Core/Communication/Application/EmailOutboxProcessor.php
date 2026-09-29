<?php

declare(strict_types=1);

namespace App\Core\Communication\Application;

use App\Core\Auth\Application\AuthenticationPayloadCipher;
use App\Core\Communication\Domain\EmailDeliveryResult;
use App\Core\Communication\Domain\EmailMessage;
use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Messaging\Application\OutboxEvent;
use App\Core\Messaging\Application\OutboxRepository;
use InvalidArgumentException;
use Throwable;

final readonly class EmailOutboxProcessor
{
    public function __construct(
        private OutboxRepository $outbox,
        private TemplateRendererInterface $renderer,
        private EmailSenderInterface $sender,
        private EmailOutboxPolicy $policy,
        private SupportedLocales $supportedLocales,
        private ?AuthenticationPayloadCipher $cipher = null,
    ) {}

    public function process(string $worker, int $limit = 50): EmailProcessingReport
    {
        if (trim($worker) === '') {
            throw new InvalidArgumentException('Email worker name must not be blank.');
        }

        $events = $this->outbox->claimPendingByTypes(
            $worker,
            EmailEventType::supported(),
            max(1, min(100, $limit)),
            $this->policy->leaseSeconds,
        );
        $sent = 0;
        $retrying = 0;
        $dead = 0;
        $stateConflicts = 0;

        foreach ($events as $event) {
            try {
                $message = $this->message($event);
            } catch (TemplateRenderingException | InvalidArgumentException $exception) {
                $updated = $this->outbox->markFailed(
                    id: $event->id,
                    error: 'email_payload_invalid',
                    dead: true,
                    worker: $worker,
                );
                $updated ? ++$dead : ++$stateConflicts;
                continue;
            }

            try {
                $result = $this->sender->send($message);
            } catch (Throwable $exception) {
                $result = EmailDeliveryResult::failure('email_delivery_failed');
            }

            if ($result->successful) {
                $updated = $this->outbox->markPublished($event->id, $worker);
                $updated ? ++$sent : ++$stateConflicts;
                continue;
            }

            $isDead = $event->attempts >= $this->policy->maxAttempts;
            $updated = $this->outbox->markFailed(
                id: $event->id,
                error: 'email_delivery_failed',
                dead: $isDead,
                availableAt: $isDead ? null : $this->policy->retryAt($event->attempts),
                worker: $worker,
            );
            if (!$updated) {
                ++$stateConflicts;
            } elseif ($isDead) {
                ++$dead;
            } else {
                ++$retrying;
            }
        }

        return new EmailProcessingReport(
            claimed: count($events),
            sent: $sent,
            retrying: $retrying,
            dead: $dead,
            stateConflicts: $stateConflicts,
        );
    }

    private function message(OutboxEvent $event): EmailMessage
    {
        $request = EmailRequest::fromEvent($event, $this->supportedLocales, $this->cipher);
        $template = $this->renderer->render(
            $request->templateCode,
            $request->locale,
            $request->variables,
        );

        return new EmailMessage(
            recipientAddress: $request->recipientAddress,
            recipientName: $request->recipientName,
            subject: $template->subject,
            htmlBody: $template->htmlBody,
            textBody: $template->textBody,
            messageId: $event->eventId . '@' . $this->policy->messageIdDomain,
            privacyPurposeCode: $event->eventType === EmailEventType::EMAIL_REQUESTED
                ? 'communication_email' : 'security_email',
        );
    }

}
