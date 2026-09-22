<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Communication\Application\EmailOutboxProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'communication:process-email',
    description: 'Processes one batch of email events from the transactional Outbox.',
)]
final class ProcessEmailOutboxCommand extends Command
{
    public function __construct(private readonly EmailOutboxProcessor $processor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Batch size (1-100).', '50')
            ->addOption('worker', null, InputOption::VALUE_REQUIRED, 'Stable worker name.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            $output->writeln('<error>--limit must be an integer between 1 and 100.</error>');
            return Command::INVALID;
        }

        $configuredWorker = $input->getOption('worker');
        $processId = getmypid();
        $worker = is_string($configuredWorker) && trim($configuredWorker) !== ''
            ? $configuredWorker
            : sprintf('%s:%d', gethostname() ?: 'platform', $processId === false ? 0 : $processId);
        $report = $this->processor->process($worker, $limit);

        $output->writeln(sprintf(
            'claimed=%d sent=%d retrying=%d dead=%d state_conflicts=%d',
            $report->claimed,
            $report->sent,
            $report->retrying,
            $report->dead,
            $report->stateConflicts,
        ));

        return $report->stateConflicts === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
