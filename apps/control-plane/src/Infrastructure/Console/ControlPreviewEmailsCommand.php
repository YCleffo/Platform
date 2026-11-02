<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Infrastructure\Outbox\ControlEmailLayout;
use ControlPlane\Infrastructure\Outbox\EmailTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'control:preview-emails',
    description: 'Renders and sends every Commercial email template to the configured SMTP sink.',
)]
final class ControlPreviewEmailsCommand extends Command
{
    public function __construct(
        private readonly EmailTransport $transport,
        private readonly string $messageIdDomain,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Preview recipient address.', 'preview@platform.test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = $input->getOption('to');
        if (!is_string($to) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>--to must be a valid email address.</error>');
            return Command::INVALID;
        }

        $sent = 0;
        $failed = 0;
        foreach ($this->messages() as [$subject, $text, $body, $purpose, $categories]) {
            try {
                $this->transport->send(
                    $to,
                    $subject,
                    $text,
                    ControlEmailLayout::render($subject, $body),
                    'preview-' . Uuid::v7()->toRfc4122() . '@' . $this->messageIdDomain,
                    $purpose,
                    $categories,
                );
                ++$sent;
            } catch (\Throwable) {
                ++$failed;
                $output->writeln(sprintf('<error>%s: delivery failed</error>', $subject));
            }
        }

        $output->writeln(sprintf('Sent %d preview email(s), %d failed.', $sent, $failed));
        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: list<string>}> */
    private function messages(): array
    {
        $verifyUrl = 'https://app.platform.test/verify-email?token=preview';
        $inviteUrl = 'https://app.platform.test/invitation#token=preview';
        $resetUrl = 'https://app.platform.test/reset-password?token=preview';

        return [
            [
                'Verify your Platform email address',
                "Verify your Platform email address: $verifyUrl",
                '<p>Confirm your email address to finish setting up your Platform account.</p>'
                . '<p><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '">Verify email</a></p>'
                . '<p>If you did not create this account, you can ignore this email.</p>',
                'control_email_verification',
                ['contact_email', 'verification_link'],
            ],
            [
                'Invitation to join Acme Demo on Platform',
                "You have been invited to join Acme Demo on Platform as Administrator.\n\nAccept the invitation: $inviteUrl",
                '<p>You have been invited to join <strong>Acme Demo</strong> on Platform as <strong>Administrator</strong>.</p>'
                . '<p><a href="' . htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') . '">Accept invitation</a></p>'
                . '<p>This invitation expires in 7 days. If you were not expecting it, you can ignore this email.</p>',
                'control_account_invitation',
                ['contact_email', 'invitation_link', 'organization_name'],
            ],
            [
                'Reset your Platform password',
                "Reset your Platform password: $resetUrl",
                '<p>We received a request to reset the password for your Platform account.</p>'
                . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Choose a new password</a></p>'
                . '<p>If you did not request this, you can ignore this email.</p>',
                'control_email_verification',
                ['contact_email', 'verification_link'],
            ],
            [
                'Your Platform password changed',
                'Your Platform password was changed. If this was not you, contact support immediately.',
                '<p>Your Platform password was changed. If this was not you, contact support immediately.</p>',
                'control_email_verification',
                ['contact_email', 'verification_link'],
            ],
            [
                'Two-factor authentication enabled',
                'Two-factor authentication was enabled for your Platform account.',
                '<p>Two-factor authentication was enabled for your Platform account.</p>',
                'control_email_verification',
                ['contact_email', 'verification_link'],
            ],
            [
                'Two-factor authentication disabled',
                'Two-factor authentication was disabled for your Platform account.',
                '<p>Two-factor authentication was disabled for your Platform account.</p>',
                'control_email_verification',
                ['contact_email', 'verification_link'],
            ],
            [
                'Recovery codes regenerated',
                'Your Platform recovery codes were regenerated. Previous codes no longer work.',
                '<p>Your Platform recovery codes were regenerated. Previous codes no longer work.</p>',
                'control_email_verification',
                ['contact_email', 'verification_link'],
            ],
            [
                'Platform security notice',
                'A security setting for your Platform account changed.',
                '<p>A security setting for your Platform account changed. If this was not you, contact support immediately.</p>',
                'control_email_verification',
                ['contact_email', 'verification_link'],
            ],
        ];
    }
}
