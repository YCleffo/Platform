<?php

declare(strict_types=1);

namespace App\Core\Communication\Infrastructure;

use App\Core\Communication\Application\EmailSenderInterface;
use App\Core\Communication\Domain\EmailDeliveryResult;
use App\Core\Communication\Domain\EmailMessage;
use App\Core\Privacy\Application\CrossBorderTransferPolicy;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class SmtpEmailSender implements EmailSenderInterface
{
    private MailerInterface $mailer;

    public function __construct(
        private SmtpConfiguration $configuration,
        private CrossBorderTransferPolicy $egressPolicy,
    ) {
        $this->mailer = new Mailer(Transport::fromDsn($configuration->dsn()));
    }

    public function send(EmailMessage $message): EmailDeliveryResult
    {
        $this->egressPolicy->assertEgressAllowed(
            'smtp',
            $message->privacyPurposeCode,
            ['contact', 'message_content'],
        );
        $email = (new Email())
            ->from(new Address(
                $this->configuration->fromAddress,
                $this->configuration->fromName,
            ))
            ->to(new Address(
                $message->recipientAddress,
                $message->recipientName ?? '',
            ))
            ->subject($message->subject)
            ->html($message->htmlBody)
            ->text($message->textBody);
        $email->getHeaders()->addIdHeader('Message-ID', $message->messageId);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface) {
            return EmailDeliveryResult::failure('smtp_transport_failure');
        }

        return EmailDeliveryResult::success($message->messageId);
    }
}
