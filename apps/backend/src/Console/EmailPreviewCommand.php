<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Communication\Application\EmailOutboxPolicy;
use App\Core\Communication\Application\EmailSenderInterface;
use App\Core\Communication\Application\TemplateRendererInterface;
use App\Core\Communication\Domain\EmailMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'communication:preview-emails',
    description: 'Renders and sends every Core email template to the configured SMTP sink.',
)]
final class EmailPreviewCommand extends Command
{
    /** @var array<string, list<string>> */
    private const TEMPLATES = [
        'auth.account.created' => ['displayName', 'signInUrl'],
        'auth.email.verification' => ['displayName', 'actionUrl'],
        'auth.external_identity.linked' => [],
        'auth.external_identity.unlinked' => [],
        'auth.mfa.recovery_completed' => [],
        'auth.mfa.recovery_requested' => ['actionUrl'],
        'auth.passkey.registered' => [],
        'auth.passkey.revoked' => [],
        'auth.password.changed' => ['displayName'],
        'auth.password.reset' => ['displayName', 'actionUrl'],
        'auth.recovery_codes.regenerated' => [],
        'auth.security_change' => [],
        'auth.totp.disabled' => [],
        'auth.totp.enabled' => [],
    ];

    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly EmailSenderInterface $sender,
        private readonly EmailOutboxPolicy $policy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Preview recipient address.', 'preview@platform.test');
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'ru-RU, en-US or all.', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = $input->getOption('to');
        if (!is_string($to) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>--to must be a valid email address.</error>');
            return Command::INVALID;
        }
        $localeOption = $input->getOption('locale');
        $locales = match ($localeOption) {
            'ru-RU' => ['ru-RU'],
            'en-US' => ['en-US'],
            'all' => ['ru-RU', 'en-US'],
            default => null,
        };
        if ($locales === null) {
            $output->writeln('<error>--locale must be ru-RU, en-US or all.</error>');
            return Command::INVALID;
        }

        $sent = 0;
        $failed = 0;
        foreach ($locales as $locale) {
            foreach (self::TEMPLATES as $code => $required) {
                $variables = $this->variables($locale);
                foreach ($required as $name) {
                    if (!array_key_exists($name, $variables)) {
                        $output->writeln(sprintf('<error>%s/%s: missing %s</error>', $locale, $code, $name));
                        return Command::FAILURE;
                    }
                }
                $template = $this->renderer->render($code, $locale, $variables);
                $result = $this->sender->send(new EmailMessage(
                    recipientAddress: $to,
                    recipientName: 'Platform preview',
                    subject: $template->subject,
                    htmlBody: $template->htmlBody,
                    textBody: $template->textBody,
                    messageId: 'preview-' . bin2hex(random_bytes(12)) . '@' . $this->policy->messageIdDomain,
                ));
                if ($result->successful) {
                    ++$sent;
                } else {
                    ++$failed;
                    $output->writeln(sprintf('<error>%s/%s: %s</error>', $locale, $code, $result->error ?? 'delivery_failed'));
                }
            }
        }

        $output->writeln(sprintf('Sent %d preview email(s), %d failed.', $sent, $failed));
        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return array<string, string> */
    private function variables(string $locale): array
    {
        $suffix = $locale === 'ru-RU' ? 'ru' : 'en';
        return [
            'displayName' => $locale === 'ru-RU' ? 'Юрий' : 'Yuran',
            'actionUrl' => 'https://app.platform.test/action?lang=' . $suffix,
            'signInUrl' => 'https://app.platform.test/login?lang=' . $suffix,
        ];
    }
}
