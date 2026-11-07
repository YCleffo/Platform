<?php

declare(strict_types=1);

namespace App\DeviceManagement\Console;

use App\DeviceManagement\Infrastructure\DeviceMigrationRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateCommand extends Command
{
    public function __construct(private readonly DeviceMigrationRunner $migrations)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('device:migrate')->setDescription('Apply DeviceManagement migrations.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->migrations->apply();
        $output->writeln('DeviceManagement migrations applied. Enable the module explicitly in Core.');
        return Command::SUCCESS;
    }
}
