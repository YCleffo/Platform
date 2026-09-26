<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Fiscalization\Application\ReconcileYooKassaReceipts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'control:payments:reconcile-yookassa-receipts', description: 'Reconcile a bounded batch of pending YooKassa fiscal receipts')]
final class ReconcileYooKassaReceiptsCommand extends Command
{
    public function __construct(private readonly ReconcileYooKassaReceipts $reconciliation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum receipts to reconcile', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getOption('limit');
        if (!is_string($raw) || preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1 || (int) $raw > 1000) {
            throw new \InvalidArgumentException('Limit must be an integer between 1 and 1000.');
        }
        $result = $this->reconciliation->reconcile((int) $raw, Uuid::v7()->toRfc4122());
        $output->writeln(sprintf('Selected: %d; completed: %d; pending: %d; failed: %d', $result['selected'], $result['completed'], $result['pending'], $result['failed']));

        return $result['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
