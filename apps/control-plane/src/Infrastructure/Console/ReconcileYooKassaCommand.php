<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Payment\Application\ReconcileYooKassaPayments;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'control:payments:reconcile-yookassa', description: 'Reconcile a bounded batch of pending YooKassa payment attempts')]
final class ReconcileYooKassaCommand extends Command
{
    public function __construct(private readonly ReconcileYooKassaPayments $reconciliation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum attempts to reconcile', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawLimit = $input->getOption('limit');
        if (!is_string($rawLimit) || preg_match('/\A[1-9][0-9]*\z/D', $rawLimit) !== 1 || (int) $rawLimit > 1000) {
            throw new \InvalidArgumentException('Limit must be an integer between 1 and 1000.');
        }
        $result = $this->reconciliation->reconcile((int) $rawLimit, Uuid::v7()->toRfc4122());
        $output->writeln(sprintf(
            'Selected: %d; completed: %d; pending: %d; failed: %d',
            $result['selected'],
            $result['completed'],
            $result['pending'],
            $result['failed'],
        ));

        return $result['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
