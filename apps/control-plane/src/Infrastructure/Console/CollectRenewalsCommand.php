<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Billing\Application\CollectRenewalInvoice;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'control:billing:collect-renewals', description: 'Start a bounded batch of eligible saved-method renewal collections')]
final class CollectRenewalsCommand extends Command
{
    public function __construct(private readonly CollectRenewalInvoice $collections) { parent::__construct(); }

    protected function configure(): void { $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum invoices to collect', '100'); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getOption('limit');
        if (!is_string($raw) || preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1 || (int) $raw > 1000) throw new \InvalidArgumentException('Limit must be an integer between 1 and 1000.');
        $count = $this->collections->collectDue(new \DateTimeImmutable('now'), (int) $raw, Uuid::v7()->toRfc4122());
        $output->writeln(sprintf('Collected renewal invoices: %d', $count));
        return Command::SUCCESS;
    }
}
