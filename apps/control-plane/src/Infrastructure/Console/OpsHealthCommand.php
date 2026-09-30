<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Infrastructure\Scheduler\OperationalStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:ops:health', description: 'Check one Control Plane worker heartbeat')]
final class OpsHealthCommand extends Command
{
    public function __construct(private readonly OperationalStatus $status) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('component', InputArgument::REQUIRED, 'control-scheduler or control-outbox-worker');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try { return $this->status->componentHealthy((string) $input->getArgument('component'))
            ? Command::SUCCESS : Command::FAILURE; }
        catch (\Throwable) { return Command::FAILURE; }
    }
}
