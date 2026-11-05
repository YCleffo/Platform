<?php

declare(strict_types=1);

namespace App\Education\Console;

use App\Education\Infrastructure\MigrationRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateCommand extends Command
{
    public function __construct(private readonly MigrationRunner $migrations) { parent::__construct('education:migrate'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->migrations->apply();
        $output->writeln('Education migrations applied. Enable the module explicitly in Core and assign teachers.');
        return Command::SUCCESS;
    }
}
