<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Infrastructure\Outbox\VerificationEmailProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:outbox:process-email', description: 'Deliver leased Control email events')]
final class ProcessOutboxEmailCommand extends Command
{
    public function __construct(private readonly VerificationEmailProcessor $processor)
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
            $processed = 0;
            while ($processed < $limit && $this->processor->processOne()) {
                ++$processed;
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
