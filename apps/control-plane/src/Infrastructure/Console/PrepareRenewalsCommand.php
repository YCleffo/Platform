<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Billing\Application\IssueRenewalInvoice;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'control:billing:prepare-renewals', description: 'Issue a bounded batch of upcoming subscription renewal invoices')]
final class PrepareRenewalsCommand extends Command
{
    public function __construct(private readonly IssueRenewalInvoice $issuer, private readonly int $leadDays = 7)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum subscriptions to prepare', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getOption('limit');
        if (!is_string($raw) || preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1 || (int) $raw > 1000) {
            throw new \InvalidArgumentException('Limit must be an integer between 1 and 1000.');
        }
        $total = 0;
        do {
            $count = $this->issuer->prepareDue(new \DateTimeImmutable('now'), $this->leadDays, (int) $raw, Uuid::v7()->toRfc4122());
            $total += $count;
        } while ($count === (int) $raw);
        $output->writeln(sprintf('Prepared renewal invoices: %d', $total));

        return Command::SUCCESS;
    }
}
