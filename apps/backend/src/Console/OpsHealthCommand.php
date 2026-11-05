<?php

declare(strict_types=1);

namespace App\Console;

use App\PlatformRuntime\Scheduler\OperationalStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ops:health', description: 'Check one local worker heartbeat')]
final class OpsHealthCommand extends Command
{
    public function __construct(private readonly OperationalStatus $status)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('component', InputArgument::REQUIRED, 'backend-scheduler or backend-email-worker');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->status->componentHealthy((string) $input->getArgument('component'))
                ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable) {
            return Command::FAILURE;
        }
    }
}
