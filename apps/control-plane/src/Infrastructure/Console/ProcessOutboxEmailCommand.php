<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Infrastructure\Outbox\ControlEmailOutboxDispatcher;
use ControlPlane\Infrastructure\Scheduler\BackgroundJobStatusRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:outbox:process-email', description: 'Deliver leased Control email events')]
final class ProcessOutboxEmailCommand extends Command
{
    public function __construct(private readonly ControlEmailOutboxDispatcher $processor,
        private readonly BackgroundJobStatusRecorder $status)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum events per invocation', '100');
        $this->addOption('loop', null, InputOption::VALUE_NONE, 'Run continuously (for supervised workers)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1 || $limit > 1000) {
            $output->writeln('<error>--limit must be between 1 and 1000.</error>');
            return Command::INVALID;
        }
        do {
            $started = microtime(true);
            $processed = 0;
            try {
                $this->status->started('control_outbox_worker');
                while ($processed < $limit && $this->processor->processOne()) {
                    ++$processed;
                }
                $this->status->succeeded('control_outbox_worker',
                    max(0, (int) ((microtime(true) - $started) * 1000)), $processed);
            } catch (\Throwable) {
                try { $this->status->failed('control_outbox_worker', 'outbox_batch_failed'); }
                catch (\Throwable) { /* Keep bounded retry behavior. */ }
                $output->writeln('Control outbox worker batch failed.');
                if (!$input->getOption('loop')) { return Command::FAILURE; }
                sleep(5);
                continue;
            }
            $output->writeln("Processed $processed email event(s).");
            if (!$input->getOption('loop')) {
                break;
            }
            if ($processed === 0) {
                sleep(5);
            }
        } while (true);

        return Command::SUCCESS;
    }
}
